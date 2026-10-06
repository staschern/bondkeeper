<?php

declare(strict_types=1);

/**
 * Офлайн-проверка всего пути «сообщение НРД → разбор → обработка → текст
 * уведомления клиенту» на НАСТОЯЩИХ сообщениях GetNews
 * (tests/fixtures/getnews_messages.json, выгрузка тестового доступа
 * 18–30.09.2026). График в тестовой базе повторяет то, как эти бумаги
 * лежат в боевой: выплата номинала в конце срока — строкой amortizations,
 * а в redemptions на ту же дату ноль; купон валютного выпуска — в валюте
 * номинала; дата по условиям выпуска может быть выходным.
 *
 * Тот же путь прогнан на всей выгрузке (3798 сообщений) и выгрузке боевых
 * таблиц от 05.10.2026 — итоги в docs/STAGE5_PAYMENTS.md, раздел 9.
 *
 * Запуск (из корня репозитория):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_getnews_pipeline.php
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Events\EventPublisher;
use BondKeeper\Payments\GetNewsMessageMapper;
use BondKeeper\Payments\PaymentMessage;
use BondKeeper\Payments\PaymentProcessor;
use BondKeeper\Payments\PaymentWatch;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Telegram\NotificationDispatcher;
use BondKeeper\Telegram\TelegramClientInterface;

final class PipelineTelegramClient implements TelegramClientInterface
{
    /** @var array<int, array{chat_id: int, text: string}> */
    public array $sent = [];

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null, bool $disableWebPagePreview = false): bool
    {
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text];

        return true;
    }

    public function sendMessageReturningId(int $chatId, string $text, ?array $replyMarkup = null): ?int
    {
        return $this->sendMessage($chatId, $text) ? 1 : null;
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        return true;
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool
    {
        return true;
    }

    public function lastSendWasBlockedByUser(): bool
    {
        return false;
    }
}

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

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

$db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, short_name TEXT, inn TEXT)');
$db->exec("CREATE TABLE securities (id INTEGER PRIMARY KEY AUTOINCREMENT, isin TEXT, issuer_id INTEGER, short_name TEXT, currency TEXT DEFAULT 'RUB', is_structured INTEGER DEFAULT 0, status TEXT DEFAULT 'active')");
$schedule = "id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, issuer_id INTEGER, %s TEXT, value_per_bond TEXT, actual_value_per_bond TEXT, full_default_date_planned TEXT, status TEXT DEFAULT 'planned'";
$db->exec('CREATE TABLE coupons (' . sprintf($schedule, 'period_end_date') . ')');
$db->exec('CREATE TABLE amortizations (' . sprintf($schedule, 'payment_date_planned') . ')');
$db->exec('CREATE TABLE redemptions (' . sprintf($schedule, 'payment_date_planned') . ", redemption_type TEXT DEFAULT 'scheduled_maturity')");
$db->exec('CREATE TABLE event_types (code TEXT PRIMARY KEY, default_priority TEXT, notify_client INTEGER)');
$db->exec("INSERT INTO event_types VALUES ('A2','green',1),('A3','green',0),('A4','info',1),('A5','info',0),('A6','info',1),('A7','info',0),
    ('B1','red',1),('B1a','yellow',1),('B2','red',1),('B2a','yellow',1),('B2b','yellow',0),('B4','yellow',1),('B5','green',1),('C2','critical',1),('R1','info',1)");
$db->exec("CREATE TABLE raw_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, source_ref TEXT, isin TEXT, raw_payload TEXT, processed_at TEXT,
    processing_status TEXT, processing_error TEXT, retry_count INTEGER DEFAULT 0, last_retry_at TEXT)");
$db->exec("CREATE TABLE event_stories (id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, coupon_id INTEGER, amortization_id INTEGER, redemption_id INTEGER,
    title TEXT, status TEXT DEFAULT 'open', resolution_type TEXT, resolved_at TEXT)");
$db->exec("CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, issuer_id INTEGER, event_type_code TEXT, story_id INTEGER, raw_message_id INTEGER,
    event_date TEXT, published_at TEXT, detected_at TEXT DEFAULT (datetime('now')), amount_planned TEXT, amount_actual TEXT, status_text TEXT, priority TEXT, payload_json TEXT)");
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, telegram_id INTEGER, telegram_bot_blocked INTEGER DEFAULT 0)');
$db->exec("CREATE TABLE watchlist (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, issuer_id INTEGER, security_id INTEGER, added_at TEXT DEFAULT (datetime('now')))");
$db->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_id INTEGER, channel TEXT, status TEXT, failure_reason TEXT, sent_at TEXT, UNIQUE(user_id, event_id, channel))');

