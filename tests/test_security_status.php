<?php

declare(strict_types=1);

/**
 * Офлайн-проверка статуса бумаг (11.10.2026, docs/SECURITY_STATUS.md):
 *   - SecurityStatus — смена статуса, чистка оферт, статус по событиям выплат;
 *   - SecurityStatusSync — ежедневная сверка со списком биржи.
 * Биржа подменена двойником IssClient: в сеть ничего не уходит.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_security_status.php
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Iss\IssClient;
use BondKeeper\Iss\SecurityStatusSync;
use BondKeeper\Securities\SecurityStatus;

$failures = 0;
$checks = 0;

function check(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected === $actual) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n       ожидали: " . var_export($expected, true) . "\n       получили: " . var_export($actual, true) . "\n";
    }
}

/** Биржа-двойник: список торгуемых бумаг и таблицы оферт задаются в тесте. */
final class FakeIss extends IssClient
{
    /** @var array<int, array{0: string, 1: string, 2: string}> ISIN, доска, STATUS */
    public array $market = [];
    /** @var array<string, array<int, array{0: string, 1: string}>> ISIN → [дата, вид] */
    public array $offers = [];
    /** @var array<int, string> */
    public array $offerRequests = [];

    public function getJson(string $path, array $query = []): array
    {
        if ($path === '/engines/stock/markets/bonds/securities.json') {
            return ['securities' => ['columns' => ['SECID', 'BOARDID', 'ISIN', 'STATUS'],
                'data' => array_map(static fn (array $r): array => [$r[0], $r[1], $r[0], $r[2]], $this->market)]];
        }
        if (preg_match('~/bondization/([A-Z0-9]+)\.json$~', $path, $m) === 1) {
            $this->offerRequests[] = $m[1];
            $rows = $this->offers[$m[1]] ?? [];

            return ['offers' => ['columns' => ['isin', 'offerdate', 'offertype'], 'data' => array_map(static fn (array $r): array => [$m[1], $r[0], $r[1]], $rows)],
                'offers.cursor' => ['columns' => ['INDEX', 'TOTAL', 'PAGESIZE'], 'data' => [[0, count($rows), 100]]]];
        }
        throw new RuntimeException("неожиданный запрос к бирже: {$path}");
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE securities (id INTEGER PRIMARY KEY, isin TEXT, short_name TEXT, status TEXT DEFAULT 'active', status_reason TEXT, status_changed_at TEXT,
    ended_at TEXT, missing_since TEXT, maturity_date TEXT, last_synced_at TEXT, has_offer INTEGER DEFAULT 0)");
$db->exec('CREATE TABLE offers (id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, execution_date_planned TEXT, offer_type TEXT)');
foreach (['coupons', 'amortizations', 'redemptions'] as $table) {
    $db->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, status TEXT DEFAULT 'planned')");
}
$add = static function (int $id, string $isin, string $name, array $fields = []) use ($db): void {
    $row = $fields + ['status' => 'active', 'status_reason' => null, 'maturity_date' => '2030-01-15', 'last_synced_at' => '2026-10-12 03:00:00', 'has_offer' => 0, 'missing_since' => null];
    $db->prepare('INSERT INTO securities (id, isin, short_name, status, status_reason, maturity_date, last_synced_at, has_offer, missing_since)
                  VALUES (:id, :isin, :name, :status, :status_reason, :maturity_date, :last_synced_at, :has_offer, :missing_since)')
        ->execute(['id' => $id, 'isin' => $isin, 'name' => $name] + $row);
};
$get = static fn (int $id): array => $db->query("SELECT status, status_reason, ended_at, missing_since, has_offer FROM securities WHERE id = {$id}")->fetch();
$offersOf = static fn (int $id): int => (int) $db->query("SELECT COUNT(*) FROM offers WHERE security_id = {$id}")->fetchColumn();
$status = new SecurityStatus($db);

echo "--- смена статуса ---\n";
$add(1, 'RU0000000001', 'Обычная');
$db->exec("UPDATE securities SET has_offer = 1 WHERE id = 1");
$db->exec("INSERT INTO offers (security_id, execution_date_planned, offer_type) VALUES (1, '2027-03-01', 'put')");
check('техдефолт: статус и причина записаны, дата окончания пуста', [true, 'tech_default', 'payment_missed', null],
    [$status->set(1, SecurityStatus::TECH_DEFAULT, SecurityStatus::REASON_PAYMENT_MISSED, '2026-10-01'), $get(1)['status'], $get(1)['status_reason'], $get(1)['ended_at']]);
check('бумага осталась в обращении — оферта на месте', [1, 1], [(int) $get(1)['has_offer'], $offersOf(1)]);
check('повтор с тем же статусом и причиной ничего не меняет', false, $status->set(1, SecurityStatus::TECH_DEFAULT, SecurityStatus::REASON_PAYMENT_MISSED));
check('время смены статуса записано', true, $db->query('SELECT status_changed_at FROM securities WHERE id = 1')->fetchColumn() !== null);
check('погашена досрочно: статус, причина и дата окончания', ['matured', 'early_redemption_nsd', '2026-09-25'],
    [$status->set(1, SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-09-25') ? $get(1)['status'] : '', $get(1)['status_reason'], $get(1)['ended_at']]);
check('бумага вышла из обращения — признак оферты снят, строки оферт удалены', [0, 0], [(int) $get(1)['has_offer'], $offersOf(1)]);
check('«снята с торгов» можно уточнить до «погашена», если причина выяснилась', 'matured', (static function () use ($status, $get, $add): string {
    $add(2, 'RU0000000002', 'Уточняемая', ['status' => 'delisted', 'status_reason' => 'left_exchange_unknown']);
    $status->set(2, SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-09-30');

    return $get(2)['status'];
})());

echo "\n--- статус по событиям выплат ---\n";
$add(10, 'RU0000000010', 'Плательщик');
check('купон получен (A2) — статус не меняется', [null, 'active'], [$status->applyPaymentEvents(10, ['A2']), $get(10)['status']]);
check('часть суммы до срока (B1a) — не нарушение', null, $status->applyPaymentEvents(10, ['B1a']));
check('не выплачено в срок (B2) → техдефолт', ['tech_default', 'payment_missed'], [$status->applyPaymentEvents(10, ['B2']), $get(10)['status_reason']]);
check('повторное B2 статус не трогает', null, $status->applyPaymentEvents(10, ['B2']));
$db->exec("INSERT INTO coupons (security_id, status) VALUES (10, 'tech_default'), (10, 'paid')");
check('выплачено после просрочки (B5), но другой купон ещё не закрыт — остаётся техдефолт', [null, 'tech_default'], [$status->applyPaymentEvents(10, ['B5']), $get(10)['status']]);
$db->exec("UPDATE coupons SET status = 'paid' WHERE security_id = 10");
check('долгов не осталось — B5 возвращает в обращение', ['active', 'debt_closed'], [$status->applyPaymentEvents(10, ['B5']), $get(10)['status_reason']]);
check('выплачено частично после срока (B1) → техдефолт', 'tech_default', $status->applyPaymentEvents(10, ['B1']));
check('НРД объявил дефолт (C2) → дефолт', ['default', 'nsd_default'], [$status->applyPaymentEvents(10, ['C2']), $get(10)['status_reason']]);
check('после дефолта долг закрыт полностью (B5) → снова в обращении', 'active', $status->applyPaymentEvents(10, ['B5']));
$add(11, 'RU0000000011', 'В режиме Д', ['status' => 'tech_default', 'status_reason' => 'moex_board_d']);
check('техдефолт из-за режима «Д» выплатой не снимается — решает биржа', [null, 'tech_default'], [$status->applyPaymentEvents(11, ['B5']), $get(11)['status']]);
$add(12, 'RU0000000012', 'Погашенная', ['status' => 'matured', 'status_reason' => 'scheduled_maturity']);
check('у погашенной бумаги события выплат статус не меняют', [null, 'matured'], [$status->applyPaymentEvents(12, ['B2']), $get(12)['status']]);

echo "\n--- сверка со списком биржи ---\n";
$iss = new FakeIss();
for ($i = 1; $i <= 1200; $i++) { // остальной рынок — чтобы список биржи был правдоподобного размера
    $iss->market[] = [sprintf('XX%010d', $i), 'TQCB', 'A'];
}
$iss->market[] = ['RU0000000010', 'TQCB', 'A']; // бумаги из проверок выше остаются на бирже
$iss->market[] = ['RU0000000011', 'TQRD', 'A'];
for ($i = 100; $i < 200; $i++) { // наши обычные бумаги, которые на бирже есть
    $add($i, sprintf('XX%010d', $i), "Обычная {$i}");
}
$add(20, 'RU0000000020', 'Погашена по сроку', ['maturity_date' => '2026-10-09', 'last_synced_at' => '2026-10-09 03:00:00', 'has_offer' => 1]);
$db->exec("INSERT INTO offers (security_id, execution_date_planned, offer_type) VALUES (20, '2026-10-09', 'unknown')");
$add(21, 'RU0000000021', 'Досрочно, запись биржи', ['last_synced_at' => '2026-08-20 03:00:00']);
$iss->offers['RU0000000021'] = [['2025-08-20', 'Оферта'], ['2026-08-20', 'Оферта/Погашение']];
$add(22, 'RU0000000022', 'Ушла без причины', ['last_synced_at' => '2026-09-15 03:00:00', 'has_offer' => 1]);
$iss->offers['RU0000000022'] = [['0000-00-00', 'Оферта/Погашение'], ['2026-09-18', 'Оферта']];
$add(23, 'RU0000000023', 'Пропала сегодня', ['last_synced_at' => '2026-10-11 03:00:00', 'has_offer' => 1]);
$db->exec("INSERT INTO offers (security_id, execution_date_planned, offer_type) VALUES (23, '2026-11-17', 'put')");
$add(24, 'RU0000000024', 'В режиме Д');
$iss->market[] = ['RU0000000024', 'TQRD', 'A'];
$add(25, 'RU0000000025', 'Вышла из режима Д', ['status' => 'tech_default', 'status_reason' => 'moex_board_d']);
$iss->market[] = ['RU0000000025', 'TQCB', 'A'];
$add(26, 'RU0000000026', 'Техдефолт по выплате', ['status' => 'tech_default', 'status_reason' => 'payment_missed']);
$iss->market[] = ['RU0000000026', 'TQCB', 'A'];
$add(27, 'RU0000000027', 'Вернулась', ['status' => 'delisted', 'status_reason' => 'left_exchange_unknown', 'missing_since' => '2026-09-01']);
$iss->market[] = ['RU0000000027', 'TQCB', 'A'];
$add(28, 'RU0000000028', 'Погашена, но ещё в списке', ['status' => 'matured', 'status_reason' => 'scheduled_maturity']);
$iss->market[] = ['RU0000000028', 'TQCB', 'A'];
$add(29, 'RU0000000029', 'В списке со статусом N', ['maturity_date' => '2032-10-13', 'last_synced_at' => '2026-10-08 03:00:00']);
$iss->market[] = ['RU0000000029', 'TQCB', 'N'];
$iss->offers['RU0000000029'] = [['2026-10-09', 'Оферта/Погашение']];
$add(30, 'RU0000000030', 'Дефолтная, срок прошёл', ['status' => 'default', 'status_reason' => 'nsd_default', 'maturity_date' => '2026-09-01', 'last_synced_at' => '2026-09-20 03:00:00']);
$add(31, 'RU0000000031', 'Вернулась до смены статуса', ['missing_since' => '2026-10-11']);
$iss->market[] = ['RU0000000031', 'TQCB', 'A'];

$sync = new SecurityStatusSync($iss, $db, $status);
$before = $db->query('SELECT id, status, has_offer, missing_since FROM securities ORDER BY id')->fetchAll();
$dry = $sync->run('2026-10-12', false);
check('просмотр ничего не меняет в базе', $before, $db->query('SELECT id, status, has_offer, missing_since FROM securities ORDER BY id')->fetchAll());
check('просмотр показывает то же, что будет сделано', [1, 2, 2, 1, 1, 2],
    [count($dry['matured']), count($dry['early']), count($dry['delisted']), count($dry['gone_today']), count($dry['board_d']), count($dry['restored'])]);

$report = $sync->run('2026-10-12', true);
check('срок прошёл, в списке биржи нет → погашена по сроку, дата окончания — дата погашения', ['matured', 'scheduled_maturity', '2026-10-09'], array_slice(array_values($get(20)), 0, 3));
check('…оферта у неё убрана', [0, 0], [(int) $get(20)['has_offer'], $offersOf(20)]);
check('ушла до срока, у биржи «Оферта/Погашение» рядом с днём ухода → погашена досрочно, дата — из записи биржи', ['matured', 'early_redemption_moex', '2026-08-20'], array_slice(array_values($get(21)), 0, 3));
check('ушла до срока, записи нет (только признак без даты) → снята с торгов, дата — последний день в списке', ['delisted', 'left_exchange_unknown', '2026-09-15'], array_slice(array_values($get(22)), 0, 3));
check('пропала сегодня, срок не вышел → статус пока прежний, отмечен день пропажи', ['active', '2026-10-12'], [$get(23)['status'], $get(23)['missing_since']]);
check('…но признак оферты и строка оферты сняты в тот же день', [0, 0], [(int) $get(23)['has_offer'], $offersOf(23)]);
check('биржа перевела в режим «Д» → техдефолт', ['tech_default', 'moex_board_d'], array_slice(array_values($get(24)), 0, 2));
check('биржа вернула из режима «Д» → снова в обращении', ['active', 'left_board_d'], array_slice(array_values($get(25)), 0, 2));
check('техдефолт по невыплате на обычной доске остаётся техдефолтом', ['tech_default', 'payment_missed'], array_slice(array_values($get(26)), 0, 2));
check('снятая с торгов снова в списке → в обращении, отметка о пропаже снята', ['active', 'back_on_exchange', null], [$get(27)['status'], $get(27)['status_reason'], $get(27)['missing_since']]);
check('погашенная бумага в обращение не возвращается, даже если ещё висит в списке', 'matured', $get(28)['status']);
check('строка со статусом «N» торговлей не считается → бумага обработана как ушедшая (досрочно, запись биржи)', ['matured', 'early_redemption_moex', '2026-10-09'], array_slice(array_values($get(29)), 0, 3));
check('бумага в дефолте, срок прошёл, с биржи ушла — «погашена» не ставится, только «снята с торгов»', ['delisted', 'left_exchange_unknown'], array_slice(array_values($get(30)), 0, 2));
check('вернулась в список до смены статуса — отметка о пропаже снята, статус не трогали', ['active', null], [$get(31)['status'], $get(31)['missing_since']]);
check('обычные бумаги, которые на бирже есть, не затронуты', 100, (int) $db->query("SELECT COUNT(*) FROM securities WHERE id BETWEEN 100 AND 199 AND status = 'active' AND status_reason IS NULL")->fetchColumn());
check('запрос записей биржи — только по бумагам, ушедшим до срока и выждавшим отсрочку', ['RU0000000021', 'RU0000000022', 'RU0000000029', 'RU0000000030'], (static function () use ($iss): array {
    $asked = array_values(array_unique($iss->offerRequests));
    sort($asked);

    return $asked;
})());
check('в отчёте — все группы', [1, 2, 2, 1, 1, 2], [count($report['matured']), count($report['early']), count($report['delisted']), count($report['gone_today']), count($report['board_d']), count($report['restored'])]);

$next = $sync->run('2026-10-13', true);
check('на следующий день пропавшая бумага ждёт: в отчёте «ждут», статус прежний', [1, 0, 'active'], [count($next['waiting']), count($next['gone_today']), $get(23)['status']]);
check('повторная сверка ничего нового не меняет', [0, 0, 0, 0, 0], [count($next['matured']), count($next['early']), count($next['delisted']), count($next['board_d']), count($next['restored'])]);
$sync->run('2026-10-14', true);
check('через два дня — всё ещё ждёт', 'active', $get(23)['status']);
$sync->run('2026-10-15', true);
check('через три дня отсрочка вышла: записи биржи нет → снята с торгов, дата — последний день в списке', ['delisted', 'left_exchange_unknown', '2026-10-11'], array_slice(array_values($get(23)), 0, 3));

echo "\n--- защита от неполного списка биржи ---\n";
$snapshot = $db->query('SELECT id, status FROM securities ORDER BY id')->fetchAll();
$small = new FakeIss();
$small->market = array_slice($iss->market, 0, 500);
$error = '';
try {
    (new SecurityStatusSync($small, $db, $status))->run('2026-10-16', true);
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
check('список из 500 бумаг — сверка останавливается с ошибкой', true, str_contains($error, 'выглядит неполным'));
$half = new FakeIss();
for ($i = 5000; $i < 6500; $i++) { // список большой, но наших бумаг в нём почти нет
    $half->market[] = [sprintf('YY%010d', $i), 'TQCB', 'A'];
}
$error = '';
try {
    (new SecurityStatusSync($half, $db, $status))->run('2026-10-16', true);
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
check('в списке нет большинства наших бумаг — тоже ошибка', true, str_contains($error, 'выглядит неполным'));
check('после обеих ошибок в базе ничего не изменилось', $snapshot, $db->query('SELECT id, status FROM securities ORDER BY id')->fetchAll());

echo "\n--- импортёр бумаг: обходит все доски, включая режим «Д» и валютные ---\n";
/** Биржа-двойник для импортёра: отдаёт списки досок и запоминает, какие бумаги импортёр пошёл уточнять. */
final class BoardsIss extends IssClient
{
    /** @var array<string, array<int, string>> доска → ISIN */
    public array $boards = [];
    /** @var array<int, string> */
    public array $searched = [];
    /** @var array<string, int> доска → сколько раз запрошена */
    public array $boardRequests = [];

    public function getJson(string $path, array $query = []): array
    {
        if (preg_match('~/boards/([A-Z]+)/securities\.json$~', $path, $m) === 1) {
            // Как настоящая биржа: доска отдаётся целиком, параметр start не учитывается.
            $this->boardRequests[$m[1]] = ($this->boardRequests[$m[1]] ?? 0) + 1;
            if ($this->boardRequests[$m[1]] > 5) {
                throw new RuntimeException("доска {$m[1]} запрашивается по кругу");
            }

            return ['securities' => ['columns' => ['SECID', 'ISIN', 'SHORTNAME', 'STATUS'],
                'data' => array_map(static fn (string $isin): array => [$isin, $isin, $isin, 'A'], $this->boards[$m[1]] ?? [])]];
        }
        if ($path === '/securities.json') {
            $this->searched[] = (string) $query['q'];

            return ['securities' => ['columns' => ['isin'], 'data' => []]]; // «не найдено» — дальше импортёр бумагу пропускает
        }
        throw new RuntimeException("неожиданный запрос к бирже: {$path}");
    }
}
$db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY)');
$hundred = array_map(static fn (int $i): string => sprintf('QQ%010d', $i), range(1, 100));
$boards = new BoardsIss();
$boards->boards = ['TQCB' => ['RU0000000026', 'XX0000000150'], 'TQOB' => [], 'TQIR' => [], 'TQRD' => ['RU0000000024', 'RU0000000011', 'ZZ0000000999'],
    'TQOD' => ['RU0000000026', 'VV0000000001'], 'TQOY' => $hundred];
ob_start();
(new BondKeeper\Iss\SecuritiesImporter($boards, $db))->importMarket();
ob_end_clean();
$expected = array_merge(['RU0000000011', 'RU0000000024', 'RU0000000026', 'VV0000000001', 'XX0000000150', 'ZZ0000000999'], $hundred);
sort($expected);
sort($boards->searched);
check('берутся бумаги всех досок: основных, режима «Д» (и новые для базы), валютных; бумага с двух досок — один раз', $expected, $boards->searched);
check('доска ровно из 100 бумаг не запрашивается по кругу (биржа отдаёт её целиком при любом start)',
    ['TQCB' => 1, 'TQIR' => 1, 'TQOB' => 1, 'TQRD' => 1, 'TQOD' => 1, 'TQOY' => 2], $boards->boardRequests);

$only = new BoardsIss();
$only->boards = $boards->boards;
ob_start();
(new BondKeeper\Iss\SecuritiesImporter($only, $db))->importMarket(['TQRD']);
ob_end_clean();
sort($only->searched);
check('можно обойти только названные доски', [['TQRD' => 1], ['RU0000000011', 'RU0000000024', 'ZZ0000000999']], [$only->boardRequests, $only->searched]);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
