<?php

declare(strict_types=1);

/**
 * Офлайн-проверка оферт (переписано 11.10.2026 вместе с OffersImporter,
 * docs/OFFERS.md):
 *   1. OffersImporter::rebuild() — таблица offers и признак
 *      securities.has_offer приводятся к спискам биржи. Биржа — двойник,
 *      база — SQLite в памяти (запросы импортёра переносимые).
 *   2. OfferNotice / OfferNoticeProcessor — объявление НРД о досрочном
 *      погашении и отмена оферты, на НАСТОЯЩИХ сообщениях GetNews
 *      (tests/fixtures/getnews_offer_messages.json, выгрузка тестового
 *      доступа 18–30.09.2026): АльфаБО-21, СовкмФ 2Р5, ЧеркизБ2P2,
 *      ВЭБ2Р-К754, ТБ-3_01.
 *
 * Запуск (из корня репозитория):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_offers_importer.php
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Iss\IssClient;
use BondKeeper\Iss\OffersImporter;
use BondKeeper\Payments\OfferNotice;
use BondKeeper\Payments\OfferNoticeProcessor;

$failures = 0;
$checks = 0;

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected === $actual) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n       ожидалось: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n       получено:  " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

/** Биржа-двойник: список торгуемых бумаг и общий список предстоящих оферт, страницами по 100, как у настоящей. */
final class FakeIss extends IssClient
{
    /** @var array<int, string> ISIN торгуемых бумаг */
    public array $market = [];
    /** @var array<int, array<string, mixed>> строки списка оферт */
    public array $offers = [];
    /** Сколько строк «обещает» счётчик биржи; null — столько, сколько есть. */
    public ?int $total = null;
    /** Биржа не учитывает start и каждый раз отдаёт первую страницу. */
    public bool $ignoreStart = false;
    public int $offerRequests = 0;

    public function getJson(string $path, array $query = []): array
    {
        if ($path === '/engines/stock/markets/bonds/securities.json') {
            return ['securities' => ['columns' => ['SECID', 'BOARDID', 'ISIN', 'STATUS'],
                'data' => array_map(static fn (string $isin): array => [$isin, 'TQCB', $isin, 'A'], $this->market)]];
        }
        if ($path === '/statistics/engines/stock/markets/bonds/bondization.json') {
            if (++$this->offerRequests > 200) {
                throw new RuntimeException('список оферт запрашивается по кругу');
            }
            $columns = ['isin', 'offerdate', 'offerdatestart', 'offerdateend', 'price', 'offertype'];
            $page = array_slice($this->offers, $this->ignoreStart ? 0 : (int) $query['start'], (int) $query['limit']);

            return [
                'offers' => ['columns' => $columns, 'data' => array_map(static fn (array $r): array => array_map(static fn (string $c) => $r[$c] ?? null, $columns), $page)],
                'offers.cursor' => ['columns' => ['INDEX', 'TOTAL', 'PAGESIZE'], 'data' => [[(int) $query['start'], $this->total ?? count($this->offers), 100]]],
            ];
        }
        throw new RuntimeException("неожиданный запрос к бирже: {$path}");
    }
}

function makeDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("CREATE TABLE securities (id INTEGER PRIMARY KEY, isin TEXT, issuer_id INTEGER DEFAULT 1, short_name TEXT, maturity_date TEXT,
        status TEXT DEFAULT 'active', has_offer INTEGER DEFAULT 0, is_mortgage_backed INTEGER DEFAULT 0)");
    // Таблица оферт — как после миграции 030.
    $db->exec("CREATE TABLE offers (id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER NOT NULL, issuer_id INTEGER NOT NULL, offer_type TEXT NOT NULL DEFAULT 'unknown',
        source TEXT NOT NULL DEFAULT 'moex', source_ref TEXT, announcement_date TEXT, period_start TEXT, period_end TEXT, execution_date_planned TEXT NOT NULL,
        price_percent TEXT, status TEXT NOT NULL DEFAULT 'unknown', UNIQUE (security_id, execution_date_planned, offer_type))");

    return $db;
}