$db->exec("INSERT INTO issuers (id, short_name, inn) VALUES (1, 'Эмитент', '7700000001'), (2, 'Тихий эмитент', '7700000002')");
$db->exec("INSERT INTO users (id, telegram_id) VALUES (1, 111)");
$db->exec("INSERT INTO watchlist (user_id, issuer_id, added_at) VALUES (1, 1, '2026-01-01 00:00:00'), (1, 2, '2026-01-01 00:00:00')");

/**
 * Бумага с графиком. $rows: [таблица, дата, сумма]. Таблица 'final' —
 * выплата номинала в конце срока так, как она лежит в боевой базе:
 * строка amortizations с суммой и строка redemptions с нулём на ту же дату.
 */
$addBond = static function (string $isin, string $name, array $rows, string $currency = 'RUB', int $issuerId = 1) use ($db): int {
    $db->prepare('INSERT INTO securities (isin, issuer_id, short_name, currency) VALUES (:isin, :issuer, :name, :currency)')
        ->execute(['isin' => $isin, 'issuer' => $issuerId, 'name' => $name, 'currency' => $currency]);
    $securityId = (int) $db->lastInsertId();
    $insert = static function (string $table, string $column, string $date, string $value) use ($db, $securityId, $issuerId): void {
        $db->prepare("INSERT INTO {$table} (security_id, issuer_id, {$column}, value_per_bond) VALUES (:s, :i, :d, :v)")
            ->execute(['s' => $securityId, 'i' => $issuerId, 'd' => $date, 'v' => $value]);
    };
    foreach ($rows as [$table, $date, $value]) {
        match ($table) {
            'coupon' => $insert('coupons', 'period_end_date', $date, $value),
            'amortization' => $insert('amortizations', 'payment_date_planned', $date, $value),
            'redemption' => $insert('redemptions', 'payment_date_planned', $date, $value),
            'final' => [$insert('amortizations', 'payment_date_planned', $date, $value), $insert('redemptions', 'payment_date_planned', $date, '0.0000')],
        };
    }

    return $securityId;
};

