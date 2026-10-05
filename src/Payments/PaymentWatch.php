<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use BondKeeper\Events\EventPublisher;
use PDO;

/**
 * События по выплатам, которые мы считаем сами по графику, без сообщения
 * источника (Этап 5, см. docs/STAGE5_PAYMENTS.md). Схема согласована с
 * пользователем 04.10.2026:
 *
 *   1. Напоминание R1 — накануне выплаты. Сутки считаются от даты ПО
 *      ГРАФИКУ, а не от дня, когда деньги придут на деле: смысл — дать
 *      клиенту возможность продать бумагу до выплаты. Если дата по
 *      графику — выходной, в тексте называется и день исполнения.
 *   2. Вечер дня исполнения (19:00 мск, после крайнего срока перевода в
 *      17:00): сообщения «получено НРД» ещё нет → жёлтое B2a «деньги от
 *      эмитента пока не поступили».
 *   3. Утро следующего рабочего дня: всё ещё нет → B2a повторно.
 *   4. Вечер следующего рабочего дня: от НРД нет вообще ничего (ни
 *      «получено», ни «частично», ни «не исполнено») — это странно и
 *      похоже на сбой: B2b, уведомление только администратору.
 * «Нет сообщения» = выплата в графике всё ещё в статусе planned: любое
 * обработанное сообщение НРД (PaymentProcessor) статус меняет.
 *
 * Купон, амортизация и погашение одной бумаги на одну дату — одно
 * событие на бумагу (в payload — список выплат), а не три подряд.
 * Повторный запуск за тот же день событий не дублирует: проверяется
 * наличие события того же типа по бумаге на ту же дату.
 *
 * Оферты сюда не входят (решение пользователя: вернёмся позже).
 * Переносимый SQL — офлайн-тест: tests/test_payments.php.
 */
final class PaymentWatch
{
    public const CHECK_EVENING = 'evening';
    public const CHECK_MORNING = 'morning';

    public function __construct(
        private readonly PDO $db,
        private readonly EventPublisher $publisher,
        private readonly WorkingCalendar $calendar,
    ) {
    }

    /**
     * Напоминания о выплатах с датой по графику «завтра» ($today + 1 день).
     * $createEvents = false — только показать список, ничего не создавая.
     *
     * @return array{created: int, existing: int, items: array<int, array<string, mixed>>}
     */
    public function remind(string $today, bool $createEvents = true): array
    {
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $result = ['created' => 0, 'existing' => 0, 'items' => []];

        foreach ($this->plannedPayments([$tomorrow]) as $group) {
            $result['items'][] = $group;
            if (!$createEvents) {
                continue;
            }
            if ($this->eventExists('R1', (int) $group['security_id'], $tomorrow)) {
                $result['existing']++;
                continue;
            }
            $this->publisher->publishPaymentEvent(
                eventTypeCode: 'R1',
                issuerId: (int) $group['issuer_id'],
                securityId: (int) $group['security_id'],
                eventDate: $tomorrow,
                statusText: 'Завтра выплата: ' . self::kindsText($group['payments']),
                payload: $this->payload($group),
                amountPlanned: self::totalPlanned($group['payments']),
            );
            $result['created']++;
        }

        return $result;
    }

    /**
     * Проверка «сообщения о получении нет». $check = evening — выплаты с
     * днём исполнения сегодня; morning — с днём исполнения в предыдущий
     * рабочий день. В нерабочий день ничего не делает.
     * $createEvents = false — режим «только администратору»: список
     * возвращается, события клиентам не создаются.
     *
     * @return array{created: int, existing: int, items: array<int, array<string, mixed>>}
     */
    public function checkNoReceipt(string $today, string $check, bool $createEvents): array
    {
        $result = ['created' => 0, 'existing' => 0, 'items' => []];
        if (!$this->calendar->isWorkingDay($today)) {
            return $result;
        }
        $dueDay = $check === self::CHECK_MORNING ? $this->calendar->previousWorkingDay($today) : $today;

        foreach ($this->plannedPayments($this->calendar->scheduleDatesDueOn($dueDay)) as $group) {
            $result['items'][] = $group;
            if (!$createEvents) {
                continue;
            }
            if ($this->eventExists('B2a', (int) $group['security_id'], $today)) {
                $result['existing']++;
                continue;
            }
            $this->publisher->publishPaymentEvent(
                eventTypeCode: 'B2a',
                issuerId: (int) $group['issuer_id'],
                securityId: (int) $group['security_id'],
                eventDate: $today,
                statusText: 'Деньги от эмитента пока не поступили: ' . self::kindsText($group['payments']),
                payload: $this->payload($group) + ['check' => $check],
                amountPlanned: self::totalPlanned($group['payments']),
            );
            $result['created']++;
        }

        return $result;
    }

    /**
     * Вечер следующего рабочего дня после дня исполнения: от НРД по
     * выплате нет вообще ничего. Событие B2b клиенту не показывается
     * (notify_client = FALSE) — список уходит администратору. В items —
     * только новые случаи (о которых администратору ещё не сообщалось).
     * $createEvents = false — только показать список, ничего не создавая.
     *
     * @return array{created: int, existing: int, items: array<int, array<string, mixed>>}
     */
    public function checkSilence(string $today, bool $createEvents = true): array
    {
        $result = ['created' => 0, 'existing' => 0, 'items' => []];
        if (!$this->calendar->isWorkingDay($today)) {
            return $result;
        }
        $dueDay = $this->calendar->previousWorkingDay($today);

        foreach ($this->plannedPayments($this->calendar->scheduleDatesDueOn($dueDay)) as $group) {
            if (!$createEvents) {
                $result['items'][] = $group;
                continue;
            }
            if ($this->eventExists('B2b', (int) $group['security_id'], $today)) {
                $result['existing']++;
                continue;
            }
            $result['items'][] = $group;
            $this->publisher->publishPaymentEvent(
                eventTypeCode: 'B2b',
                issuerId: (int) $group['issuer_id'],
                securityId: (int) $group['security_id'],
                eventDate: $today,
                statusText: 'Нет сообщений НРД по выплате: ' . self::kindsText($group['payments']),
                payload: $this->payload($group),
                amountPlanned: self::totalPlanned($group['payments']),
            );
            $result['created']++;
        }

        return $result;
    }

