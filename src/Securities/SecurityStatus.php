<?php

declare(strict_types=1);

namespace BondKeeper\Securities;

use PDO;

/**
 * Статус бумаги (securities.status) и всё, что из него следует
 * (11.10.2026, docs/SECURITY_STATUS.md).
 *
 * До этого статус ставился по умолчанию и не менялся. Теперь его меняют
 * три источника — и все через этот класс, чтобы правило было одно:
 *   - ежедневная сверка со списком биржи (Iss\SecurityStatusSync):
 *     погашена по сроку, ушла с биржи до срока, переведена в режим «Д»;
 *   - сообщения НРД о выплатах (Payments\PaymentProcessor): не выплачено
 *     в срок, объявлен дефолт, долг закрыт, погашение получено;
 *   - разовый разбор от 11.10.2026 (bin/apply_2026_10_security_status_review.php).
 *
 * Значения статуса (решения пользователя, 11.10.2026):
 *   active       — в обращении, нарушений нет;
 *   tech_default — выплата не исполнена в срок ЛИБО биржа перевела бумагу
 *                  в режим «Облигации Д»;
 *   default      — НРД объявил дефолт;
 *   matured      — погашена: по сроку или досрочно, если досрочное
 *                  погашение подтверждено (НРД или запись биржи);
 *   delisted     — ушла с биржи до срока, причина не установлена.
 * Бумаги не удаляются — меняется только статус.
 */
final class SecurityStatus
{
    public const ACTIVE = 'active';
    public const TECH_DEFAULT = 'tech_default';
    public const DEFAULTED = 'default';
    public const MATURED = 'matured';
    public const DELISTED = 'delisted';

    /** Бумага в обращении: по ней обновляются график и оферты и обрабатываются сообщения о выплатах. */
    public const IN_CIRCULATION = [self::ACTIVE, self::TECH_DEFAULT, self::DEFAULTED];

    /** Коды причин — securities.status_reason. */
    public const REASON_SCHEDULED = 'scheduled_maturity';       // погашена по сроку
    public const REASON_EARLY_NSD = 'early_redemption_nsd';     // погашена досрочно, подтверждено сообщением НРД
    public const REASON_EARLY_MOEX = 'early_redemption_moex';   // погашена досрочно, запись биржи «Оферта/Погашение»
    public const REASON_LEFT_UNKNOWN = 'left_exchange_unknown'; // ушла с биржи до срока, причина не установлена
    public const REASON_BOARD_D = 'moex_board_d';               // биржа перевела в режим «Облигации Д»
    public const REASON_PAYMENT_MISSED = 'payment_missed';      // выплата не исполнена в срок (наши события B1, B2)
    public const REASON_NSD_DEFAULT = 'nsd_default';            // НРД объявил дефолт (C2)
    public const REASON_DEBT_CLOSED = 'debt_closed';            // долг по выплатам закрыт (B5) — снова active
    public const REASON_LEFT_BOARD_D = 'left_board_d';          // биржа вернула из режима «Д» — снова active
    public const REASON_BACK_ON_EXCHANGE = 'back_on_exchange';  // снова в списке торгуемых — снова active

    /** Досрочным считается погашение раньше срока по графику больше чем на столько дней. */
    public const EARLY_REDEMPTION_DAYS = 10;

    /** Статусы строк графика, означающие непогашенный долг. */
    private const UNPAID_ROW_STATUSES = "'partial', 'tech_default', 'default'";

    public function __construct(private readonly PDO $db)
    {
    }

    public static function inCirculation(string $status): bool
    {
        return in_array($status, self::IN_CIRCULATION, true);
    }

    /**
     * Дата раньше срока погашения больше чем на EARLY_REDEMPTION_DAYS дней — то есть это досрочное
     * погашение, а не само погашение по сроку. Одно правило для сообщений НРД о деньгах, объявлений НРД
     * и строк биржи «Оферта/Погашение». У бумаги без срока (бессрочной) любая дата погашения — досрочная.
     */
    public static function isEarly(string $date, ?string $maturityDate): bool
    {
        if ($maturityDate === null || $maturityDate === '') {
            return true;
        }

        return strtotime($date) < strtotime($maturityDate . ' -' . self::EARLY_REDEMPTION_DAYS . ' days');
    }

