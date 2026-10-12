<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use BondKeeper\Securities\SecurityStatus;
use PDO;

/**
 * Статусы строк графика, которые ставятся не по сообщению о конкретной
 * выплате, а по положению дел (12.10.2026, docs/STAGE5_PAYMENTS.md,
 * раздел «Статусы выплат»):
 *
 *   unconfirmed — дата выплаты прошла, а сообщения НРД о ней нет. Раньше
 *       такая строка оставалась planned навсегда: всё, что было до начала
 *       наблюдения, и всё, о чём НРД промолчал. Это не «не выплачено» —
 *       это «подтверждения нет». Сообщение НРД, пришедшее позже,
 *       обрабатывается как обычно и статус меняет.
 *
 *   cancelled — выплаты не будет: выпуск погашен досрочно. Отменяется
 *       «хвост» графика после даты досрочного погашения и само плановое
 *       погашение. Деньги, всё же пришедшие по такой строке, статус
 *       исправят.
 *
 *   строка redemptions планового погашения — зеркало последней
 *       амортизации. Биржа отдаёт выплату номинала в конце срока строкой
 *       amortizations, и в redemptions у таких бумаг сумма 0 (2932 бумаги
 *       из 3215): сообщение НРД ложится на амортизацию, а строка
 *       погашения оставалась planned. Теперь она повторяет статус этой
 *       амортизации; факт по ней — 0, чтобы номинал не считался дважды.
 *
 * Вызывается из PaymentProcessor (сразу при обработке сообщения) и из
 * ночного bin/sync_payment_statuses.php (догоняет всё остальное). Все
 * действия повторяемы: второй запуск ничего не меняет.
 */
final class ScheduleStatuses
{
    /** Строки, по которым денег ещё не было: впереди, без подтверждения или отменённые. */
    public const NOT_RECEIVED = ['planned', 'unconfirmed', 'cancelled'];

    /** Причины статуса бумаги, означающие досрочное погашение. */
    private const EARLY_REASONS = [SecurityStatus::REASON_EARLY_NSD, SecurityStatus::REASON_EARLY_MOEX];

    /** На сколько дней раньше даты погашения смотреть строки: дата в графике может быть выходным, а исполнение — после него. */
    private const LOOKBACK_DAYS = 7;

    public function __construct(
        private readonly PDO $db,
        private readonly WorkingCalendar $calendar,
    ) {
    }