    /**
     * Строки для сообщения администратору по списку бумаг.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    public static function adminLines(array $items): array
    {
        return array_map(
            static fn (array $g): string => "• {$g['security_name']} ({$g['isin']}), {$g['issuer_name']}: " . self::kindsText($g['payments'])
                . ', дата по графику ' . date('d.m.Y', (int) strtotime((string) $g['payment_date'])),
            $items,
        );
    }

    /** Года, нужного для расчётов вокруг даты, нет в календаре рабочих дней. */
    public function calendarGapWarning(string $today): ?string
    {
        foreach ([$today, date('Y-m-d', strtotime($today . ' +20 days'))] as $date) {
            $year = (int) substr($date, 0, 4);
            if (!$this->calendar->coversYear($year)) {
                return "В календаре рабочих дней (config/working_calendar.php) нет {$year} года — праздники не учитываются, сроки по выплатам могут считаться неверно.";
            }
        }

        return null;
    }

    /**
     * Выплаты в статусе planned на указанные даты по графику, сгруппированные
     * по бумаге и дате. Бумаги в обращении: active и tech_default (у бумаги
     * в техдефолте следующие выплаты по графику остаются — и они важнее
     * обычных); погашенные, дефолтные и снятые с торгов не берутся.
     *
     * @param array<int, string> $dates
     * @return array<int, array{security_id: int|string, issuer_id: int|string, isin: string, security_name: string, issuer_name: string, payment_date: string, payments: array<int, array{kind: string, amount_planned: ?string}>}>
     */
    private function plannedPayments(array $dates): array
    {
        if ($dates === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($dates), '?'));
        $sources = [
            [PaymentMessage::KIND_COUPON, 'coupons', 'period_end_date', ''],
            [PaymentMessage::KIND_AMORTIZATION, 'amortizations', 'payment_date_planned', ''],
            [PaymentMessage::KIND_REDEMPTION, 'redemptions', 'payment_date_planned', " AND p.redemption_type = 'scheduled_maturity'"],
        ];

        $groups = [];
        foreach ($sources as [$kind, $table, $dateColumn, $extra]) {
            $stmt = $this->db->prepare(
                "SELECT p.security_id, p.issuer_id, p.{$dateColumn} AS payment_date, p.value_per_bond,
                        s.isin, s.short_name AS security_name, i.short_name AS issuer_name
                 FROM {$table} p
                 JOIN securities s ON s.id = p.security_id
                 JOIN issuers i ON i.id = p.issuer_id
                 WHERE p.{$dateColumn} IN ({$in}) AND p.status = 'planned' AND s.status IN ('active', 'tech_default'){$extra}
                 ORDER BY p.security_id, p.{$dateColumn}"
            );
            $stmt->execute($dates);
            foreach ($stmt->fetchAll() as $row) {
                $key = $row['security_id'] . ':' . $row['payment_date'];
                $groups[$key] ??= [
                    'security_id' => $row['security_id'],
                    'issuer_id' => $row['issuer_id'],
                    'isin' => (string) $row['isin'],
                    'security_name' => (string) $row['security_name'],
                    'issuer_name' => (string) $row['issuer_name'],
                    'payment_date' => (string) $row['payment_date'],
                    'payments' => [],
                ];
                $groups[$key]['payments'][] = ['kind' => $kind, 'amount_planned' => $row['value_per_bond'] !== null ? (string) $row['value_per_bond'] : null];
            }
        }
        ksort($groups);

        return array_values($groups);
    }

    private function eventExists(string $eventTypeCode, int $securityId, string $eventDate): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM events WHERE event_type_code = :code AND security_id = :security_id AND event_date = :event_date LIMIT 1');
        $stmt->execute(['code' => $eventTypeCode, 'security_id' => $securityId, 'event_date' => $eventDate]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array{isin: string, security_name: string, payment_date: string, payments: array<int, array{kind: string, amount_planned: ?string}>} $group
     * @return array<string, mixed>
     */
    private function payload(array $group): array
    {
        return [
            'isin' => $group['isin'],
            'security_name' => $group['security_name'],
            'payment_date' => $group['payment_date'],
            'effective_date' => $this->calendar->effectiveDate($group['payment_date']),
            'payments' => $group['payments'],
        ];
    }

    /** @param array<int, array{kind: string, amount_planned: ?string}> $payments */
    private static function kindsText(array $payments): string
    {
        return implode(', ', array_map(static fn (array $p): string => PaymentMessage::kindLabel($p['kind']), $payments));
    }

    /** @param array<int, array{kind: string, amount_planned: ?string}> $payments */
    private static function totalPlanned(array $payments): ?string
    {
        $total = 0.0;
        foreach ($payments as $payment) {
            if ($payment['amount_planned'] === null) {
                return null;
            }
            $total += (float) $payment['amount_planned'];
        }

        return number_format($total, 4, '.', '');
    }
}
