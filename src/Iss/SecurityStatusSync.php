<?php

declare(strict_types=1);

namespace BondKeeper\Iss;

use BondKeeper\Securities\SecurityStatus;
use PDO;
use RuntimeException;

/**
 * Ежедневная сверка наших бумаг со списком торгуемых облигаций биржи
 * (11.10.2026, docs/SECURITY_STATUS.md). Отвечает на три вопроса:
 *
 *   1. Бумаги больше нет в списке биржи?
 *      - срок погашения наступил → matured (погашена по сроку);
 *      - срок не наступил → в тот же день снимается признак оферты, а
 *        через GRACE_DAYS дней меняется статус: matured, если у биржи
 *        есть запись «Оферта/Погашение» рядом с днём ухода (досрочное
 *        погашение), иначе delisted (причина не установлена). Отсрочка —
 *        на случай, если бумага пропала из списка временно.
 *   2. Биржа перевела бумагу в режим «Облигации Д» (доска TQRD)?
 *      → tech_default (решение пользователя, 11.10.2026). Вернула из
 *      режима «Д» — снова active.
 *   3. Бумага со статусом delisted снова в списке? → снова active.
 *
 * Бумаги со статусом matured сами в active не возвращаются: погашение
 * необратимо, а в списке биржи погашенная бумага может провисеть день-два.
 *
 * Чем ещё меняется статус — см. BondKeeper\Securities\SecurityStatus.
 *
 * Список биржи берётся одним запросом по всему рынку облигаций. Если он
 * выглядит неполным (сбой у биржи), сверка останавливается с ошибкой и
 * ничего не меняет: иначе неполный ответ «погасил» бы половину базы.
 */
final class SecurityStatusSync
{
    /** Сколько дней бумага, пропавшая до срока погашения, ждёт смены статуса. */
    public const GRACE_DAYS = 3;

    private const BOARD_D = 'TQRD';
    private const MIN_MARKET_SIZE = 1000;
    private const MIN_PRESENT_SHARE = 0.8;
    /** Окно вокруг дня ухода с биржи, в котором ищется запись биржи о досрочном погашении. */
    private const EVIDENCE_DAYS_BEFORE = 10;
    private const EVIDENCE_DAYS_AFTER = 5;
    private const EARLY_REDEMPTION_TYPES = ['Оферта/Погашение', 'Оферта/Погашение (состоялось)'];

    public function __construct(
        private readonly IssClient $iss,
        private readonly PDO $db,
        private readonly SecurityStatus $status,
    ) {
    }

    /**
     * @param bool $apply false — только показать, что изменится
     * @return array{market: int, checked: int, matured: array<int, array<string, string>>, early: array<int, array<string, string>>,
     *               delisted: array<int, array<string, string>>, gone_today: array<int, array<string, string>>,
     *               waiting: array<int, array<string, string>>, board_d: array<int, array<string, string>>, restored: array<int, array<string, string>>}
     */
    public function run(string $today, bool $apply): array
    {
        $market = $this->fetchMarket();
        $rows = $this->db->query(
            'SELECT id, isin, short_name, status, status_reason, maturity_date, last_synced_at, missing_since FROM securities ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $inCirculation = array_values(array_filter($rows, static fn (array $r): bool => SecurityStatus::inCirculation((string) $r['status'])));
        $present = count(array_filter($inCirculation, static fn (array $r): bool => isset($market[$r['isin']])));
        if (count($market) < self::MIN_MARKET_SIZE || ($inCirculation !== [] && $present / count($inCirculation) < self::MIN_PRESENT_SHARE)) {
            throw new RuntimeException(sprintf(
                'Список биржи выглядит неполным: в нём %d бумаг, из наших %d в обращении найдено %d. Статусы не менялись.',
                count($market), count($inCirculation), $present,
            ));
        }

        $report = ['market' => count($market), 'checked' => count($inCirculation), 'matured' => [], 'early' => [], 'delisted' => [],
            'gone_today' => [], 'waiting' => [], 'board_d' => [], 'restored' => []];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $status = (string) $row['status'];
            $boards = $market[$row['isin']] ?? [];
            $item = ['isin' => (string) $row['isin'], 'name' => (string) $row['short_name']];

            if (!SecurityStatus::inCirculation($status)) {
                if ($status === SecurityStatus::DELISTED && $boards !== []) {
                    $report['restored'][] = $item + ['note' => 'снова в списке биржи'];
                    if ($apply) {
                        $this->status->set($id, SecurityStatus::ACTIVE, SecurityStatus::REASON_BACK_ON_EXCHANGE);
                        $this->setMissingSince($id, null);
                    }
                }
                continue;
            }

            if ($boards !== []) {
                if ($row['missing_since'] !== null && $apply) {
                    $this->setMissingSince($id, null);
                }
                $onBoardD = in_array(self::BOARD_D, $boards, true);
                if ($onBoardD && $status === SecurityStatus::ACTIVE) {
                    $report['board_d'][] = $item;
                    if ($apply) {
                        $this->status->set($id, SecurityStatus::TECH_DEFAULT, SecurityStatus::REASON_BOARD_D);
                    }
                } elseif (!$onBoardD && $status === SecurityStatus::TECH_DEFAULT && $row['status_reason'] === SecurityStatus::REASON_BOARD_D) {
                    $report['restored'][] = $item + ['note' => 'биржа вернула из режима «Д»'];
                    if ($apply) {
                        $this->status->set($id, SecurityStatus::ACTIVE, SecurityStatus::REASON_LEFT_BOARD_D);
                    }
                }
                continue;
            }

            // Бумаги нет в списке биржи.
            $missingSince = $row['missing_since'] !== null ? (string) $row['missing_since'] : self::firstMissingDay($row['last_synced_at'], $today);
            if ($apply) {
                if ($row['missing_since'] === null) {
                    $this->setMissingSince($id, $missingSince);
                }
                $this->status->clearOffers($id);
            }
            $maturity = $row['maturity_date'] !== null ? (string) $row['maturity_date'] : null;

            // «Погашена по сроку» — только для бумаги без нарушений: выпуск в техдефолте или дефолте,
            // пропавший с биржи после срока, погашенным считать нельзя — он идёт общим путём (delisted).
            if ($maturity !== null && $maturity <= $today && $status === SecurityStatus::ACTIVE) {
                $report['matured'][] = $item + ['ended_at' => $maturity];
                if ($apply) {
                    $this->status->set($id, SecurityStatus::MATURED, SecurityStatus::REASON_SCHEDULED, $maturity);
                }
            } elseif (self::daysBetween($missingSince, $today) >= self::GRACE_DAYS) {
                $redeemedOn = $this->earlyRedemptionDate((string) $row['isin'], $missingSince);
                $lastSeen = date('Y-m-d', (int) strtotime($missingSince . ' -1 day'));
                if ($redeemedOn !== null) {
                    $report['early'][] = $item + ['ended_at' => $redeemedOn, 'maturity' => (string) $maturity];
                    if ($apply) {
                        $this->status->set($id, SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_MOEX, $redeemedOn);
                    }
                } else {
                    $report['delisted'][] = $item + ['ended_at' => $lastSeen, 'maturity' => (string) $maturity];
                    if ($apply) {
                        $this->status->set($id, SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, $lastSeen);
                    }
                }
            } else {
                $key = $row['missing_since'] === null ? 'gone_today' : 'waiting';
                $report[$key][] = $item + ['missing_since' => $missingSince, 'maturity' => (string) $maturity];
            }
        }

        return $report;
    }

