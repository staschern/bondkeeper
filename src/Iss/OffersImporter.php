<?php

declare(strict_types=1);

namespace BondKeeper\Iss;

use BondKeeper\Securities\SecurityStatus;
use PDO;
use RuntimeException;

/**
 * Оферты из таблицы оферт биржи: каждую ночь таблица offers приводится к
 * тому, что биржа показывает на сегодня (переписано 11.10.2026,
 * docs/OFFERS.md).
 *
 * Прежняя версия обходила бумаги по одной и хранила одну строку на бумагу —
 * ближайшую оферту. Сверка с Bondana и биржей (10.10.2026) показала:
 *   - запрос по бумаге шёл без limit, биржа отдавала 20 самых старых строк,
 *     и у бумаг с длинной историей предстоящая оферта не была видна;
 *   - вторая и следующие оферты терялись;
 *   - строки не удалялись: прошедшие оферты и оферты ушедших с биржи бумаг
 *     оставались навсегда;
 *   - погашение по сроку записывалось как оферта (88 бумаг);
 *   - признак securities.has_offer ставился по полю BUYBACKDATE, а оно
 *     заполнено у всех ипотечных выпусков, у которых оферты нет.
 *
 * === Откуда берём ===
 *
 * Один список на весь рынок — все предстоящие оферты:
 *   /iss/statistics/engines/stock/markets/bonds/bondization.json
 *       ?iss.only=offers,offers.cursor&from=СЕГОДНЯ&till=2100-01-01&limit=100&start=N
 * (на 11.10.2026 — 694 строки, 7 запросов вместо трёх с лишним тысяч).
 * И список торгуемых бумаг (TradedBonds) — ещё один запрос.
 *
 * === Что считается офертой ===
 *
 * По виду строки у биржи (offertype), проверено на сверке 544 бумаг:
 *   «Оферта»            → put  (447 совпадений с путами Bondana, 0 противоречий);
 *   «Оферта/Погашение» с датой раньше срока погашения → call (6 из 6);
 *   «Оферта/Погашение» с датой, равной сроку, — это само погашение, не
 *        оферта: пропускается (88 бумаг — короткие выпуски ВТБ, ВЭБ.РФ);
 *   строки с пометкой в скобках («состоялось», «отменено», «дефолт»,
 *        «технический дефолт») — не предстоящие: пропускаются.
 * Оговорка: «Оферта» у биржи — это и право владельца предъявить бумагу, и
 * добровольный выкуп эмитентом по объявленной цене (АПРИ 2Р14 — по 90 %);
 * различить их по данным биржи нельзя, оба идут как put.
 *
 * Строки биржи хранятся как есть, одна к одной. У 13 бумаг биржа даёт на
 * одну оферту две строки с разницей в 1–14 дней (обычно первая — с периодом
 * предъявления, вторая — без). Склеивать их не стали: проверка по
 * сообщениям НРД показала, что верной оказывается то первая дата («ГПБ
 * Финанс» 001Р-07: 29.09.2026), то вторая («ФСК — Россети» БО-05:
 * 25.09.2026), и общего правила нет.
 *
 * === Признак оферты ===
 *
 * securities.has_offer = 1, только если выполнены оба условия: бумага
 * сегодня торгуется И у неё есть предстоящая неотменённая оферта. Одной
 * таблицы оферт мало: у погашенной досрочно бумаги плановый пут может
 * висеть у биржи ещё долго (БиоВит 1Р1 — замечание пользователя,
 * 11.10.2026). Признак ставит только этот класс и разбор объявлений НРД.
 *
 * === Что удаляется ===
 *
 *   - оферта, дата которой прошла (решение пользователя, 11.10.2026:
 *     историю не храним);
 *   - все оферты бумаги, которая не торгуется или вышла из обращения;
 *   - строка, полученная с биржи, которой в таблице биржи больше нет.
 * Строки из объявлений НРД (source = nsd) живут до своей даты. Оферта,
 * отменённая НРД (status = cancelled), с биржи заново не загружается.
 *
 * === Защита ===
 *
 * Если список торгуемых бумаг или список оферт выглядит неполным (сбой у
 * биржи), загрузка останавливается с ошибкой и ничего не меняет — иначе
 * половина оферт была бы удалена. Все изменения — одной транзакцией.
 */
final class OffersImporter
{
    private const PUT_LABEL = 'Оферта';
    private const CALL_LABEL = 'Оферта/Погашение';

    /** Меньше этого в списках биржи — считаем ответ неполным. */
    private const MIN_MARKET_SIZE = 1000;
    private const MIN_PRESENT_SHARE = 0.8;
    private const MIN_OFFERS = 100;
    /** Предел числа страниц списка оферт: листание не должно стать бесконечным ни при каком ответе биржи. */
    private const MAX_PAGES = 60;

