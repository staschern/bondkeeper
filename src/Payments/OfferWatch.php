<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use BondKeeper\Events\EventPublisher;
use BondKeeper\Securities\SecurityStatus;
use PDO;

/**
 * Напоминание о предстоящей оферте — событие R2 (ТЗ пользователя от
 * 12.10.2026, docs/OFFERS.md, раздел «Напоминания»).
 *
 * По умолчанию — за 30 календарных дней до даты оферты, с указанием её
 * вида. Пользователь может задать свой интервал; для каждого интервала
 * создаётся своё событие (events.reminder_days), получают его те, у кого
 * этот интервал задан (NotificationPreferences).
 *
 * Правила — те же, что у напоминаний о выплатах (PaymentWatch):
 *   - напоминание уходит в рабочий день; если «дата минус N» — нерабочий,
 *     то в последний рабочий день перед ним;
 *   - одно напоминание на бумагу, дату и интервал; повторный запуск
 *     ничего не дублирует;
 *   - бумаги — в обращении без объявленного дефолта (active, tech_default).
 *
 * Если мы узнали об оферте, когда до неё осталось меньше N дней (колл,
 * объявленный НРД за две недели; оферта, появившаяся у биржи поздно;
 * первый запуск после установки), напоминание уходит в ближайший рабочий
 * день: «не напомнить вовсе» хуже, чем «напомнить позже».
 *
 * Отменённые оферты (offers.status = cancelled) не напоминаются.
 *
 * Переносимый SQL — офлайн-тест: tests/test_reminder_settings.php.
 */
final class OfferWatch
{
    public function __construct(
        private readonly PDO $db,
        private readonly EventPublisher $publisher,
        private readonly WorkingCalendar $calendar,
        /**
         * О путах с датой оферты раньше этой даты не напоминаем (config/payments.php, offer_put_reminders_from).
         * Решение пользователя от 12.10.2026 для первого запуска: обычно о путе напоминаем за 30 дней, о появившемся
         * поздно — сразу; но о тех, что уже были в базе на день включения и до которых осталось меньше 10 дней,
         * напоминать поздно. Коллов не касается.
         */
        private readonly ?string $skipPutsBefore = null,
    ) {
    }

    /**
     * @param bool $createEvents false — только показать список, ничего не создавая
     * @param ?array<int, int> $issuerIds null — все эмитенты; список — только бумаги этих эмитентов (свой интервал пользователей)
     * @param ?array<int, string> $isins null — все бумаги; список — только эти бумаги. Так опрос НРД напоминает об оферте сразу
     *        после её объявления, не дожидаясь утреннего запуска (решение пользователя, 12.10.2026: если дата стала известна,
     *        когда до неё меньше 30 дней, напоминаем сразу, как получили информацию)
     * @return array{created: int, existing: int, skipped_puts: int, items: array<int, array<string, mixed>>} items — оферты, о которых
     *         напоминаем в этот запуск; skipped_puts — путы, о которых не напоминаем из-за offer_put_reminders_from
     */
    public function remind(string $today, bool $createEvents = true, int $daysBefore = 30, ?array $issuerIds = null, ?array $isins = null): array
    {
        $result = ['created' => 0, 'existing' => 0, 'skipped_puts' => 0, 'items' => []];
        $dates = $this->calendar->scheduleDatesRemindedOn($today, $daysBefore);
        if ($dates === [] || $issuerIds === [] || $isins === []) {
            return $result;
        }

        foreach ($this->upcomingOffers($today, (string) end($dates), $issuerIds, $isins) as $group) {
            if ($this->eventExists((int) $group['security_id'], (string) $group['offer_date'], $daysBefore)) {
                $result['existing']++;
                continue;
            }
            if ($this->skipPutsBefore !== null && (string) $group['offer_date'] < $this->skipPutsBefore) {
                $group['offers'] = array_values(array_filter($group['offers'], static fn (array $o): bool => $o['type'] !== 'put'));
                if ($group['offers'] === []) {
                    $result['skipped_puts']++;
                    continue;
                }
            }
            $result['items'][] = $group;
            if (!$createEvents) {
                continue;
            }
            $this->publisher->publishPaymentEvent(
                eventTypeCode: 'R2',
                issuerId: (int) $group['issuer_id'],
                securityId: (int) $group['security_id'],
                eventDate: (string) $group['offer_date'],
                statusText: 'Оферта ' . date('d.m.Y', (int) strtotime((string) $group['offer_date'])) . ': '
                    . implode(', ', array_map(static fn (array $o): string => self::typeLabel((string) $o['type']), $group['offers'])),
                payload: [
                    'isin' => $group['isin'],
                    'security_name' => $group['security_name'],
                    'offer_date' => $group['offer_date'],
                    'offers' => $group['offers'],
                    'reminder_date' => $today,
                    'days_before' => $daysBefore,
                ] + (in_array($group['security_status'], [SecurityStatus::TECH_DEFAULT, SecurityStatus::DEFAULTED], true)
                    ? ['security_status' => $group['security_status']] : []),
                reminderDays: $daysBefore,
            );
            $result['created']++;
        }

        return $result;
    }

