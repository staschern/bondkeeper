<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use BondKeeper\Iss\OffersImporter;
use BondKeeper\Securities\SecurityStatus;
use PDO;

/**
 * Применяет сообщение НРД об офертах (OfferNotice) к таблице offers и
 * признаку securities.has_offer (11.10.2026, docs/OFFERS.md).
 *
 * Объявление о досрочном погашении по решению эмитента → строка call с
 * датой, source = nsd, status = announced. Если такая строка уже есть (её
 * дала биржа), она помечается объявленной и получает дату объявления.
 * Погашение в конце срока, оформленное тем же кодом MCAL, офертой не
 * считается.
 *
 * Отмена → оферта помечается cancelled и перестаёт учитываться в признаке.
 * Строка остаётся до своей даты: у биржи отменённая оферта может висеть
 * ещё долго, и ночная загрузка (OffersImporter) вернула бы её обратно.
 * Какие строки гасит отмена:
 *   - строку с тем же номером корпоративного действия НРД;
 *   - строки биржи того же вида в пределах OffersImporter::CANCEL_WINDOW_DAYS
 *     дней от даты отменённого действия (у строк биржи номера НРД нет, а
 *     дата у биржи и у НРД может отличаться на несколько дней: у
 *     АльфаБО-21 — 09.10 и 13.10.2026).
 *
 * Опрос перечитывает ленту с перекрытием, поэтому одно сообщение приходит
 * сюда много раз: повторная обработка ничего не меняет. В частности,
 * старая отмена не гасит оферту, объявленную позже другим корпоративным
 * действием.
 */
final class OfferNoticeProcessor
{
    public const RESULT_FOREIGN = 'foreign';     // бумаги нет в базе или она не в обращении
    public const RESULT_IGNORED = 'ignored';     // не оферта (погашение по сроку) или дата уже прошла
    public const RESULT_APPLIED = 'applied';
    public const RESULT_NO_CHANGE = 'no_change';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param bool $apply false — только сказать, что изменилось бы
     * @return array{result: string, note: string, security_name: ?string}
     */
    public function process(OfferNotice $notice, string $today, bool $apply = true): array
    {
        $stmt = $this->db->prepare('SELECT id, issuer_id, short_name, maturity_date, status FROM securities WHERE isin = :isin');
        $stmt->execute(['isin' => $notice->isin]);
        $security = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($security === false || !SecurityStatus::inCirculation((string) $security['status'])) {
            return ['result' => self::RESULT_FOREIGN, 'note' => 'бумаги нет в базе или она не в обращении', 'security_name' => null];
        }
        $name = (string) $security['short_name'];
        if ($notice->date < $today) {
            return ['result' => self::RESULT_IGNORED, 'note' => 'дата уже прошла', 'security_name' => $name];
        }

        $securityId = (int) $security['id'];
        $rows = $this->db->prepare('SELECT id, execution_date_planned, status, source, source_ref, announcement_date, price_percent FROM offers
            WHERE security_id = :id AND offer_type = :type ORDER BY id');
        $rows->execute(['id' => $securityId, 'type' => $notice->type]);
        $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

        if ($notice->action === OfferNotice::ACTION_ANNOUNCED) {
            if (!SecurityStatus::isEarly($notice->date, $security['maturity_date'] !== null ? (string) $security['maturity_date'] : null)) {
                return ['result' => self::RESULT_IGNORED, 'note' => 'погашение по сроку, не оферта', 'security_name' => $name];
            }

            return $this->announce($notice, $security, $rows, $apply) + ['security_name' => $name];
        }

        return $this->cancel($notice, $security, $rows, $today, $apply) + ['security_name' => $name];
    }

    /**
     * @param array<string, mixed> $security
     * @param array<int, array<string, mixed>> $rows оферты бумаги того же вида
     * @return array{result: string, note: string}
     */
    private function announce(OfferNotice $notice, array $security, array $rows, bool $apply): array
    {
        $same = null;
        foreach ($rows as $row) {
            if ($row['status'] === 'cancelled' && (string) $row['source_ref'] === $notice->actionRef) {
                return ['result' => self::RESULT_NO_CHANGE, 'note' => 'это действие позже отменено'];
            }
            if ((string) $row['execution_date_planned'] === $notice->date) {
                $same = $row;
            }
        }

        $securityId = (int) $security['id'];
        if ($same === null) {
            if ($apply) {
                $this->db->prepare(
                    "INSERT INTO offers (security_id, issuer_id, offer_type, source, source_ref, announcement_date, execution_date_planned, price_percent, status)
                     VALUES (:security_id, :issuer_id, :type, 'nsd', :ref, :announced, :date, :price, 'announced')"
                )->execute([
                    'security_id' => $securityId,
                    'issuer_id' => (int) $security['issuer_id'],
                    'type' => $notice->type,
                    'ref' => $notice->actionRef,
                    'announced' => $notice->messageDate,
                    'date' => $notice->date,
                    'price' => $notice->percent,
                ]);
                $this->setFlag($securityId, true);
            }

            return ['result' => self::RESULT_APPLIED, 'note' => 'добавлено досрочное погашение, объявленное НРД'];
        }

        $changes = [];
        if ($same['status'] !== 'announced') {
            $changes['status'] = 'announced';
        }
        if ((string) ($same['source_ref'] ?? '') !== $notice->actionRef) {
            $changes['source_ref'] = $notice->actionRef;
        }
        if ($same['announcement_date'] === null) {
            $changes['announcement_date'] = $notice->messageDate;
        }
        if ($same['price_percent'] === null && $notice->percent !== null) {
            $changes['price_percent'] = $notice->percent;
        }
        if ($changes === []) {
            return ['result' => self::RESULT_NO_CHANGE, 'note' => 'уже учтено'];
        }
        if ($apply) {
            $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($changes)));
            $this->db->prepare("UPDATE offers SET {$set} WHERE id = :id")->execute($changes + ['id' => (int) $same['id']]);
            $this->setFlag($securityId, true);
        }