    /**
     * Готова ли база к новым статусам (применена ли миграция 031). null —
     * готова, иначе текст для разработчика. Проверка — по списку значений
     * колонки (MySQL); в других базах (SQLite в тестах) значения не
     * ограничены, проверять нечего.
     */
    public static function schemaProblem(PDO $db): ?string
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return null;
        }
        foreach (['coupons', 'amortizations', 'redemptions'] as $table) {
            $column = $db->query("SHOW COLUMNS FROM {$table} LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
            if ($column === false || !str_contains((string) ($column['Type'] ?? ''), "'unconfirmed'")) {
                return "В таблице {$table} нет новых значений статуса (unconfirmed, cancelled, default) — не применена миграция database/031_payment_status_ladder.sql. Статусы выплат не менялись.";
            }
        }

        return null;
    }

    /**
     * Выплаты, дата которых прошла, а сообщения НРД нет → unconfirmed.
     *
     * Строка переводится не сразу: на следующий рабочий день после дня
     * исполнения идут проверки (12:02 и 19:00), и им нужна строка planned.
     * Поэтому берутся даты по графику не позже рабочего дня, идущего
     * перед предыдущим рабочим: выплата в понедельник → проверки во
     * вторник → unconfirmed в ночь на среду.
     *
     * @return array{coupons: int, amortizations: int, redemptions: int}
     */
    public function markUnconfirmed(string $today, bool $apply = true): array
    {
        $cutoff = $this->calendar->previousWorkingDay($this->calendar->previousWorkingDay($today));
        $counts = [];
        foreach (['coupons' => 'period_end_date', 'amortizations' => 'payment_date_planned', 'redemptions' => 'payment_date_planned'] as $table => $dateColumn) {
            if ($apply) {
                $stmt = $this->db->prepare("UPDATE {$table} SET status = 'unconfirmed' WHERE status = 'planned' AND {$dateColumn} <= :cutoff");
                $stmt->execute(['cutoff' => $cutoff]);
                $counts[$table] = $stmt->rowCount();
            } else {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE status = 'planned' AND {$dateColumn} <= :cutoff");
                $stmt->execute(['cutoff' => $cutoff]);
                $counts[$table] = (int) $stmt->fetchColumn();
            }
        }

        return $counts;
    }

    /**
     * Выпуск погашен досрочно $redemptionDate — отменить то, что после:
     *   - купоны с днём исполнения ПОЗЖЕ этой даты (купон в день
     *     погашения выплачивается вместе с ним);
     *   - амортизации и плановое погашение с днём исполнения в эту дату и
     *     позже (досрочное погашение их заменяет: у ДОМРФИА 1P на
     *     28.09.2026 стояла плановая амортизация, а пришёл весь остаток
     *     номинала).
     * Трогаются только строки, по которым денег не было.
     *
     * @return array{coupons: int, amortizations: int, redemptions: int}
     */
    public function cancelAfterEarlyRedemption(int $securityId, string $redemptionDate, bool $apply = true): array
    {
        $from = date('Y-m-d', (int) strtotime($redemptionDate . ' -' . self::LOOKBACK_DAYS . ' days'));
        $counts = [];
        $tables = [
            'coupons' => ['period_end_date', '', false],
            'amortizations' => ['payment_date_planned', '', true],
            'redemptions' => ['payment_date_planned', " AND redemption_type = 'scheduled_maturity'", true],
        ];
        foreach ($tables as $table => [$dateColumn, $extra, $sameDayToo]) {
            $stmt = $this->db->prepare("SELECT id, {$dateColumn} AS payment_date FROM {$table}
                WHERE security_id = :security_id AND status IN ('planned', 'unconfirmed') AND {$dateColumn} >= :date_from{$extra}");
            $stmt->execute(['security_id' => $securityId, 'date_from' => $from]);
            $counts[$table] = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $effective = $this->calendar->effectiveDate((string) $row['payment_date']);
                if ($effective > $redemptionDate || ($sameDayToo && $effective === $redemptionDate)) {
                    $counts[$table]++;
                    if ($apply) {
                        $this->db->prepare("UPDATE {$table} SET status = 'cancelled' WHERE id = :id")->execute(['id' => (int) $row['id']]);
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * То же для всех бумаг, погашенных досрочно (по причине статуса и дате
     * окончания выпуска): догоняет бумаги, статус которых поставила сверка
     * с биржей или разовый разбор.
     *
     * @return array{bonds: int, coupons: int, amortizations: int, redemptions: int}
     */
    public function cancelTails(bool $apply = true): array
    {
        $reasons = "'" . implode("', '", self::EARLY_REASONS) . "'";
        $bonds = $this->db->query(
            "SELECT id, ended_at FROM securities WHERE status = '" . SecurityStatus::MATURED . "' AND status_reason IN ({$reasons}) AND ended_at IS NOT NULL"
        )->fetchAll(PDO::FETCH_ASSOC);

        $total = ['bonds' => 0, 'coupons' => 0, 'amortizations' => 0, 'redemptions' => 0];
        foreach ($bonds as $bond) {
            // Если досрочное погашение записано строкой (сообщение НРД), его дата точнее даты окончания выпуска.
            $stmt = $this->db->prepare("SELECT MAX(payment_date_planned) FROM redemptions WHERE security_id = :id AND redemption_type = 'early_issuer_call'");
            $stmt->execute(['id' => (int) $bond['id']]);
            $date = (string) ($stmt->fetchColumn() ?: $bond['ended_at']);

            $counts = $this->cancelAfterEarlyRedemption((int) $bond['id'], $date, $apply);
            if (array_sum($counts) > 0) {
                $total['bonds']++;
                foreach ($counts as $table => $count) {
                    $total[$table] += $count;
                }
            }
        }

        return $total;
    }

    /**
     * Строка планового погашения с нулевой суммой повторяет статус
     * амортизации той же бумаги на ту же дату (она и есть выплата
     * номинала). $securityId = null — по всем бумагам.
     *
     * @return int сколько строк погашения изменено
     */
    public function mirrorFinalAmortization(?int $securityId = null, bool $apply = true): int
    {
        $sql = "SELECT r.id, a.status, a.full_default_date_planned
                FROM redemptions r
                JOIN amortizations a ON a.security_id = r.security_id AND a.payment_date_planned = r.payment_date_planned
                WHERE r.redemption_type = 'scheduled_maturity'
                  AND CAST(r.value_per_bond AS DECIMAL(18,4)) = 0
                  AND a.status IN ('paid', 'partial', 'tech_default', 'default')
                  AND (r.status <> a.status OR COALESCE(r.full_default_date_planned, '') <> COALESCE(a.full_default_date_planned, ''))";
        $params = [];
        if ($securityId !== null) {
            $sql .= ' AND r.security_id = :security_id';
            $params['security_id'] = $securityId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($apply) {
            $update = $this->db->prepare('UPDATE redemptions SET status = :status, actual_value_per_bond = :actual, full_default_date_planned = :full_default WHERE id = :id');
            foreach ($rows as $row) {
                $update->execute([
                    'status' => $row['status'],
                    // По этой строке причиталось 0 — и выплачено 0: номинал учтён в строке амортизации.
                    'actual' => $row['status'] === 'paid' ? '0.0000' : null,
                    'full_default' => $row['full_default_date_planned'],
                    'id' => (int) $row['id'],
                ]);
            }
        }

        return count($rows);
    }
}
