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
 * Объявление (оферта — с 18.10.2026, досрочное погашение по решению
 * эмитента — с 11.10.2026) сравнивается с тем, что у нас уже есть, и у
 * объявления приоритет (решение пользователя, 18.10.2026):
 *   - оферта с той же датой уже есть (её дала биржа) — подтверждается:
 *     получает номер действия НРД, день объявления, период предъявления и
 *     цену, если их не было; строки биржи того же вида рядом по дате (у
 *     биржи на одну оферту бывает две строки) удаляются;
 *   - есть строка этого же действия НРД с другой датой — НРД перенёс дату:
 *     строка получает новую;
 *   - у биржи оферта того же вида рядом по дате (не дальше
 *     OffersImporter::CANCEL_WINDOW_DAYS дней), но дата другая — верной
 *     считается дата НРД: строки биржи удаляются, появляется строка
 *     source = nsd; ночная загрузка их обратно не вернёт;
 *   - ничего похожего нет — появляется новая строка source = nsd.
 * Погашение в конце срока, оформленное кодом MCAL, офертой не считается.
 * Для путов сверка со сроком погашения не делается: у части выпусков биржа
 * ставит сроком дату колла, и пут после неё настоящий (МТС 2P-13).
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
    public const RESULT_APPLIED = 'applied';     // новая оферта, другая дата или отмена — об этом стоит сказать
    public const RESULT_CONFIRMED = 'confirmed'; // пут с той же датой уже был: объявление его только подтвердило
    public const RESULT_NO_CHANGE = 'no_change';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param bool $apply false — только сказать, что изменилось бы
     * @return array{result: string, note: string, security_name: ?string, previous_date: ?string}
     *         previous_date — дата, которая была у нас, если объявление её изменило
     */
    public function process(OfferNotice $notice, string $today, bool $apply = true): array
    {
        $stmt = $this->db->prepare('SELECT id, issuer_id, short_name, maturity_date, status FROM securities WHERE isin = :isin');
        $stmt->execute(['isin' => $notice->isin]);
        $security = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($security === false || !SecurityStatus::inCirculation((string) $security['status'])) {
            return ['result' => self::RESULT_FOREIGN, 'note' => 'бумаги нет в базе или она не в обращении', 'security_name' => null, 'previous_date' => null];
        }
        $name = (string) $security['short_name'];
        if ($notice->date < $today) {
            return ['result' => self::RESULT_IGNORED, 'note' => 'дата уже прошла', 'security_name' => $name, 'previous_date' => null];
        }

        $securityId = (int) $security['id'];
        $rows = $this->db->prepare('SELECT id, execution_date_planned, status, source, source_ref, announcement_date, price_percent, period_start, period_end FROM offers
            WHERE security_id = :id AND offer_type = :type ORDER BY id');
        $rows->execute(['id' => $securityId, 'type' => $notice->type]);
        $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

        if ($notice->action === OfferNotice::ACTION_ANNOUNCED) {
            if ($notice->type === 'call' && !SecurityStatus::isEarly($notice->date, $security['maturity_date'] !== null ? (string) $security['maturity_date'] : null)) {
                return ['result' => self::RESULT_IGNORED, 'note' => 'погашение по сроку, не оферта', 'security_name' => $name, 'previous_date' => null];
            }

            return $this->announce($notice, $security, $rows, $apply) + ['security_name' => $name];
        }

        return $this->cancel($notice, $security, $rows, $today, $apply) + ['security_name' => $name, 'previous_date' => null];
    }

    /**
     * @param array<string, mixed> $security
     * @param array<int, array<string, mixed>> $rows оферты бумаги того же вида
     * @return array{result: string, note: string, previous_date: ?string}
     */
    private function announce(OfferNotice $notice, array $security, array $rows, bool $apply): array
    {
        $same = null;   // строка с той же датой
        $revive = null; // отменённая строка с той же датой — НРД объявил оферту на эту дату заново
        $own = null;    // строка этого же действия НРД с другой датой — НРД перенёс дату
        $near = [];     // строки биржи того же вида рядом по дате — та же оферта, но дата у НРД другая
        foreach ($rows as $row) {
            $ref = (string) ($row['source_ref'] ?? '');
            $date = (string) $row['execution_date_planned'];
            if ($row['status'] === 'cancelled') {
                if ($ref === $notice->actionRef) {
                    return ['result' => self::RESULT_NO_CHANGE, 'note' => 'это действие позже отменено', 'previous_date' => null];
                }
                if ($date === $notice->date) {
                    $revive = $row;
                }
                continue;
            }
            if ($date === $notice->date) {
                $same = $row;
            } elseif ($ref === $notice->actionRef) {
                $own = $row;
            } elseif ($ref === '' && abs(strtotime($date) - strtotime($notice->date)) <= OffersImporter::CANCEL_WINDOW_DAYS * 86400) {
                $near[] = $row;
            }
        }

        $securityId = (int) $security['id'];
        $drop = array_map(static fn (array $row): int => (int) $row['id'], $near);
        $replaced = $own ?? ($near[0] ?? null);
        $previous = $replaced !== null ? (string) $replaced['execution_date_planned'] : null;

        if ($same === null && $revive !== null) {
            $same = $revive;
        }
        if ($same !== null) {
            if ($own !== null) {
                $drop[] = (int) $own['id'];
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
            foreach (['price_percent' => $notice->percent, 'period_start' => $notice->periodStart, 'period_end' => $notice->periodEnd] as $column => $value) {
                if ($same[$column] === null && $value !== null) {
                    $changes[$column] = $value;
                }
            }
            if ($changes === [] && $drop === []) {
                return ['result' => self::RESULT_NO_CHANGE, 'note' => 'уже учтено', 'previous_date' => null];
            }
            if ($apply) {
                $this->write(static function (PDO $db) use ($changes, $same, $drop): void {
                    if ($changes !== []) {
                        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($changes)));
                        $db->prepare("UPDATE offers SET {$set} WHERE id = :id")->execute($changes + ['id' => (int) $same['id']]);
                    }
                    foreach ($drop as $id) {
                        $db->prepare('DELETE FROM offers WHERE id = :id')->execute(['id' => $id]);
                    }
                }, $securityId);
            }
            $extra = $drop !== [] ? '; у биржи убрана вторая строка той же оферты' : '';
            if ($same === $revive) {
                return ['result' => self::RESULT_APPLIED, 'note' => 'НРД снова объявил оферту на эту дату после отмены', 'previous_date' => null];
            }

            // Колл, подтверждённый НРД, — новость (эмитент решил погасить выпуск); пут с той же датой — нет.
            return $notice->type === 'call'
                ? ['result' => self::RESULT_APPLIED, 'note' => 'оферта из таблицы биржи подтверждена объявлением НРД' . $extra, 'previous_date' => null]
                : ['result' => self::RESULT_CONFIRMED, 'note' => 'оферта из таблицы биржи подтверждена объявлением НРД' . $extra, 'previous_date' => null];
        }

        if ($own !== null) {
            if ($apply) {
                $this->write(function (PDO $db) use ($notice, $own, $near): void {
                    // Источник — НРД: ночная загрузка с биржи такую строку не удаляет.
                    $db->prepare("UPDATE offers SET execution_date_planned = :date, source = 'nsd', status = 'announced',
                        period_start = COALESCE(:period_start, period_start), period_end = COALESCE(:period_end, period_end), price_percent = COALESCE(:price, price_percent)
                        WHERE id = :id")->execute([
                        'date' => $notice->date, 'period_start' => $notice->periodStart, 'period_end' => $notice->periodEnd, 'price' => $notice->percent, 'id' => (int) $own['id'],
                    ]);
                    foreach ($near as $row) {
                        $db->prepare('DELETE FROM offers WHERE id = :id')->execute(['id' => (int) $row['id']]);
                    }
                }, $securityId);
            }

            return ['result' => self::RESULT_APPLIED, 'note' => 'НРД перенёс дату: было ' . self::ru($previous), 'previous_date' => $previous];
        }

        if ($apply) {
            $this->write(function (PDO $db) use ($notice, $security, $drop): void {
                foreach ($drop as $id) {
                    $db->prepare('DELETE FROM offers WHERE id = :id')->execute(['id' => $id]);
                }
                $db->prepare(
                    "INSERT INTO offers (security_id, issuer_id, offer_type, source, source_ref, announcement_date, period_start, period_end, execution_date_planned, price_percent, status)
                     VALUES (:security_id, :issuer_id, :type, 'nsd', :ref, :announced, :period_start, :period_end, :date, :price, 'announced')"
                )->execute([
                    'security_id' => (int) $security['id'],
                    'issuer_id' => (int) $security['issuer_id'],
                    'type' => $notice->type,
                    'ref' => $notice->actionRef,
                    'announced' => $notice->messageDate,
                    'period_start' => $notice->periodStart,
                    'period_end' => $notice->periodEnd,
                    'date' => $notice->date,
                    'price' => $notice->percent,
                ]);
            }, $securityId);
        }

        if ($previous !== null) {
            return ['result' => self::RESULT_APPLIED, 'note' => 'дата из объявления НРД, у нас (по бирже) было ' . self::ru($previous), 'previous_date' => $previous];
        }

        return ['result' => self::RESULT_APPLIED, 'note' => $notice->type === 'call' ? 'добавлено досрочное погашение, объявленное НРД' : 'добавлена оферта, объявленная НРД',
            'previous_date' => null];
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

    /** Изменения одной бумаги — одной транзакцией; признак оферты после них стоит. */
    private function write(callable $changes, int $securityId): void
    {
        $this->db->beginTransaction();
        try {
            $changes($this->db);
            $this->setFlag($securityId, true);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function setFlag(int $securityId, bool $hasOffer): void
    {
        $this->db->prepare('UPDATE securities SET has_offer = :value WHERE id = :id')->execute(['value' => $hasOffer ? 1 : 0, 'id' => $securityId]);
    }

    private static function ru(?string $date): string
    {
        return $date !== null ? date('d.m.Y', (int) strtotime($date)) : '—';
    }
}