    /**
     * Список торгуемых облигаций: ISIN → доски, на которых бумага сейчас
     * торгуется. Строка со STATUS, отличным от «A», торговлей не считается
     * (АльфаБО-21 два дня после досрочного погашения висела в списке со
     * статусом «N»).
     *
     * @return array<string, array<int, string>>
     */
    private function fetchMarket(): array
    {
        return TradedBonds::fetch($this->iss);
    }

    /**
     * Дата записи биржи «Оферта/Погашение» рядом с днём, когда бумага
     * пропала из списка, — так биржа отмечает досрочное погашение (ПИК
     * БО-П04: запись на 20.08.2026, последний торговый день 21.08). null —
     * такой записи нет.
     */
    private function earlyRedemptionDate(string $isin, string $missingSince): ?string
    {
        $from = date('Y-m-d', (int) strtotime($missingSince . ' -' . self::EVIDENCE_DAYS_BEFORE . ' days'));
        $to = date('Y-m-d', (int) strtotime($missingSince . ' +' . self::EVIDENCE_DAYS_AFTER . ' days'));
        $best = null;
        $start = 0;
        do {
            $response = $this->iss->getJson("/statistics/engines/stock/markets/bonds/bondization/{$isin}.json", [
                'iss.only' => 'offers,offers.cursor',
                'limit' => 100,
                'start' => $start,
            ]);
            $rows = IssClient::block($response, 'offers');
            foreach ($rows as $row) {
                $date = (string) ($row['offerdate'] ?? '');
                if (in_array(trim((string) ($row['offertype'] ?? '')), self::EARLY_REDEMPTION_TYPES, true) && $date >= $from && $date <= $to
                    && ($best === null || $date > $best)) {
                    $best = $date;
                }
            }
            $total = (int) (IssClient::block($response, 'offers.cursor')[0]['TOTAL'] ?? 0);
            $start += 100;
        } while ($start < $total);

        return $best;
    }

    /** С какого дня бумаги нет на бирже: следующий день после последней синхронизации, но не позже сегодняшнего. */
    private static function firstMissingDay(?string $lastSyncedAt, string $today): string
    {
        if ($lastSyncedAt === null || $lastSyncedAt === '') {
            return $today;
        }

        return min($today, date('Y-m-d', (int) strtotime(substr($lastSyncedAt, 0, 10) . ' +1 day')));
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) round((strtotime($to) - strtotime($from)) / 86400);
    }

    private function setMissingSince(int $securityId, ?string $date): void
    {
        $this->db->prepare('UPDATE securities SET missing_since = :date WHERE id = :id')->execute(['date' => $date, 'id' => $securityId]);
    }
}