$rzd = $addBond('RU000A100W45', 'РЖД 1Р-13R', [['coupon', '2026-09-30', '105.2000']]);
$acron6 = $addBond('RU000A10AA28', 'Акрон Б1P6', [['coupon', '2026-09-30', '11.3400']]);
$gpb = $addBond('RU000A10ELA7', 'ГПБ004Р52', [['coupon', '2026-09-28', '0.0500']]);
$emZapad = $addBond('RU000A1098V0', 'ЭМ ЗАПАД КО-П10', [['coupon', '2026-09-29', '12.7400']]);
$samolet = $addBond('RU000A10CZA1', 'СамолетP20', [['coupon', '2026-09-28', '17.4700'], ['final', '2026-09-28', '1000.0000']]);
$mmz = $addBond('RU000A108YZ4', 'ММЗ001P-01', [['coupon', '2026-09-29', '4.6800']]);
$lkh1 = $addBond('RU000A10AMY3', 'ЛКХ-01', [['coupon', '2026-09-08', '25.4800']]);
$lkh2 = $addBond('RU000A10AT01', 'ЛКХ-02', [['coupon', '2026-08-28', '25.4800'], ['coupon', '2026-09-27', '25.4800']]); // 27.09 — воскресенье
$vzvt = $addBond('RU000A10A7A8', 'ВЗВТ-01', [['coupon', '2026-09-01', '74.7900']]);
$garant = $addBond('RU000A105GV6', 'ГарИнв2P05', [['coupon', '2026-09-28', '8.2200']]);
$monopoly = $addBond('RU000A10B396', 'МОНОП 1P04', [['coupon', '2026-09-08', '21.7800'], ['final', '2026-09-08', '1000.0000']]);
$invobl = $addBond('RU000A109QT1', 'ИнвОбл11', [['coupon', '2026-10-02', '0.1000'], ['final', '2026-10-02', '1000.0000']]);
$acronUsd = $addBond('RU000A10BRM5', 'Акрон Б1P9', [['coupon', '2026-09-29', '6.3700']], 'USD');
$ltrade = $addBond('RU000A105RF6', 'ЛТрейд 1P6', [['coupon', '2026-09-30', '7.9500'], ['amortization', '2026-09-30', '41.6000']]);
$veb = $addBond('RU000A10FS15', 'ВЭБ2Р-К749', [['coupon', '2026-09-23', '5.2700'], ['final', '2026-09-23', '1000.0000']]);
$tb3 = $addBond('RU000A10B7T7', 'ТБ-3_01', [['amortization', '2026-09-26', '98.7700']]); // 26.09 — суббота
$samoletP13 = $addBond('RU000A107RZ0', 'СамолетP13', [['coupon', '2026-09-26', '17.2600']]); // суббота
$quiet = $addBond('RU000A0QUIET0', 'Тихий БО-01', [['coupon', '2026-09-30', '10.0000']], 'RUB', 2);

/** @var array<string, array<string, mixed>> $fixtures */
$fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures/getnews_messages.json'), true, 512, JSON_THROW_ON_ERROR);
$calendar = WorkingCalendar::fromFile(dirname(__DIR__) . '/config/working_calendar.php');
$publisher = new EventPublisher($db);
$processor = new PaymentProcessor($db, $publisher, $calendar);
$watch = new PaymentWatch($db, $publisher, $calendar);

