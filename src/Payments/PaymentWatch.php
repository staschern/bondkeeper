<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use BondKeeper\Events\EventPublisher;
use PDO;

/**
 * События по выплатам, которые мы считаем сами по графику, без сообщения
 * источника (Этап 5, см. docs/STAGE5_PAYMENTS.md).
 *
 *   1. Напоминание R1 — накануне выплаты. Сутки считаются от даты ПО
 *      ГРАФИКУ, а не от дня, когда деньги придут на деле: смысл — дать
 *      клиенту возможность продать бумагу до выплаты (решение
 *      пользователя, 04.10.2026). Если дата по графику — выходной, в
 *      тексте называется и день исполнения.
 *   2. Следующий рабочий день после дня исполнения, 12:02 мск: сообщения
 *      о деньгах от НРД нет → жёлтое B2a.
 *   3. Тот же день, 19:00 мск: от НРД нет вообще ничего (ни денег, ни
 *      объявления о неисполнении) — похоже на сбой: B2b, только
 *      администратору.
 *
 * Почему одна проверка и именно в полдень следующего дня (решение
 * пользователя, 05.10.2026, вместо прежних «19:00 дня выплаты и утро»).
 * НРД сообщает о деньгах на следующий рабочий день после их поступления,
 * в 9:45–11:00. По 982 выплатам тестовой выгрузки (плановые даты
 * 21–29.09.2026): к 19:00 дня выплаты НРД сообщил о 74 % выплат, на
 * следующий рабочий день к 10:00 — о 79 %, к 12:00 — о 99 %. Проверка в
 * день выплаты поднимала бы ложную тревогу по каждой четвёртой
 * своевременной выплате. Объявление о неисполнении НРД публикует в тот
 * же следующий день, чаще в 17:20–17:35, — поэтому «тишина» проверяется
 * вечером.
 *
 * «Нет сообщения» = выплата в графике всё ещё в статусе planned: любое
 * обработанное сообщение НРД (PaymentProcessor) статус меняет.
 *
 * Купон, амортизация и погашение одной бумаги на одну дату — одно событие
 * на бумагу (в payload — список выплат), а не три подряд. Повторный запуск
 * за тот же день событий не дублирует.
 *
 * Погашение в нашем графике: выплату номинала в конце срока биржа отдаёт
 * строкой amortizations, а в redemptions у таких бумаг сумма 0 (см.
 * докблок PaymentProcessor). Поэтому строка redemptions с нулём выплатой
 * не считается, а амортизация на дату погашения называется погашением.
 *
 * Оферты сюда не входят (решение пользователя: вернёмся позже).
 * Переносимый SQL — офлайн-тест: tests/test_payments.php.
 */
final class PaymentWatch
{
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
     * Полдень следующего рабочего дня после дня исполнения: по выплате
     * нет сообщения о деньгах. В нерабочий день ничего не делает.
     * $createEvents = false — режим «только администратору»: список
     * возвращается, события клиентам не создаются.
     *
     * @return array{created: int, existing: int, items: array<int, array<string, mixed>>}
     */
    public function checkNoReceipt(string $today, bool $createEvents): array
    {
        $result = ['created' => 0, 'existing' => 0, 'items' => []];
        if (!$this->calendar->isWorkingDay($today)) {
            return $result;
        }
        $dueDay = $this->calendar->previousWorkingDay($today);

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
                statusText: 'НРД не сообщил о поступлении денег: ' . self::kindsText($group['payments']),
                payload: $this->payload($group) + ['check' => 'noon', 'due_date' => $dueDay],
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
                payload: $this->payload($group) + ['due_date' => $dueDay],
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
     * @return array<int, array{security_id: int|string, issuer_id: int|string, isin: string, security_name: string, issuer_name: string, currency: ?string, payment_date: string, payments: array<int, array{kind: string, amount_planned: ?string}>}>
     */
    private function plannedPayments(array $dates): array
    {
        if ($dates === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($dates), '?'));
        $select = 'p.security_id, p.issuer_id, p.value_per_bond, s.isin, s.short_name AS security_name, s.currency, i.short_name AS issuer_name';
        $joins = 'JOIN securities s ON s.id = p.security_id JOIN issuers i ON i.id = p.issuer_id';
        $where = "p.status = 'planned' AND s.status IN ('active', 'tech_default')";

        $queries = [
            // Купоны.
            "SELECT {$select}, p.period_end_date AS payment_date, 'coupon' AS kind
             FROM coupons p {$joins}
             WHERE p.period_end_date IN ({$in}) AND {$where}",
            // Амортизации. На дату погашения, если в redemptions ноль, — это и есть погашение.
            "SELECT {$select}, p.payment_date_planned AS payment_date,
                    CASE WHEN r.id IS NOT NULL AND COALESCE(CAST(r.value_per_bond AS DECIMAL(18,4)), 0) = 0 THEN 'redemption' ELSE 'amortization' END AS kind
             FROM amortizations p {$joins}
             LEFT JOIN redemptions r ON r.security_id = p.security_id AND r.payment_date_planned = p.payment_date_planned
                 AND r.redemption_type = 'scheduled_maturity'
             WHERE p.payment_date_planned IN ({$in}) AND {$where}",
            // Погашения — только строки с суммой. CAST — чтобы сравнение было числовым и там, где
            // колонка хранится текстом (SQLite в офлайн-тестах): строка «0.0000» иначе считается больше нуля.
            "SELECT {$select}, p.payment_date_planned AS payment_date, 'redemption' AS kind
             FROM redemptions p {$joins}
             WHERE p.payment_date_planned IN ({$in}) AND {$where} AND p.redemption_type = 'scheduled_maturity' AND CAST(p.value_per_bond AS DECIMAL(18,4)) > 0",
        ];

        $groups = [];
        foreach ($queries as $sql) {
            $stmt = $this->db->prepare($sql . ' ORDER BY p.security_id');
            $stmt->execute($dates);
            foreach ($stmt->fetchAll() as $row) {
                $key = $row['security_id'] . ':' . $row['payment_date'];
                $groups[$key] ??= [
                    'security_id' => $row['security_id'],
                    'issuer_id' => $row['issuer_id'],
                    'isin' => (string) $row['isin'],
                    'security_name' => (string) $row['security_name'],
                    'issuer_name' => (string) $row['issuer_name'],
                    'currency' => $row['currency'] !== null ? (string) $row['currency'] : null,
                    'payment_date' => (string) $row['payment_date'],
                    'payments' => [],
                ];
                $groups[$key]['payments'][] = [
                    'kind' => (string) $row['kind'],
                    'amount_planned' => $row['value_per_bond'] !== null ? (string) $row['value_per_bond'] : null,
                ];
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
     * @param array{isin: string, security_name: string, currency: ?string, payment_date: string, payments: array<int, array{kind: string, amount_planned: ?string}>} $group
     * @return array<string, mixed>
     */
    private function payload(array $group): array
    {
        return [
            'isin' => $group['isin'],
            'security_name' => $group['security_name'],
            'payment_date' => $group['payment_date'],
            'effective_date' => $this->calendar->effectiveDate($group['payment_date']),
            'currency' => $group['currency'],
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