/** @return array<int, string> оферты бумаги в виде «вид дата статус источник» */
function offersOf(PDO $db, int $securityId): array
{
    $stmt = $db->prepare("SELECT offer_type || ' ' || execution_date_planned || ' ' || status || ' ' || source FROM offers WHERE security_id = :id ORDER BY execution_date_planned, offer_type");
    $stmt->execute(['id' => $securityId]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function flagOf(PDO $db, int $securityId): int
{
    return (int) $db->query("SELECT has_offer FROM securities WHERE id = {$securityId}")->fetchColumn();
}

// =====================================================================
echo "--- загрузка с биржи: что считается офертой ---\n";
$db = makeDb();
$bonds = [
    // id => [ISIN, название, срок, статус, has_offer, ипотечная]
    1 => ['RU0000000001', 'Два пута', '2030-06-01', 'active', 1, 0],
    2 => ['RU0000000002', 'Погашение как оферта', '2026-12-22', 'active', 0, 0],
    3 => ['RU0000000003', 'Колл раньше срока', '2030-10-11', 'active', 1, 0],
    4 => ['RU0000000004', 'Ушла с биржи', '2028-03-17', 'active', 1, 0],
    5 => ['RU0000000005', 'Погашена', '2026-09-01', 'matured', 0, 0],
    6 => ['RU0000000006', 'Оферта прошла', '2029-01-01', 'active', 1, 0],
    7 => ['RU0000000007', 'Ипотечная', '2050-01-01', 'active', 1, 1],
    8 => ['RU0000000008', 'Пут отменён НРД', '2032-10-13', 'active', 0, 0],
    9 => ['RU0000000009', 'Колл объявлен НРД', '2031-01-01', 'active', 1, 0],
    10 => ['RU0000000010', 'Бессрочная', null, 'active', 0, 0],
    11 => ['RU0000000011', 'Дополняется', '2030-01-01', 'active', 1, 0],
    12 => ['RU0000000012', 'В техдефолте', '2029-07-27', 'tech_default', 0, 0],
];
$insert = $db->prepare('INSERT INTO securities (id, isin, short_name, maturity_date, status, has_offer, is_mortgage_backed) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ($bonds as $id => $bond) {
    $insert->execute([$id, ...$bond]);
}
$existing = [
    // бумага, вид, дата, статус, источник, номер действия НРД
    [2, 'unknown', '2026-12-22', 'unknown', 'moex', null],   // старая строка «погашение как оферта»
    [3, 'put', '2026-11-20', 'unknown', 'moex', null],        // оферты у биржи больше нет
    [4, 'put', '2026-11-17', 'unknown', 'moex', null],        // бумага ушла, а пут у биржи остался
    [6, 'put', '2026-09-01', 'unknown', 'moex', null],        // прошла
    [8, 'put', '2026-10-13', 'cancelled', 'nsd', '847172'],   // отмена НРД
    [9, 'call', '2026-10-20', 'announced', 'nsd', '1221427'], // объявлено НРД, у биржи нет
    [11, 'put', '2027-03-01', 'unknown', 'moex', null],       // та же оферта, без периода и цены
];
$insert = $db->prepare('INSERT INTO offers (security_id, issuer_id, offer_type, execution_date_planned, status, source, source_ref) VALUES (?, 1, ?, ?, ?, ?, ?)');
foreach ($existing as $row) {
    $insert->execute($row);
}

$iss = new FakeIss();
$iss->market = array_merge(
    array_map(static fn (array $b): string => $b[0], array_diff_key($bonds, [4 => true])), // бумаги 4 в списке торгуемых нет
    array_map(static fn (int $i): string => sprintf('XX%010d', $i), range(1, 1200)),
);
$offer = static fn (string $isin, string $date, string $type, ?string $start = null, ?string $end = null, mixed $price = 100): array
    => ['isin' => $isin, 'offerdate' => $date, 'offerdatestart' => $start, 'offerdateend' => $end, 'price' => $price, 'offertype' => $type];
$iss->offers = [
    $offer('RU0000000001', '2027-03-01', 'Оферта', '2027-02-15', '2027-02-19'),
    $offer('RU0000000001', '2028-03-01', 'Оферта'),
    $offer('RU0000000002', '2026-12-22', 'Оферта/Погашение'),
    $offer('RU0000000003', '2026-10-16', 'Оферта/Погашение'),
    $offer('RU0000000003', '2026-10-21', 'Оферта (отменено)'),
    $offer('RU0000000004', '2026-11-17', 'Оферта'),
    $offer('RU0000000005', '2026-12-01', 'Оферта'),
    $offer('RU0000000008', '2026-10-15', 'Оферта'),                       // у биржи отменённый пут ещё висит, и дата у неё другая
    $offer('RU0000000008', '2027-10-13', 'Оферта'),                       // следующий пут через год — не отменён
    $offer('RU0000000010', '2027-05-05', 'Оферта/Погашение', null, null, null),
    $offer('RU0000000011', '2027-03-01', 'Оферта', '2027-02-15', '2027-02-19', 99.5),
    $offer('RU0000000012', '2027-07-27', 'Оферта'),
    $offer('RU0000000001', '0000-00-00', 'Оферта/Погашение'),             // признак без даты
];
foreach (range(1, 130) as $i) { // чужие бумаги — чтобы список был на две страницы, как настоящий
    $iss->offers[] = $offer(sprintf('XX%010d', $i), '2027-01-15', 'Оферта');
}

$importer = new OffersImporter($iss, $db);
$before = $db->query('SELECT * FROM offers ORDER BY id')->fetchAll();
$dry = $importer->rebuild('2026-10-11', false);
check('просмотр ничего не меняет', $before, $db->query('SELECT * FROM offers ORDER BY id')->fetchAll());
check('просмотр показывает то же, что сделает запуск', [6, 1, 4], [count($dry['added']), $dry['updated'], count($dry['removed'])]);

$iss->offerRequests = 0;
$report = $importer->rebuild('2026-10-11', true);
check('список оферт прочитан постранично: 143 строки — два запроса', [143, 2], [$report['exchange_rows'], $iss->offerRequests]);
check('«Оферта» → put; у бумаги сохраняются обе оферты, а не только ближайшая', ['put 2027-03-01 announced moex', 'put 2028-03-01 announced moex'], offersOf($db, 1));
check('период предъявления и цена — из таблицы биржи', ['2027-02-15', '2027-02-19', '100.000'],
    array_values($db->query("SELECT period_start, period_end, price_percent FROM offers WHERE security_id = 1 AND execution_date_planned = '2027-03-01'")->fetch()));
check('«Оферта/Погашение» на дату срока — погашение, не оферта: старая строка удалена, признака нет', [[], 0], [offersOf($db, 2), flagOf($db, 2)]);
check('«Оферта/Погашение» раньше срока → call; исчезнувший пут удалён, «отменено» не берётся', ['call 2026-10-16 announced moex'], offersOf($db, 3));
check('бумаги нет в списке торгуемых — оферты удалены и признак снят, хотя пут у биржи ещё есть', [[], 0], [offersOf($db, 4), flagOf($db, 4)]);
check('погашенная бумага оферт не получает', [], offersOf($db, 5));
check('прошедшая оферта удалена, признак снят', [[], 0], [offersOf($db, 6), flagOf($db, 6)]);
check('ипотечная без оферты у биржи: признак снят', 0, flagOf($db, 7));
check('оферта, отменённая НРД, с биржи заново не загружается (дата у биржи отличается на 2 дня); пут через год — загружается',
    ['put 2026-10-13 cancelled nsd', 'put 2027-10-13 announced moex'], offersOf($db, 8));
check('досрочное погашение, объявленное НРД, остаётся, хотя у биржи его нет', [['call 2026-10-20 announced nsd'], 1], [offersOf($db, 9), flagOf($db, 9)]);
check('бессрочная бумага: «Оферта/Погашение» с датой — call; цены у биржи нет', [['call 2027-05-05 announced moex'], null],
    [offersOf($db, 10), $db->query('SELECT price_percent FROM offers WHERE security_id = 10')->fetchColumn()]);
check('существующая строка дополнена периодом и ценой, статус — «предстоит»', ['put', 'announced', '2027-02-15', '2027-02-19', '99.500'],
    array_values($db->query('SELECT offer_type, status, period_start, period_end, price_percent FROM offers WHERE security_id = 11')->fetch()));
check('бумага в техдефолте остаётся в обращении: оферта есть, признак стоит', [['put 2027-07-27 announced moex'], 1], [offersOf($db, 12), flagOf($db, 12)]);
check('признак оферты: только у торгуемых бумаг с предстоящей неотменённой офертой', [1, 3, 8, 9, 10, 11, 12],
    array_map('intval', $db->query('SELECT id FROM securities WHERE has_offer = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
check('отчёт: сколько строк не взято и почему', ['не наша бумага' => 130, 'бумага не торгуется' => 2, 'погашение по сроку, не оферта' => 1,
    'не предстоящая (пометка биржи)' => 1, 'без даты' => 1, 'отменена НРД' => 1], $report['skipped']);
check('отчёт: причины удаления', ['это погашение по сроку, не оферта' => 1, 'в таблице биржи больше нет' => 1, 'бумага не торгуется' => 1, 'дата прошла' => 1],
    array_count_values(array_column($report['removed'], 'reason')));
check('отчёт: итог', [7, 9, 6, 1, 7, 7], [$report['before'], $report['after'], count($report['added']), $report['updated'], $report['flag_before'], $report['flag_after']]);

$again = $importer->rebuild('2026-10-11', true);
check('повторный запуск ничего не меняет', [0, 0, 0, 0, 0], [count($again['added']), $again['updated'], count($again['removed']), count($again['flag_on']), count($again['flag_off'])]);

// Через неделю колл третьей бумаги и отмена восьмой остались в прошлом; биржа отдаёт только будущее.
$iss->offers = array_values(array_filter($iss->offers, static fn (array $r): bool => $r['offerdate'] >= '2026-10-25' || $r['offerdate'] === '0000-00-00'));
$later = $importer->rebuild('2026-10-25', true);
check('дата прошла — строка удаляется: и колл с биржи, и объявленный НРД, и отметка об отмене', [[], [], ['put 2027-10-13 announced moex']],
    [offersOf($db, 3), offersOf($db, 9), offersOf($db, 8)]);
check('у бумаг без оставшихся оферт признак снят', [0, 0, 1], [flagOf($db, 3), flagOf($db, 9), flagOf($db, 8)]);

echo "\n--- защита от неполного ответа биржи ---\n";
$snapshot = [$db->query('SELECT * FROM offers ORDER BY id')->fetchAll(), $db->query('SELECT id, has_offer FROM securities ORDER BY id')->fetchAll()];
$failed = static function (callable $setup) use ($iss, $db): string {
    $broken = clone $iss;
    $setup($broken);
    try {
        (new OffersImporter($broken, $db))->rebuild('2026-10-25', true);
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return '';
};
check('биржа обещала 300 оферт, а отдала меньше — ошибка', true, str_contains($failed(static function (FakeIss $b): void { $b->total = 300; }), 'Список оферт биржи выглядит неполным'));
check('биржа отдаёт одну и ту же страницу при любом start — ошибка, а не бесконечный опрос', true,
    str_contains($failed(static function (FakeIss $b): void { $b->ignoreStart = true; }), 'Список оферт биржи выглядит неполным'));
check('в списке оферт всего 30 строк — ошибка', true, str_contains($failed(static function (FakeIss $b): void { $b->offers = array_slice($b->offers, 0, 30); }), 'выглядит неполным'));
check('в списке торгуемых бумаг всего 500 — ошибка', true, str_contains($failed(static function (FakeIss $b): void { $b->market = array_slice($b->market, 0, 500); }), 'Список биржи выглядит неполным'));
check('в списке торгуемых нет большинства наших бумаг — ошибка', true,
    str_contains($failed(static function (FakeIss $b): void { $b->market = array_slice($b->market, 11); }), 'Список биржи выглядит неполным'));
check('после всех ошибок в базе ничего не изменилось', $snapshot, [$db->query('SELECT * FROM offers ORDER BY id')->fetchAll(), $db->query('SELECT id, has_offer FROM securities ORDER BY id')->fetchAll()]);

$old = new PDO('sqlite::memory:');
$old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$old->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY, security_id INTEGER, has_buyback_date INTEGER)');
check('таблица без миграции 030 — понятное сообщение с именем миграции; с миграцией — замечаний нет', [true, null],
    [str_contains((string) OffersImporter::schemaProblem($old), '030_offers_multiple_rows.sql'), OffersImporter::schemaProblem($db)]);

// =====================================================================
echo "\n--- сообщения НРД: какие читаем ---\n";
/** @var array<string, array<string, mixed>> $fixtures */
$fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures/getnews_offer_messages.json'), true, 512, JSON_THROW_ON_ERROR);
$notice = static fn (string $label): ?OfferNotice => OfferNotice::fromRaw($fixtures[$label]);
$fields = static fn (?OfferNotice $n): ?array => $n === null ? null : [$n->isin, $n->type, $n->action, $n->date, $n->messageDate, $n->actionRef, $n->percent];

check('АльфаБО-21: объявление о досрочном погашении на 09.10.2026', ['RU000A0ZYBM4', 'call', 'announced', '2026-10-09', '2026-09-23', '1221427', '100.000'], $fields($notice('alfa_call_announced')));
check('АльфаБО-21: отмена пута (дата действия у НРД — 13.10.2026)', ['RU000A0ZYBM4', 'put', 'cancelled', '2026-10-13', '2026-09-23', '847172', '100.000'], $fields($notice('alfa_put_cancelled')));
check('у отмены пута есть период предъявления', ['2026-10-05', '2026-10-09'], [$notice('alfa_put_cancelled')->periodStart, $notice('alfa_put_cancelled')->periodEnd]);
check('ТБ-3_01: досрочно гасится остаток номинала — 9,877 %', ['call', 'announced', '2026-09-28', '9.877'],
    array_slice($fields($notice('tb3_call_remaining_nominal')), 1, 3) + [3 => $notice('tb3_call_remaining_nominal')->percent]);
check('объявление НРД о самой оферте (BPUT) не разбирается', null, $notice('put_announcement_not_processed'));
check('сообщение о деньгах по досрочному погашению — не объявление', null, $notice('call_money_message'));
check('купон — не про оферты', null, OfferNotice::fromRaw(['ca_type' => 'INTR', 'title_ru' => '(INTR) О корпоративном действии', 'data' => []]));

echo "\n--- сообщения НРД: что меняется в базе ---\n";
$db = makeDb();
$real = [
    21 => ['RU000A0ZYBM4', 'АльфаБО-21', '2032-10-13'],
    22 => ['RU000A10D4H7', 'СовкмФ 2Р5', '2030-10-11'],
    23 => ['RU000A10C5F9', 'ЧеркизБ2P2', '2027-07-12'],
    24 => ['RU000A10FS64', 'ВЭБ2Р-К754', '2026-10-15'],
    25 => ['RU000A10B7T7', 'ТБ-3_01', '2030-03-26'],
];
$insert = $db->prepare('INSERT INTO securities (id, isin, short_name, maturity_date, has_offer) VALUES (?, ?, ?, ?, ?)');
foreach ($real as $id => [$isin, $name, $maturity]) {
    $insert->execute([$id, $isin, $name, $maturity, in_array($id, [21, 22], true) ? 1 : 0]);
}
// Как это выглядело в базе по данным биржи накануне сообщений НРД.
$db->exec("INSERT INTO offers (security_id, issuer_id, offer_type, execution_date_planned, status, source) VALUES
    (21, 1, 'put', '2026-10-09', 'announced', 'moex'),
    (22, 1, 'call', '2026-10-16', 'announced', 'moex'),
    (22, 1, 'put', '2026-10-21', 'announced', 'moex')");
$processor = new OfferNoticeProcessor($db);
$run = static fn (string $label, string $today, bool $apply = true): string => $processor->process(OfferNotice::fromRaw($fixtures[$label]), $today, $apply)['result'];

check('просмотр (без записи) говорит, что отмена применилась бы, и ничего не меняет', ['applied', ['put 2026-10-09 announced moex']],
    [$run('alfa_put_cancelled', '2026-09-23', false), offersOf($db, 21)]);
check('АльфаБО-21, 23.09 14:56 — НРД отменил пут', 'applied', $run('alfa_put_cancelled', '2026-09-23'));
check('пут из таблицы биржи (09.10) погашен отменой НРД (13.10): осталась отметка об отмене, признак снят',
    [['put 2026-10-13 cancelled nsd'], 0], [offersOf($db, 21), flagOf($db, 21)]);
check('АльфаБО-21, 23.09 16:37 — НРД объявил досрочное погашение на 09.10', 'applied', $run('alfa_call_announced', '2026-09-23'));
check('появился колл с датой, ценой, днём объявления и номером действия НРД; признак снова стоит',
    [['call 2026-10-09 announced nsd', 'put 2026-10-13 cancelled nsd'], ['100.000', '2026-09-23', '1221427'], 1],
    [offersOf($db, 21), array_values($db->query("SELECT price_percent, announcement_date, source_ref FROM offers WHERE security_id = 21 AND offer_type = 'call'")->fetch()), flagOf($db, 21)]);
check('опрос перечитывает ленту: те же сообщения второй раз ничего не меняют', ['no_change', 'no_change', 2],
    [$run('alfa_put_cancelled', '2026-09-24'), $run('alfa_call_announced', '2026-09-24'), (int) $db->query('SELECT COUNT(*) FROM offers WHERE security_id = 21')->fetchColumn()]);

// Ночная загрузка: у биржи отменённый пут ещё в таблице, а колла нет.
$iss = new FakeIss();
$iss->market = array_merge(array_column($real, 0), array_map(static fn (int $i): string => sprintf('XX%010d', $i), range(1, 1200)));
$iss->offers = [$offer('RU000A0ZYBM4', '2026-10-09', 'Оферта'), $offer('RU000A10D4H7', '2026-10-16', 'Оферта/Погашение'), $offer('RU000A10D4H7', '2026-10-21', 'Оферта')];
foreach (range(1, 130) as $i) {
    $iss->offers[] = $offer(sprintf('XX%010d', $i), '2027-01-15', 'Оферта');
}
(new OffersImporter($iss, $db))->rebuild('2026-09-24', true);
check('ночная загрузка с биржи не возвращает отменённый пут и не трогает колл от НРД',
    [['call 2026-10-09 announced nsd', 'put 2026-10-13 cancelled nsd'], 1], [offersOf($db, 21), flagOf($db, 21)]);

check('СовкмФ 2Р5, 28.09 15:57 — отмена пута на 21.10: строка биржи с той же датой помечена отменённой', ['applied', ['call 2026-10-16 announced moex', 'put 2026-10-21 cancelled moex'], 1],
    [$run('sovcom_put_cancelled', '2026-09-28'), offersOf($db, 22), flagOf($db, 22)]);
check('СовкмФ 2Р5, 28.09 18:17 — объявление колла на 16.10, который уже есть от биржи: строка подтверждена, второй не появилось',
    ['applied', ['2026-09-28', '1223463', 'moex'], 2],
    [$run('sovcom_call_announced', '2026-09-28'), array_values($db->query("SELECT announcement_date, source_ref, source FROM offers WHERE security_id = 22 AND offer_type = 'call'")->fetch()),
        (int) $db->query('SELECT COUNT(*) FROM offers WHERE security_id = 22')->fetchColumn()]);
(new OffersImporter($iss, $db))->rebuild('2026-09-29', true);
check('после ночной загрузки пут остаётся отменённым, колл — подтверждённым', ['call 2026-10-16 announced moex', 'put 2026-10-21 cancelled moex'], offersOf($db, 22));

check('ЧеркизБ2P2, 29.09 — колл на 15.10, которого в базе не было', ['applied', ['call 2026-10-15 announced nsd'], 1],
    [$run('cherkiz_call_announced', '2026-09-29'), offersOf($db, 23), flagOf($db, 23)]);
check('ВЭБ2Р-К754: тем же кодом НРД оформил погашение в конце срока — это не оферта', ['ignored', [], 0],
    [$run('veb_redemption_at_maturity', '2026-09-24'), offersOf($db, 24), flagOf($db, 24)]);
check('ТБ-3_01: досрочно гасится остаток номинала — колл с долей 9,877 %', ['applied', '9.877'],
    [$run('tb3_call_remaining_nominal', '2026-09-25'), $db->query('SELECT price_percent FROM offers WHERE security_id = 25')->fetchColumn()]);
check('сообщение пришло, когда дата уже прошла (первая загрузка за прошлые дни) — пропускается', 'ignored', $run('cherkiz_call_announced', '2026-10-20'));
$db->exec("UPDATE securities SET status = 'matured' WHERE id = 25");
check('бумага вышла из обращения или её нет в базе — сообщение не наше', ['foreign', 'foreign'],
    [$run('tb3_call_remaining_nominal', '2026-09-25'), $processor->process(new OfferNotice('RU0000000999', 'call', 'announced', '2026-12-01', '2026-10-01', '1'), '2026-10-01')['result']]);

// Старая отмена не должна гасить оферту, объявленную позже другим корпоративным действием.
$db->exec("INSERT INTO offers (security_id, issuer_id, offer_type, execution_date_planned, status, source, source_ref) VALUES (21, 1, 'put', '2026-10-20', 'announced', 'nsd', '999001')");
check('повтор старой отмены не трогает оферту, объявленную позже другим действием НРД', ['no_change', true],
    [$run('alfa_put_cancelled', '2026-09-30'), in_array('put 2026-10-20 announced nsd', offersOf($db, 21), true)]);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