        return ['result' => self::RESULT_APPLIED, 'note' => 'оферта из таблицы биржи подтверждена объявлением НРД'];
    }

    /**
     * @param array<string, mixed> $security
     * @param array<int, array<string, mixed>> $rows оферты бумаги того же вида
     * @return array{result: string, note: string}
     */
    private function cancel(OfferNotice $notice, array $security, array $rows, string $today, bool $apply): array
    {
        $exact = null;
        $toDelete = [];
        foreach ($rows as $row) {
            $date = (string) $row['execution_date_planned'];
            $ref = (string) ($row['source_ref'] ?? '');
            if ($row['status'] === 'cancelled') {
                if ($ref === $notice->actionRef) {
                    return ['result' => self::RESULT_NO_CHANGE, 'note' => 'отмена уже учтена'];
                }
                if ($date === $notice->date) {
                    $exact = $row;
                }
                continue;
            }
            $matches = $ref !== ''
                ? $ref === $notice->actionRef
                : abs(strtotime($date) - strtotime($notice->date)) <= OffersImporter::CANCEL_WINDOW_DAYS * 86400;
            if (!$matches) {
                continue;
            }
            if ($date === $notice->date) {
                $exact = $row;
            } else {
                $toDelete[] = (int) $row['id'];
            }
        }

        $securityId = (int) $security['id'];
        if ($apply) {
            $this->db->beginTransaction();
            try {
                foreach ($toDelete as $id) {
                    $this->db->prepare('DELETE FROM offers WHERE id = :id')->execute(['id' => $id]);
                }
                if ($exact !== null) {
                    $this->db->prepare("UPDATE offers SET status = 'cancelled', source_ref = :ref WHERE id = :id")
                        ->execute(['ref' => $notice->actionRef, 'id' => (int) $exact['id']]);
                } else {
                    // Отметка об отмене: по ней ночная загрузка с биржи не вернёт эту оферту.
                    $this->db->prepare(
                        "INSERT INTO offers (security_id, issuer_id, offer_type, source, source_ref, execution_date_planned, period_start, period_end, price_percent, status)
                         VALUES (:security_id, :issuer_id, :type, 'nsd', :ref, :date, :period_start, :period_end, :price, 'cancelled')"
                    )->execute([
                        'security_id' => $securityId,
                        'issuer_id' => (int) $security['issuer_id'],
                        'type' => $notice->type,
                        'ref' => $notice->actionRef,
                        'date' => $notice->date,
                        'period_start' => $notice->periodStart,
                        'period_end' => $notice->periodEnd,
                        'price' => $notice->percent,
                    ]);
                }
                $left = $this->db->prepare("SELECT COUNT(*) FROM offers WHERE security_id = :id AND status <> 'cancelled' AND execution_date_planned >= :today");
                $left->execute(['id' => $securityId, 'today' => $today]);
                $this->setFlag($securityId, (int) $left->fetchColumn() > 0);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }

        $found = count($toDelete) + ($exact !== null && $exact['status'] !== 'cancelled' ? 1 : 0);

        return ['result' => self::RESULT_APPLIED, 'note' => $found > 0 ? "оферта отменена НРД (строк: {$found})" : 'отмена НРД записана: такой оферты у нас не было'];
    }

    private function setFlag(int $securityId, bool $hasOffer): void
    {
        $this->db->prepare('UPDATE securities SET has_offer = :value WHERE id = :id')->execute(['value' => $hasOffer ? 1 : 0, 'id' => $securityId]);
    }
}