    /** Список статусов «в обращении» для вставки в SQL: 'active', 'tech_default', 'default'. */
    public static function inCirculationSql(): string
    {
        return "'" . implode("', '", self::IN_CIRCULATION) . "'";
    }

    /**
     * Поставить статус. Если статус и причина уже такие — ничего не
     * меняется. У бумаги, вышедшей из обращения, заодно снимается признак
     * оферты и удаляются строки оферт.
     *
     * @param ?string $endedAt дата окончания выпуска — только для matured и delisted
     * @return bool изменилось ли что-то
     */
    public function set(int $securityId, string $status, string $reason, ?string $endedAt = null): bool
    {
        $stmt = $this->db->prepare('SELECT status, status_reason FROM securities WHERE id = :id');
        $stmt->execute(['id' => $securityId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($current === false || ((string) $current['status'] === $status && (string) ($current['status_reason'] ?? '') === $reason)) {
            return false;
        }

        $inCirculation = self::inCirculation($status);
        $this->db->prepare(
            'UPDATE securities SET status = :status, status_reason = :reason, status_changed_at = :now, ended_at = :ended_at WHERE id = :id'
        )->execute([
            'status' => $status,
            'reason' => $reason,
            'now' => date('Y-m-d H:i:s'),
            'ended_at' => $inCirculation ? null : $endedAt,
            'id' => $securityId,
        ]);

        if (!$inCirculation) {
            $this->clearOffers($securityId);
        }

        return true;
    }

    /**
     * Снять признак оферты и удалить строки оферт: у бумаги, которой нет
     * на бирже, предстоящей оферты быть не может, даже если биржа ещё
     * держит её в своей таблице оферт (БиоВит 1Р1: выпуск погашен
     * 24.09.2026, а пут на 17.11.2026 в таблице остался).
     */
    public function clearOffers(int $securityId): void
    {
        $this->db->prepare('UPDATE securities SET has_offer = 0 WHERE id = :id AND has_offer <> 0')->execute(['id' => $securityId]);
        $this->db->prepare('DELETE FROM offers WHERE security_id = :id')->execute(['id' => $securityId]);
    }

    /**
     * Статус бумаги по событиям выплат, только что созданным из сообщения
     * НРД (коды событий — см. docs/STAGE5_PAYMENTS.md):
     *   C2 (НРД объявил дефолт)                    → default;
     *   B1, B2 (не выплачено или недоплачено в срок) → tech_default, если бумага была active;
     *   B5 (выплачено полностью после просрочки)   → обратно active, если
     *        по бумаге не осталось непогашенных выплат и статус был
     *        поставлен из-за выплат (а не из-за режима «Д» на бирже).
     *
     * @param array<int, string> $eventCodes
     * @return ?string новый статус или null, если ничего не изменилось
     */
    public function applyPaymentEvents(int $securityId, array $eventCodes): ?string
    {
        $stmt = $this->db->prepare('SELECT status, status_reason FROM securities WHERE id = :id');
        $stmt->execute(['id' => $securityId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($current === false || !self::inCirculation((string) $current['status'])) {
            return null;
        }
        $status = (string) $current['status'];
        $reason = (string) ($current['status_reason'] ?? '');

        if (in_array('C2', $eventCodes, true)) {
            return $this->set($securityId, self::DEFAULTED, self::REASON_NSD_DEFAULT) ? self::DEFAULTED : null;
        }
        if (array_intersect(['B1', 'B2'], $eventCodes) !== []) {
            return $status === self::ACTIVE && $this->set($securityId, self::TECH_DEFAULT, self::REASON_PAYMENT_MISSED) ? self::TECH_DEFAULT : null;
        }
        if (in_array('B5', $eventCodes, true)
            && in_array($reason, [self::REASON_PAYMENT_MISSED, self::REASON_NSD_DEFAULT], true)
            && !$this->hasUnpaidRows($securityId)
        ) {
            return $this->set($securityId, self::ACTIVE, self::REASON_DEBT_CLOSED) ? self::ACTIVE : null;
        }

        return null;
    }

    private function hasUnpaidRows(int $securityId): bool
    {
        foreach (['coupons', 'amortizations', 'redemptions'] as $table) {
            $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE security_id = :id AND status IN (" . self::UNPAID_ROW_STATUSES . ') LIMIT 1');
            $stmt->execute(['id' => $securityId]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
        }

        return false;
    }
}