/** Обработать образец: результат и коды событий. */
$feed = static function (string $label) use ($fixtures, $processor): array {
    $result = $processor->process(GetNewsMessageMapper::map($fixtures[$label]));

    return [$result['result'], $result['events']];
};
$row = static fn (string $table, int $securityId, string $column, string $date): array => $db->query(
    "SELECT status, actual_value_per_bond AS actual, full_default_date_planned AS deadline FROM {$table} WHERE security_id = {$securityId} AND {$column} = '{$date}'"
)->fetch();
$coupon = static fn (int $securityId, string $date): array => $row('coupons', $securityId, 'period_end_date', $date);
$amortization = static fn (int $securityId, string $date): array => $row('amortizations', $securityId, 'payment_date_planned', $date);
$redemption = static fn (int $securityId, string $date): array => $row('redemptions', $securityId, 'payment_date_planned', $date);
$codes = static fn (int $securityId): array => $db->query("SELECT event_type_code FROM events WHERE security_id = {$securityId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$lastPayload = static fn (int $securityId): array => json_decode((string) $db->query("SELECT payload_json FROM events WHERE security_id = {$securityId} ORDER BY id DESC LIMIT 1")->fetchColumn(), true);

echo "--- обычные выплаты ---\n";
check('«О получении» за два дня до срока (состояние у НРД «Не состоялось») → клиенту «получено»', ['processed', ['A2']], $feed('pair_received'));
check('купон отмечен выплаченным', ['status' => 'paid', 'actual' => '105.2000', 'deadline' => null], $coupon($rzd, '2026-09-30'));
check('«О передаче» в день выплаты → только отметка «передано», деньги второй раз не считаются', [['processed', ['A3']], '105.2000'], [$feed('pair_transferred'), $coupon($rzd, '2026-09-30')['actual']]);
check('то же сообщение ещё раз → повтор', ['duplicate', []], $feed('pair_transferred'));
check('«О получении и передаче», состояние «Состоялось»', ['processed', ['A2']], $feed('combined_state_A'));
check('«О получении и передаче», состояние «Не состоялось» → тоже «получено»', ['processed', ['A2']], $feed('combined_state_N'));
check('купон и амортизация одной даты — два сообщения, два события', [['processed', ['A4']], ['processed', ['A2']]], [$feed('amortization_ltrade'), $feed('coupon_ltrade')]);
check('дата в сообщении — понедельник 28.09, в графике суббота 26.09 → найдено по дню исполнения', [['processed', ['A2']], 'paid'], [$feed('weekend_shift_samolet_p13'), $coupon($samoletP13, '2026-09-26')['status']]);
check('бумаги нет в базе (оферта не разбирается, а чужой купон не сохраняется)', null, GetNewsMessageMapper::map($fixtures['offer_bput_money']));

echo "\n--- погашение: в графике оно лежит строкой amortizations, в redemptions — ноль ---\n";
check('MCAL на 100 % номинала → «погашение получено»', ['processed', ['A6']], $feed('mcal_full_veb'));
check('…сумма записана в строку amortizations, нулевая строка redemptions не тронута', ['paid', '1000.0000', 'planned'], [$amortization($veb, '2026-09-23')['status'], $amortization($veb, '2026-09-23')['actual'], $redemption($veb, '2026-09-23')['status']]);
check('MCAL на часть номинала → амортизация (в графике суббота 26.09)', [['processed', ['A4']], 'paid'], [$feed('mcal_partial_tb3'), $amortization($tb3, '2026-09-26')['status']]);

echo "\n--- деньги пришли полностью, но позже срока: ЭМ ЗАПАД и ЛКХ ---\n";
check('ЭМ ЗАПАД: срок 29.09, деньги 30.09 → «выплачено после просрочки», а не «не выплачено»', ['processed', ['B5']], $feed('late_full_em_zapad'));
check('купон выплачен', ['paid', '12.7400'], [$coupon($emZapad, '2026-09-29')['status'], $coupon($emZapad, '2026-09-29')['actual']]);
check('ЛКХ-01: «О получении» с опозданием → B5, затем «О передаче» → отметка', [['processed', ['B5']], ['processed', ['A3']]], [$feed('lkh1_late_received'), $feed('lkh1_late_transferred')]);
check('ЛКХ-02: получение мы не видели, первым пришло «О передаче» (у НРД уже «Дефолт») → засчитано как поздняя выплата', ['processed', ['B5', 'A3']], $feed('lkh2_transferred_state_D'));

echo "\n--- выплата частями: «Самолёт», погашение 28.09 ---\n";
check('купон того же дня пришёл целиком', ['processed', ['A2']], $feed('samolet_coupon'));
check('первая часть погашения, 269,10 из 1000 → красное «выплачено частично»', ['processed', ['B1']], $feed('samolet_redemption_part1'));
check('статус «частично», срок полного дефолта 12.10.2026', ['status' => 'partial', 'actual' => '269.1049', 'deadline' => '2026-10-12'], $amortization($samolet, '2026-09-28'));
check('остаток 730,90 на следующий день → долг закрыт, «выплачено после просрочки»', ['processed', ['B5']], $feed('samolet_redemption_part2'));
check('269,104907468 + 730,895092532 = 1000 — выплачено, несмотря на округление в базе до 4 знаков', ['paid', '1000.0000'], [$amortization($samolet, '2026-09-28')['status'], $amortization($samolet, '2026-09-28')['actual']]);
check('вся история погашения — одна лента', ['A2', 'B1', 'B5'], $codes($samolet));

echo "\n--- часть суммы пришла раньше срока: ММЗ ---\n";
check('2,84 из 4,68 накануне срока → жёлтое «до срока получена часть», не красное', ['processed', ['B1a']], $feed('mmz_partial_before_due'));
check('статус «частично», но отсчёта до дефолта нет — срок ещё не нарушен', ['status' => 'partial', 'actual' => '2.8400', 'deadline' => null], $coupon($mmz, '2026-09-29'));
$remainder = new PaymentMessage('test-mmz-rest', 'RU000A108YZ4', 'coupon', '2026-09-29', 'received', 'partial', '1.84', '2026-09-30',
    plannedPerBond: '4.68', currency: 'RUB', receivedDate: '2026-09-29', actionRef: '942065');
$r = $processor->process($remainder);
check('остаток пришёл в срок → обычное «получено» (просрочки не было)', [['A2'], 'paid', '4.6800'], [$r['events'], $coupon($mmz, '2026-09-29')['status'], $coupon($mmz, '2026-09-29')['actual']]);

echo "\n--- не выплачено в срок и дефолт: объявления НРД ---\n";
check('«Гарант-Инвест»: объявление в состоянии «Техн.дефолт» → красное «не выплачено в срок»', ['processed', ['B2']], $feed('garant_tech_default_announcement'));
check('срок полного дефолта: 28.09 + 10 рабочих дней', ['status' => 'not_paid', 'actual' => null, 'deadline' => '2026-10-12'], $coupon($garant, '2026-09-28'));
check('ЛКХ-02: в объявлении понедельник 28.09, в графике воскресенье 27.09 → та же выплата', [['processed', ['B2']], 'not_paid'], [$feed('lkh2_tech_default_announcement'), $coupon($lkh2, '2026-09-27')['status']]);
check('ВЗВТ: частичная выплата на 14-й рабочий день, получение мы не видели → «частично» и отметка «передано»', ['processed', ['B1', 'A3']], $feed('vzvt_partial_transferred_state_D'));
check('«Монополия»: объявление в состоянии «Дефолт» по купону → событие «дефолт»', ['processed', ['C2']], $feed('monopoly_default_coupon'));
check('…и по погашению (ложится на строку amortizations)', [['processed', ['C2']], 'not_paid'], [$feed('monopoly_default_redemption'), $amortization($monopoly, '2026-09-08')['status']]);
check('история выплаты закрыта как «дефолт»', ['resolved', 'defaulted'], array_values($db->query("SELECT status, resolution_type FROM event_stories WHERE security_id = {$monopoly} ORDER BY id LIMIT 1")->fetch()));
$again = $fixtures['monopoly_default_coupon'];
$again['content_id_out'] = 47330186521;
check('повторное объявление о дефолте другим сообщением → второго уведомления нет', 'no_change', $processor->process(GetNewsMessageMapper::map($again))['result']);

echo "\n--- две выплаты НРД на одну строку купона: структурный выпуск ---\n";
check('«процентный доход» 65,21 пришёл первым → «получено»', ['processed', ['A2']], $feed('invobl_extra_income'));
check('«купонный доход» 0,10 от другого события НРД → ещё одно «получено», не потеряно', ['processed', ['A2']], $feed('invobl_coupon_fixed'));
check('в строке купона — сумма обеих выплат; вторая помечена', ['65.3100', true], [$coupon($invobl, '2026-10-02')['actual'], $lastPayload($invobl)['extra']]);
check('погашение той же бумаги', ['processed', ['A6']], $feed('invobl_redemption'));

echo "\n--- валютный выпуск ---\n";
check('купон в графике 6,37 доллара, НРД сообщает 537,25 рубля → «получено», не «частично»', ['processed', ['A2']], $feed('fx_coupon_acron_usd'));
$p = $lastPayload($acronUsd);
check('в событии — сумма и валюта выплаты из сообщения', ['537.2500', '537.2500', 'RUB'], [$p['amount_actual'], $p['amount_planned'], $p['currency']]);

echo "\n--- чужая бумага и пробел в графике ---\n";
$foreign = $fixtures['pair_received'];
$foreign['content_id_out'] = 1;
$foreign['data']['securities'][0]['isin'] = 'RU000AFOREIGN';
$r = $processor->process(GetNewsMessageMapper::map($foreign));
check('бумаги нет в нашей базе → не ошибка и в архив не пишется', ['foreign', 0], [$r['result'], (int) $db->query("SELECT COUNT(*) FROM raw_messages WHERE source_ref = '1'")->fetchColumn()]);
$gap = $fixtures['pair_received'];
$gap['content_id_out'] = 2;
$gap['data']['action_date_plan'] = '2026-12-30';
$gap['data']['action_date_calc'] = '2026-12-30';
$r = $processor->process(GetNewsMessageMapper::map($gap));
check('бумага наша, выплаты на эту дату в графике нет → в архиве с причиной, это первая попытка', ['unmatched', false, 'failed'], [$r['result'], $r['retry'], $db->query("SELECT processing_status FROM raw_messages WHERE source_ref = '2'")->fetchColumn()]);
$r = $processor->process(GetNewsMessageMapper::map($gap));
check('следующий опрос: всё ещё нет → повторная попытка (администратору второй раз не сообщается)', ['unmatched', true], [$r['result'], $r['retry']]);
$db->exec("INSERT INTO coupons (security_id, issuer_id, period_end_date, value_per_bond) VALUES ({$rzd}, 1, '2026-12-30', '105.2000')");
check('график дозагружен → то же сообщение обрабатывается', 'processed', $processor->process(GetNewsMessageMapper::map($gap))['result']);
check('архив: сообщение без английских полей', false, str_contains((string) $db->query('SELECT raw_payload FROM raw_messages ORDER BY id LIMIT 1')->fetchColumn(), 'body_en'));

echo "\n--- структурный выпуск: графика купонов у биржи нет ---\n";
$db->exec("INSERT INTO securities (isin, issuer_id, short_name, is_structured) VALUES ('RU000A10STRU1', 1, 'СбКИБ1P999', 1)");
$structured = (int) $db->lastInsertId();
$asStructured = static function (string $label, int $id) use ($fixtures): array {
    $raw = $fixtures[$label];
    $raw['content_id_out'] = $id;
    $raw['data']['securities'][0]['isin'] = 'RU000A10STRU1';

    return $raw;
};
check('просмотр: купон будет создан по сообщению', 'build', $processor->locate(GetNewsMessageMapper::map($asStructured('invobl_extra_income', 3)))['structured']);
$r = $processor->process(GetNewsMessageMapper::map($asStructured('invobl_extra_income', 3)));
check('купон берётся прямо из сообщения НРД → клиенту «получено»', ['processed', ['A2']], [$r['result'], $r['events']]);
check('в графике появилась строка купона: дата и сумма из сообщения, выплачен', ['65.2100', 'paid', '65.2100'], array_values($db->query(
    "SELECT value_per_bond, status, actual_value_per_bond FROM coupons WHERE security_id = {$structured} AND period_end_date = '2026-10-02'"
)->fetch()));
$r = $processor->process(GetNewsMessageMapper::map($asStructured('invobl_coupon_fixed', 4)));
check('вторая выплата на ту же дату от другого события НРД — ещё одно «получено», строка та же', [['A2'], 1], [$r['events'], (int) $db->query("SELECT COUNT(*) FROM coupons WHERE security_id = {$structured}")->fetchColumn()]);
check('просмотр: погашение вне графика будет пропущено', 'skip', $processor->locate(GetNewsMessageMapper::map($asStructured('invobl_redemption', 5)))['structured']);
$r = $processor->process(GetNewsMessageMapper::map($asStructured('invobl_redemption', 5)));
check('погашение структурного выпуска вне графика → пропущено без шума, в архиве помечено', ['skipped', 'ignored'], [$r['result'], $db->query("SELECT processing_status FROM raw_messages WHERE source_ref = '5'")->fetchColumn()]);
check('следующий опрос к нему не возвращается', 'duplicate', $processor->process(GetNewsMessageMapper::map($asStructured('invobl_redemption', 5)))['result']);
$r = $processor->process(GetNewsMessageMapper::map($asStructured('garant_tech_default_announcement', 6)));
check('объявление о техдефолте по выплате, которой нет в графике, купон не создаёт', ['skipped', 1], [$r['result'], (int) $db->query("SELECT COUNT(*) FROM coupons WHERE security_id = {$structured}")->fetchColumn()]);
check('обычный (не структурный) выпуск без строки в графике — по-прежнему пробел для администратора', null, $processor->locate(GetNewsMessageMapper::map($gap + ['content_id_out' => 7]))['structured']);

echo "\n--- просмотр без записи (--dry-run) ---\n";
$located = $processor->locate(GetNewsMessageMapper::map($fixtures['samolet_redemption_part1']));
check('куда ляжет погашение: таблица amortizations, точная дата', ['amortizations', 'exact', '2026-09-28'], [$located['payment']['table'], $located['payment']['match'], $located['payment']['payment_date']]);
check('выплата с переносом: найдена по дню исполнения', 'shifted', $processor->locate(GetNewsMessageMapper::map($fixtures['weekend_shift_samolet_p13']))['payment']['match']);
check('чужая бумага', null, $processor->locate(GetNewsMessageMapper::map($foreign))['security']);

echo "\n--- тихая загрузка ---\n";
$silent = new PaymentProcessor($db, $publisher, $calendar, true);
$quietMessage = new PaymentMessage('test-quiet', 'RU000A0QUIET0', 'coupon', '2026-09-30', 'received', 'full', '10', '2026-09-30');
check('событие и статус пишутся как обычно', [['A2'], 'paid'], [$silent->process($quietMessage)['events'], $coupon($quiet, '2026-09-30')['status']]);
check('…но для подписчика сразу стоит отметка «не отправлять»', ['failed', PaymentProcessor::SILENT_REASON], array_values($db->query(
    "SELECT n.status, n.failure_reason FROM notifications n JOIN events e ON e.id = n.event_id WHERE e.security_id = {$quiet}"
)->fetch()));

echo "\n--- проверка «НРД не сообщил о деньгах» на этих же данных ---\n";
$noReceipt = $watch->checkNoReceipt('2026-09-29', false);
$names = array_column($noReceipt['items'], 'security_name');
check('вторник 29.09, 12:02: по выплатам понедельника в списке только те, о ком НРД молчит', [], array_values(array_intersect($names, ['СамолетP20', 'СамолетP13', 'ГПБ004Р52', 'ГарИнв2P05', 'ЛКХ-02', 'ТБ-3_01'])));
$reminder = $watch->remind('2026-10-01', false);
check('напоминание на 02.10 по ИнвОбл11 не создаётся: купон и погашение уже получены НРД', [], $reminder['items']);
$db->exec("INSERT INTO coupons (security_id, issuer_id, period_end_date, value_per_bond) VALUES ({$veb}, 1, '2026-10-23', '5.2700')");
$db->exec("UPDATE amortizations SET payment_date_planned = '2026-10-23', status = 'planned', actual_value_per_bond = NULL WHERE security_id = {$veb}");
$db->exec("UPDATE redemptions SET payment_date_planned = '2026-10-23' WHERE security_id = {$veb}");
$reminder = $watch->remind('2026-10-22', false);
check('напоминание в день погашения: «купон» и «погашение 1000», без строки «погашение — 0»', [['coupon', 'redemption'], '1000.0000'], [array_column($reminder['items'][0]['payments'], 'kind'), $reminder['items'][0]['payments'][1]['amount_planned']]);
$db->exec("INSERT INTO coupons (security_id, issuer_id, period_end_date, value_per_bond) VALUES ({$acronUsd}, 1, '2026-10-23', '6.3700')");
$watch->remind('2026-10-22');

echo "\n--- что увидит клиент ---\n";
$telegram = new PipelineTelegramClient();
(new NotificationDispatcher($db, $telegram))->dispatchPending();
$find = static function (string ...$needles) use ($telegram): string {
    foreach ($telegram->sent as $message) {
        foreach ($needles as $needle) {
            if (!str_contains($message['text'], $needle)) {
                continue 2;
            }
        }

        return $message['text'];
    }

    return '';
};
check('обычный купон', true, str_contains($find('РЖД 1Р-13R', 'получен НРД'), "<b>✅ Выплаты:</b>\nЭмитент · РЖД 1Р-13R (RU000A100W45)\nКупон за 30.09.26 получен НРД: 105.20 ₽ на бумагу."));
check('поздняя полная выплата: срок и день поступления', true, str_contains($find('ЭМ ЗАПАД'), 'Купон за 29.09.26 выплачен полностью после просрочки: 12.74 ₽ на бумагу. Срок был 29.09.26, деньги поступили 30.09.26.'));
check('часть погашения: сумма, отметка НРД дословно, срок полного дефолта', true, str_contains($find('СамолетP20', 'частично'),
    "<b>🔴 Выплаты:</b>\nЭмитент · СамолетP20 (RU000A10CZA1)\nПогашение 28.09.26 выплачено частично: получено 269.10 ₽ из 1 000.00 ₽ на бумагу. Отметка НРД: «исполнена ненадлежащим образом». Полный дефолт наступит, если долг не будет закрыт до 12.10.26 (осталось рабочих дней: 9)."));
check('остаток погашения', true, str_contains($find('СамолетP20', 'после просрочки'), 'Погашение 28.09.26 выплачено полностью после просрочки: 1 000.00 ₽ на бумагу. Срок был 28.09.26, деньги поступили 29.09.26.'));
check('часть до срока — жёлтое, со сроком и отметкой НРД', true, str_contains($find('ММЗ001P-01', 'до срока'),
    "<b>🟡 Выплаты:</b>\nЭмитент · ММЗ001P-01 (RU000A108YZ4)\nКупон за 29.09.26: до срока получена часть суммы — 2.84 ₽ из 4.68 ₽ на бумагу. Срок выплаты — 29.09.26. Отметка НРД: «исполнена в неполном объеме до наступления срока»."));
check('не выплачено в срок', true, str_contains($find('ГарИнв2P05'), '<b>🔴 Выплаты:</b>') && str_contains($find('ГарИнв2P05'), 'Купон за 28.09.26 не выплачен в срок. Полный дефолт наступит, если долг не будет закрыт до 12.10.26 (осталось рабочих дней: 9).'));
check('старая просрочка, о которой узнали поздно: срок до дефолта уже истёк — так и написано', true, str_contains($find('ВЗВТ-01'), 'получено 61.79 ₽ из 74.79 ₽ на бумагу.') && str_contains($find('ВЗВТ-01'), 'Срок, после которого наступает полный дефолт, истёк 15.09.26.'));
check('дефолт', true, str_contains($find('МОНОП 1P04', 'Купон'), "<b>🔴 Выплаты:</b>\nЭмитент · МОНОП 1P04 (RU000A10B396)\nКупон за 08.09.26: НРД объявил дефолт — выплата не исполнена в течение 10 рабочих дней после срока (08.09.26)."));
check('вторая выплата по тому же купону', true, str_contains($find('ИнвОбл11', 'ещё одна'), 'Купон за 02.10.26: получена ещё одна выплата — 0.10 ₽ на бумагу.'));
check('валютный выпуск: получено в рублях', true, str_contains($find('Акрон Б1P9', 'получен НРД'), 'Купон за 29.09.26 получен НРД: 537.25 ₽ на бумагу.'));
check('напоминание по валютному выпуску: сумма в валюте номинала, не со знаком рубля', true, str_contains($find('Акрон Б1P9', 'Завтра'), 'Завтра, 23.10.26, выплата: купон — 6.37 USD на бумагу.'));
check('напоминание в день погашения', true, str_contains($find('ВЭБ2Р-К749', 'Завтра'), 'Завтра, 23.10.26, выплата: купон — 5.27 ₽ на бумагу; погашение — 1 000.00 ₽ на бумагу.'));
check('структурный выпуск: купон из сообщения НРД', true, str_contains($find('СбКИБ1P999', 'получен НРД'), 'Купон за 02.10.26 получен НРД: 65.21 ₽ на бумагу.'));
check('«передано депонентам» клиенту не уходит', '', $find('передано'));
check('тихая загрузка: по «Тихому эмитенту» клиенту ничего не ушло', '', $find('Тихий БО-01'));

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