    /** Отмена НРД гасит строки биржи того же вида в пределах стольких дней от своей даты. */
    public const CANCEL_WINDOW_DAYS = 14;

    public function __construct(
        private readonly IssClient $iss,
        private readonly PDO $db,
    ) {
    }

    /**
     * Готова ли таблица offers (применена ли миграция 030). null — готова, иначе текст для разработчика.
     */
    public static function schemaProblem(PDO $db): ?string
    {
        try {
            $db->query('SELECT source, source_ref FROM offers LIMIT 1');
        } catch (\Throwable $e) {
            return 'В таблице offers нет колонок source / source_ref — не применена миграция database/030_offers_multiple_rows.sql. Оферты не менялись.';
        }

        return null;
    }

    /**
     * @param bool $apply false — только показать, что изменится
     * @return array{market: int, exchange_rows: int, kept: int, skipped: array<string, int>, before: int, after: int, updated: int,
     *               added: array<int, array<string, string>>, removed: array<int, array<string, string>>,
     *               flag_before: int, flag_after: int, flag_on: array<int, array<string, string>>, flag_off: array<int, array<string, string>>,
     *               by_type: array<string, int>, bonds_with_offers: int}
     */
    public function rebuild(string $today, bool $apply): array
    {
        $market = TradedBonds::fetch($this->iss);
        $securities = $this->db->query('SELECT id, isin, issuer_id, short_name, maturity_date, status, has_offer FROM securities')->fetchAll(PDO::FETCH_ASSOC);

        $inCirculation = array_values(array_filter($securities, static fn (array $s): bool => SecurityStatus::inCirculation((string) $s['status'])));
        $tradable = [];
        foreach ($inCirculation as $security) {
            if (isset($market[$security['isin']])) {
                $tradable[(string) $security['isin']] = $security;
            }
        }
        if (count($market) < self::MIN_MARKET_SIZE || ($inCirculation !== [] && count($tradable) / count($inCirculation) < self::MIN_PRESENT_SHARE)) {
            throw new RuntimeException(sprintf(
                'Список биржи выглядит неполным: в нём %d бумаг, из наших %d в обращении найдено %d. Оферты не менялись.',
                count($market), count($inCirculation), count($tradable),
            ));
        }

        $exchangeRows = $this->fetchUpcoming($today);
        $existing = $this->db->query(
            'SELECT id, security_id, offer_type, execution_date_planned, period_start, period_end, price_percent, status, source FROM offers ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $knownIsins = array_fill_keys(array_map(static fn (array $s): string => (string) $s['isin'], $securities), true);
        $byId = [];
        foreach ($securities as $security) {
            $byId[(int) $security['id']] = $security;
        }
        $tradableIds = [];
        foreach ($tradable as $security) {
            $tradableIds[(int) $security['id']] = true;
        }
        /** @var array<int, array<int, array{type: string, date: string}>> $cancelled отмены НРД по бумагам */
        $cancelled = [];
        foreach ($existing as $row) {
            if ($row['status'] === 'cancelled') {
                $cancelled[(int) $row['security_id']][] = ['type' => (string) $row['offer_type'], 'date' => (string) $row['execution_date_planned']];
            }
        }

        // --- Что должно быть в таблице по данным биржи -----------------------
        $skipped = ['не наша бумага' => 0, 'бумага не торгуется' => 0, 'погашение по сроку, не оферта' => 0, 'не предстоящая (пометка биржи)' => 0,
            'без даты' => 0, 'отменена НРД' => 0];
        /** @var array<string, array{security_id: int, issuer_id: int, type: string, date: string, period_start: ?string, period_end: ?string, price: ?string}> $desired */
        $desired = [];
        /** @var array<string, true> $redemptions «бумага|дата» строк биржи, которые оказались погашением по сроку */
        $redemptions = [];
        foreach ($exchangeRows as $row) {
            $isin = trim((string) ($row['isin'] ?? ''));
            $security = $tradable[$isin] ?? null;
            if ($security === null) {
                $skipped[isset($knownIsins[$isin]) ? 'бумага не торгуется' : 'не наша бумага']++;
                continue;
            }
            $date = self::nullableDate($row['offerdate'] ?? null);
            if ($date === null || $date < $today) {
                $skipped['без даты']++;
                continue;
            }
            $label = trim((string) ($row['offertype'] ?? ''));
            if ($label === self::PUT_LABEL) {
                $type = 'put';
            } elseif ($label === self::CALL_LABEL) {
                if (!SecurityStatus::isEarly($date, $security['maturity_date'] !== null ? (string) $security['maturity_date'] : null)) {
                    $skipped['погашение по сроку, не оферта']++;
                    $redemptions[(int) $security['id'] . '|' . $date] = true;
                    continue;
                }
                $type = 'call';
            } else {
                $skipped['не предстоящая (пометка биржи)']++;
                continue;
            }
            $securityId = (int) $security['id'];
            if (self::nearCancelled($cancelled[$securityId] ?? [], $type, $date)) {
                $skipped['отменена НРД']++;
                continue;
            }

            $key = "{$securityId}|{$date}|{$type}";
            $desired[$key] = [
                'security_id' => $securityId,
                'issuer_id' => (int) $security['issuer_id'],
                'type' => $type,
                'date' => $date,
                // Две строки биржи с одной датой и видом — одна оферта: берём заполненное.
                'period_start' => self::nullableDate($row['offerdatestart'] ?? null) ?? ($desired[$key]['period_start'] ?? null),
                'period_end' => self::nullableDate($row['offerdateend'] ?? null) ?? ($desired[$key]['period_end'] ?? null),
                'price' => self::price($row['price'] ?? null) ?? ($desired[$key]['price'] ?? null),
            ];
        }

        // --- Сравнение с тем, что есть ----------------------------------------
        $report = ['market' => count($market), 'exchange_rows' => count($exchangeRows), 'kept' => 0, 'skipped' => $skipped, 'before' => count($existing), 'after' => 0,
            'updated' => 0, 'added' => [], 'removed' => [], 'flag_before' => 0, 'flag_after' => 0, 'flag_on' => [], 'flag_off' => [],
            'by_type' => ['put' => 0, 'call' => 0, 'unknown' => 0], 'bonds_with_offers' => 0];
        $deleteIds = [];
        $updates = [];
        /** @var array<int, true> $withOffer бумаги, у которых останется предстоящая неотменённая оферта */
        $withOffer = [];
        $seen = [];

        foreach ($existing as $row) {
            $securityId = (int) $row['security_id'];
            $date = (string) $row['execution_date_planned'];
            $type = (string) $row['offer_type'];
            $key = "{$securityId}|{$date}|{$type}";
            $item = self::item($byId[$securityId] ?? null, $date, $type);

            $reason = match (true) {
                $date === '' || $date < $today => 'дата прошла',
                !isset($tradableIds[$securityId]) => 'бумага не торгуется',
                $row['status'] !== 'cancelled' && $row['source'] === 'moex' && !isset($desired[$key])
                    => isset($redemptions["{$securityId}|{$date}"]) ? 'это погашение по сроку, не оферта' : 'в таблице биржи больше нет',
                default => null,
            };
            if ($reason !== null) {
                $deleteIds[] = (int) $row['id'];
                $report['removed'][] = $item + ['reason' => $reason];
                continue;
            }

            $seen[$key] = true;
            $report['kept']++;
            if ($row['status'] === 'cancelled') {
                continue;
            }
            $withOffer[$securityId] = true;
            $report['by_type'][$type] = ($report['by_type'][$type] ?? 0) + 1;

            $new = ['status' => 'announced'];
            if (isset($desired[$key])) {
                // Строка биржи повторяет биржу; строку из другого источника биржа только дополняет.
                $own = $row['source'] === 'moex';
                $new['period_start'] = $own ? $desired[$key]['period_start'] : ($row['period_start'] ?? $desired[$key]['period_start']);
                $new['period_end'] = $own ? $desired[$key]['period_end'] : ($row['period_end'] ?? $desired[$key]['period_end']);
                $new['price_percent'] = $own ? $desired[$key]['price'] : ($row['price_percent'] ?? $desired[$key]['price']);
            }
            $changed = [];
            foreach ($new as $column => $value) {
                $old = $row[$column] ?? null;
                $same = $column === 'price_percent'
                    ? ($old === null) === ($value === null) && ($old === null || abs((float) $old - (float) $value) < 0.0005)
                    : ($old === null ? null : (string) $old) === $value;
                if (!$same) {
                    $changed[$column] = $value;
                }
            }
            if ($changed !== []) {
                $updates[(int) $row['id']] = $changed;
                $report['updated']++;
            }
        }

        $inserts = [];
        foreach ($desired as $key => $offer) {
            if (isset($seen[$key])) {
                continue;
            }
            $inserts[] = $offer;
            $withOffer[$offer['security_id']] = true;
            $report['by_type'][$offer['type']]++;
            $report['added'][] = self::item($byId[$offer['security_id']], $offer['date'], $offer['type']);
        }
        $report['after'] = $report['kept'] + count($inserts);
        $report['bonds_with_offers'] = count($withOffer);

        // --- Признак оферты -----------------------------------------------------
        $flagOn = [];
        $flagOff = [];
        foreach ($securities as $security) {
            $id = (int) $security['id'];
            $has = (int) $security['has_offer'] === 1;
            $report['flag_before'] += $has ? 1 : 0;
            $should = isset($withOffer[$id]);
            $report['flag_after'] += $should ? 1 : 0;
            if ($should && !$has) {
                $flagOn[] = $id;
                $report['flag_on'][] = ['isin' => (string) $security['isin'], 'name' => (string) $security['short_name']];
            } elseif (!$should && $has) {
                $flagOff[] = $id;
                $report['flag_off'][] = ['isin' => (string) $security['isin'], 'name' => (string) $security['short_name']];
            }
        }

        if ($apply) {
            $this->apply($deleteIds, $updates, $inserts, $flagOn, $flagOff);
        }

        return $report;
    }

    /**
     * @param array<int, int> $deleteIds
     * @param array<int, array<string, ?string>> $updates id строки → изменившиеся колонки
     * @param array<int, array{security_id: int, issuer_id: int, type: string, date: string, period_start: ?string, period_end: ?string, price: ?string}> $inserts
     * @param array<int, int> $flagOn
     * @param array<int, int> $flagOff
     */
    private function apply(array $deleteIds, array $updates, array $inserts, array $flagOn, array $flagOff): void
    {
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare('DELETE FROM offers WHERE id = :id');
            foreach ($deleteIds as $id) {
                $delete->execute(['id' => $id]);
            }
            foreach ($updates as $id => $columns) {
                $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($columns)));
                $this->db->prepare("UPDATE offers SET {$set} WHERE id = :id")->execute($columns + ['id' => $id]);
            }
            $insert = $this->db->prepare(
                "INSERT INTO offers (security_id, issuer_id, offer_type, source, execution_date_planned, period_start, period_end, price_percent, status)
                 VALUES (:security_id, :issuer_id, :type, 'moex', :date, :period_start, :period_end, :price, 'announced')"
            );
            foreach ($inserts as $offer) {
                $insert->execute($offer);
            }
            $flag = $this->db->prepare('UPDATE securities SET has_offer = :value WHERE id = :id');
            foreach ($flagOn as $id) {
                $flag->execute(['value' => 1, 'id' => $id]);
            }
            foreach ($flagOff as $id) {
                $flag->execute(['value' => 0, 'id' => $id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Все предстоящие оферты рынка. Листание — по счётчику биржи (TOTAL);
     * неполный ответ — ошибка, а не «оферт стало меньше».
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchUpcoming(string $today): array
    {
        $rows = [];
        $total = 0;
        $previous = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->iss->getJson('/statistics/engines/stock/markets/bonds/bondization.json', [
                'iss.only' => 'offers,offers.cursor',
                'from' => $today,
                'till' => '2100-01-01',
                'limit' => 100,
                'start' => count($rows),
            ]);
            $pageRows = IssClient::block($response, 'offers');
            $total = (int) (IssClient::block($response, 'offers.cursor')[0]['TOTAL'] ?? 0);
            if ($pageRows === [] || $pageRows === $previous) {
                break; // пустая страница или та же самая — дальше листать нечего
            }
            $previous = $pageRows;
            array_push($rows, ...$pageRows);
            if (count($rows) >= $total) {
                break;
            }
        }

        if (count($rows) !== $total || $total < self::MIN_OFFERS) {
            throw new RuntimeException(sprintf(
                'Список оферт биржи выглядит неполным: получено %d строк, биржа обещала %d (ожидается не меньше %d). Оферты не менялись.',
                count($rows), $total, self::MIN_OFFERS,
            ));
        }

        return $rows;
    }

    /**
     * Есть ли отмена НРД того же вида рядом с этой датой.
     *
     * @param array<int, array{type: string, date: string}> $cancelled
     */
    public static function nearCancelled(array $cancelled, string $type, string $date): bool
    {
        foreach ($cancelled as $mark) {
            if ($mark['type'] === $type && abs(strtotime($mark['date']) - strtotime($date)) <= self::CANCEL_WINDOW_DAYS * 86400) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ?array<string, mixed> $security
     * @return array<string, string>
     */
    private static function item(?array $security, string $date, string $type): array
    {
        return ['isin' => (string) ($security['isin'] ?? '?'), 'name' => (string) ($security['short_name'] ?? '?'), 'date' => $date, 'type' => $type];
    }

    private static function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            return null;
        }

        return (string) $value;
    }

    /** Цена оферты в процентах номинала — в том виде, как хранит колонка DECIMAL(6,3). */
    private static function price(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value) || (float) $value <= 0 || (float) $value >= 1000) {
            return null;
        }

        return number_format((float) $value, 3, '.', '');
    }
}