    /**
     * Строки для лога и просмотра.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    public static function adminLines(array $items): array
    {
        return array_map(
            static fn (array $g): string => "• {$g['security_name']} ({$g['isin']}), {$g['issuer_name']}: "
                . implode(', ', array_map(static fn (array $o): string => self::typeLabel((string) $o['type']), $g['offers']))
                . ', дата ' . date('d.m.Y', (int) strtotime((string) $g['offer_date'])),
            $items,
        );
    }

    /** Вид оферты словами — для лога и короткой сводки события. */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'put' => 'пут',
            'call' => 'колл',
            default => 'вид не определён',
        };
    }

    /**
     * Неотменённые оферты с датой после $today и не позже $lastDate,
     * сгруппированные по бумаге и дате (пут и колл одной бумаги на одну
     * дату — одно напоминание).
     *
     * @param ?array<int, int> $issuerIds
     * @param ?array<int, string> $isins
     * @return array<int, array{security_id: int, issuer_id: int, isin: string, security_name: string, issuer_name: string, security_status: string, offer_date: string, offers: array<int, array<string, mixed>>}>
     */
    private function upcomingOffers(string $today, string $lastDate, ?array $issuerIds, ?array $isins = null): array
    {
        $sql = "SELECT o.security_id, o.issuer_id, o.offer_type, o.execution_date_planned, o.period_start, o.period_end, o.price_percent,
                       o.source, o.announcement_date, s.isin, s.short_name AS security_name, s.status AS security_status, i.short_name AS issuer_name
                FROM offers o
                JOIN securities s ON s.id = o.security_id
                JOIN issuers i ON i.id = o.issuer_id
                WHERE o.status <> 'cancelled' AND o.execution_date_planned > :today AND o.execution_date_planned <= :last_date
                  AND s.status IN ('active', 'tech_default')"
            // Числа приведены к целым — подставлять их в текст запроса безопасно.
            . ($issuerIds !== null ? ' AND o.issuer_id IN (' . implode(',', array_map('intval', $issuerIds)) . ')' : '')
            . ($isins !== null ? ' AND s.isin IN (' . implode(',', array_map(static fn (int $i): string => ":isin{$i}", array_keys(array_values($isins)))) . ')' : '')
            // Пут — первым: это право владельца, и по нему нужно успеть подать заявку.
            . " ORDER BY o.execution_date_planned, o.security_id, CASE o.offer_type WHEN 'put' THEN 0 ELSE 1 END, o.id";
        $stmt = $this->db->prepare($sql);
        $params = ['today' => $today, 'last_date' => $lastDate];
        foreach (array_values($isins ?? []) as $i => $isin) {
            $params["isin{$i}"] = (string) $isin;
        }
        $stmt->execute($params);

        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['security_id'] . ':' . $row['execution_date_planned'];
            $groups[$key] ??= [
                'security_id' => (int) $row['security_id'],
                'issuer_id' => (int) $row['issuer_id'],
                'isin' => (string) $row['isin'],
                'security_name' => (string) $row['security_name'],
                'issuer_name' => (string) $row['issuer_name'],
                'security_status' => (string) $row['security_status'],
                'offer_date' => (string) $row['execution_date_planned'],
                'offers' => [],
            ];
            $groups[$key]['offers'][] = [
                'type' => (string) $row['offer_type'],
                'period_start' => $row['period_start'] !== null ? (string) $row['period_start'] : null,
                'period_end' => $row['period_end'] !== null ? (string) $row['period_end'] : null,
                'price_percent' => $row['price_percent'] !== null ? (string) $row['price_percent'] : null,
                // Объявлено НРД — эмитент уже принял решение; только у биржи — это дата по условиям выпуска.
                'announced' => $row['announcement_date'] !== null || $row['source'] === 'nsd',
            ];
        }

        return array_values($groups);
    }

    private function eventExists(int $securityId, string $offerDate, int $daysBefore): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM events WHERE event_type_code = 'R2' AND security_id = :security_id AND event_date = :event_date AND reminder_days = :days LIMIT 1"
        );
        $stmt->execute(['security_id' => $securityId, 'event_date' => $offerDate, 'days' => $daysBefore]);

        return $stmt->fetchColumn() !== false;
    }
}
