<?php

declare(strict_types=1);

/**
 * Офлайн-проверка Этапа 5 — уведомления о выплатах (docs/STAGE5_PAYMENTS.md):
 *   1. PaymentProcessor — обработка нормализованного сообщения о выплате
 *      на реальных кейсах из исследования пользователя (СибАвтоТранс,
 *      КЛВЗ «Кристалл», ВЗВТ, ЕвроТранс, Нэппи Клаб);
 *   2. PaymentWatch — напоминание накануне, «денег пока нет» вечером и
 *      утром, «от НРД нет ничего» для администратора;
 *   3. NotificationDispatcher — тексты уведомлений и отбор получателей по
 *      бумаге.
 * Суммы в кейсах — из исследования; там, где в нём названа только часть
 * цифр, остальные подобраны так, чтобы сходилась арифметика.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_payments.php
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Events\EventPublisher;
use BondKeeper\Payments\PaymentMessage;
use BondKeeper\Payments\PaymentProcessor;
use BondKeeper\Payments\PaymentWatch;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Telegram\NotificationDispatcher;
use BondKeeper\Telegram\TelegramClientInterface;

final class RecordingTelegramClient implements TelegramClientInterface
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
$db->exec("CREATE TABLE securities (id INTEGER PRIMARY KEY, isin TEXT, issuer_id INTEGER, short_name TEXT, currency TEXT DEFAULT 'RUB', status TEXT DEFAULT 'active')");
$schedule = "id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, issuer_id INTEGER, %s TEXT, value_per_bond TEXT, actual_value_per_bond TEXT, full_default_date_planned TEXT, status TEXT DEFAULT 'planned'";
$db->exec('CREATE TABLE coupons (' . sprintf($schedule, 'period_end_date') . ')');
$db->exec('CREATE TABLE amortizations (' . sprintf($schedule, 'payment_date_planned') . ')');
$db->exec('CREATE TABLE redemptions (' . sprintf($schedule, 'payment_date_planned') . ", redemption_type TEXT DEFAULT 'scheduled_maturity')");
$db->exec("CREATE TABLE event_types (code TEXT PRIMARY KEY, default_priority TEXT, notify_client INTEGER)");
$db->exec("INSERT INTO event_types VALUES ('A2','green',1),('A3','green',0),('A4','info',1),('A5','info',0),('A6','info',1),('A7','info',0),
    ('B1','red',1),('B1a','yellow',1),('B2','red',1),('B2a','yellow',1),('B2b','yellow',0),('B4','yellow',1),('B5','green',1),('C2','critical',1),('R1','info',1),('C5','yellow',1)");
$db->exec("CREATE TABLE raw_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, source_ref TEXT, isin TEXT, raw_payload TEXT, processed_at TEXT,
    processing_status TEXT, processing_error TEXT, retry_count INTEGER DEFAULT 0, last_retry_at TEXT)");
$db->exec("CREATE TABLE event_stories (id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, coupon_id INTEGER, amortization_id INTEGER, redemption_id INTEGER,
    title TEXT, status TEXT DEFAULT 'open', resolution_type TEXT, resolved_at TEXT)");
$db->exec("CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, issuer_id INTEGER, event_type_code TEXT, story_id INTEGER, raw_message_id INTEGER,
    event_date TEXT, published_at TEXT, detected_at TEXT DEFAULT (datetime('now')), amount_planned TEXT, amount_actual TEXT, status_text TEXT, priority TEXT, payload_json TEXT)");
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, telegram_id INTEGER, telegram_bot_blocked INTEGER DEFAULT 0)');
$db->exec("CREATE TABLE watchlist (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, issuer_id INTEGER, security_id INTEGER, added_at TEXT DEFAULT (datetime('now')))");
$db->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_id INTEGER, channel TEXT, status TEXT, failure_reason TEXT, sent_at TEXT, UNIQUE(user_id, event_id, channel))');

$issuers = [1 => 'СибАвтоТранс', 2 => 'КЛВЗ Кристалл', 3 => 'ВЗВТ', 4 => 'ЕвроТранс', 5 => 'Нэппи Клаб', 6 => 'Флоатер', 7 => 'Тихий эмитент'];
foreach ($issuers as $id => $name) {
    $db->prepare('INSERT INTO issuers (id, short_name, inn) VALUES (:id, :name, :inn)')->execute(['id' => $id, 'name' => $name, 'inn' => '770000000' . $id]);
}
$securities = [
    10 => ['RU000A107KV4', 1, 'СибАвтоТранс 001Р-03'],
    20 => ['RU000A10AZG2', 2, 'КЛВЗ Кристалл 001Р-02'],
    30 => ['RU000A000003', 3, 'ВЗВТ БО-01'],
    40 => ['RU000A000004', 4, 'ЕвроТранс БО-001Р-07'],
    50 => ['RU000A000005', 5, 'Нэппи Клаб БО-01'],
    60 => ['RU000A000006', 6, 'Флоатер БО-01'],
    70 => ['RU000A000007', 7, 'Тихий БО-01'],
    71 => ['RU000A000071', 7, 'Тихий БО-02'],
];
foreach ($securities as $id => [$isin, $issuerId, $name]) {
    $db->prepare('INSERT INTO securities (id, isin, issuer_id, short_name) VALUES (:id, :isin, :issuer, :name)')->execute(['id' => $id, 'isin' => $isin, 'issuer' => $issuerId, 'name' => $name]);
}
$issuerOf = static fn (int $securityId): int => $securities[$securityId][1];
$addCoupon = static fn (int $securityId, string $date, ?string $value) => $db->prepare('INSERT INTO coupons (security_id, issuer_id, period_end_date, value_per_bond) VALUES (:s, :i, :d, :v)')
    ->execute(['s' => $securityId, 'i' => $issuerOf($securityId), 'd' => $date, 'v' => $value]);
$addAmortization = static fn (int $securityId, string $date, string $value) => $db->prepare('INSERT INTO amortizations (security_id, issuer_id, payment_date_planned, value_per_bond) VALUES (:s, :i, :d, :v)')
    ->execute(['s' => $securityId, 'i' => $issuerOf($securityId), 'd' => $date, 'v' => $value]);
$addRedemption = static fn (int $securityId, string $date, string $value) => $db->prepare('INSERT INTO redemptions (security_id, issuer_id, payment_date_planned, value_per_bond) VALUES (:s, :i, :d, :v)')
    ->execute(['s' => $securityId, 'i' => $issuerOf($securityId), 'd' => $date, 'v' => $value]);

$calendar = WorkingCalendar::fromFile(dirname(__DIR__) . '/config/working_calendar.php');
$publisher = new EventPublisher($db);
$processor = new PaymentProcessor($db, $publisher, $calendar);
$watch = new PaymentWatch($db, $publisher, $calendar);

$ref = 0;
$message = static function (string $isin, string $kind, string $paymentDate, string $stage, string $execution, ?string $amount, string $messageDate, ?string $sourceRef = null) use (&$ref): PaymentMessage {
    return new PaymentMessage($sourceRef ?? 'msg-' . (++$ref), $isin, $kind, $paymentDate, $stage, $execution, $amount, $messageDate, null, null, ['example' => true]);
};
$row = static fn (string $table, int $securityId, string $dateColumn, string $date): array => $db->query(
    "SELECT status, actual_value_per_bond, full_default_date_planned FROM {$table} WHERE security_id = {$securityId} AND {$dateColumn} = '{$date}'"
)->fetch();
$coupon = static fn (int $securityId, string $date): array => $row('coupons', $securityId, 'period_end_date', $date);
$storyOf = static fn (int $securityId): array => $db->query("SELECT status, resolution_type FROM event_stories WHERE security_id = {$securityId} ORDER BY id DESC LIMIT 1")->fetch();
$eventCodes = static fn (int $securityId): array => $db->query("SELECT event_type_code FROM events WHERE security_id = {$securityId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

echo "--- штатная выплата: СибАвтоТранс, январь 2026 (купон 47,37 и амортизация 50 руб. на 15.01) ---\n";
$addCoupon(10, '2026-01-15', '47.3700');
$addAmortization(10, '2026-01-15', '50.0000');
$r = $processor->process($message('RU000A107KV4', 'coupon', '2026-01-15', 'received', 'full', '47.37', '2026-01-14'));
check('«О получении» накануне, 14.01 → событие A2', ['processed', ['A2']], [$r['result'], $r['events']]);
check('купон: выплачен, факт 47,37', ['paid', '47.3700'], [$coupon(10, '2026-01-15')['status'], $coupon(10, '2026-01-15')['actual_value_per_bond']]);
check('история выплаты закрыта как «выплачено»', ['status' => 'resolved', 'resolution_type' => 'paid'], $storyOf(10));
$r = $processor->process($message('RU000A107KV4', 'coupon', '2026-01-15', 'transferred', 'full', '47.37', '2026-01-15'));
check('«О передаче» 15.01 → только отметка A3, деньги второй раз не прибавляются', [['A3'], '47.3700'], [$r['events'], $coupon(10, '2026-01-15')['actual_value_per_bond']]);
$r = $processor->process($message('RU000A107KV4', 'amortization', '2026-01-15', 'received', 'full', '50', '2026-01-15'));
check('амортизация той же даты — отдельное сообщение и своё событие A4', ['A4'], $r['events']);
check('амортизация: выплачена', 'paid', $row('amortizations', 10, 'payment_date_planned', '2026-01-15')['status']);
check('у купона и амортизации разные истории', 2, (int) $db->query('SELECT COUNT(*) FROM event_stories WHERE security_id = 10')->fetchColumn());

echo "\n--- невыплата в срок и доплата: КЛВЗ «Кристалл», купон 22.06.2026 ---\n";
$addCoupon(20, '2026-06-22', '13.4200');
$r = $processor->process($message('RU000A10AZG2', 'coupon', '2026-06-22', 'received', 'none', null, '2026-06-23'));
check('23.06 «не исполнена эмитентом в срок» → B2', ['B2'], $r['events']);
check('статус «не выплачено», срок полного дефолта — 06.07.2026 (22.06 + 10 рабочих дней)', ['not_paid', '2026-07-06'], [$coupon(20, '2026-06-22')['status'], $coupon(20, '2026-06-22')['full_default_date_planned']]);
$payload = json_decode((string) $db->query("SELECT payload_json FROM events WHERE security_id = 20 AND event_type_code = 'B2'")->fetchColumn(), true);
check('в событии: до дефолта 9 рабочих дней от даты сообщения', 9, $payload['working_days_to_default']);
$r = $processor->process($message('RU000A10AZG2', 'coupon', '2026-06-22', 'received', 'none', null, '2026-06-24'));
check('повторное «не исполнена» → второго красного уведомления нет', ['no_change', []], [$r['result'], $r['events']]);
$r = $processor->process($message('RU000A10AZG2', 'coupon', '2026-06-22', 'received', 'full', '13.42', '2026-06-23'));
check('деньги пришли 23.06 → B5 «выход из техдефолта»', ['B5'], $r['events']);
check('купон выплачен, история закрыта; рассчитанный срок дефолта остаётся в строке как справка', ['paid', '2026-07-06', 'resolved'], [$coupon(20, '2026-06-22')['status'], $coupon(20, '2026-06-22')['full_default_date_planned'], $storyOf(20)['status']]);
check('вся серия — одна история: B2, B5', ['B2', 'B5'], $eventCodes(20));

echo "\n--- частичная выплата в день выплаты: ВЗВТ, купон 02.06.2026 ---\n";
$addCoupon(30, '2026-06-02', '30.0000');
$r = $processor->process($message('RU000A000003', 'coupon', '2026-06-02', 'transferred', 'partial', '12', '2026-06-02'));
check('02.06 «О передаче…» с неполной суммой, получения до этого не было → B1 и отметка A3', ['B1', 'A3'], $r['events']);
check('статус «частично», получено 12 из 30', ['partial', '12.0000'], [$coupon(30, '2026-06-02')['status'], $coupon(30, '2026-06-02')['actual_value_per_bond']]);
$r = $processor->process($message('RU000A000003', 'coupon', '2026-06-02', 'received', 'none', null, '2026-06-11'));
check('11.06 сообщение о неисполнении в срок → B2, статус остаётся «частично»', [['B2'], 'partial'], [$r['events'], $coupon(30, '2026-06-02')['status']]);
$r = $processor->process($message('RU000A000003', 'coupon', '2026-06-02', 'received', 'full', '18', '2026-06-15'));
check('15.06 поступила доплата 18 → долг закрыт, B5', [['B5'], 'paid', '30.0000'], [$r['events'], $coupon(30, '2026-06-02')['status'], $coupon(30, '2026-06-02')['actual_value_per_bond']]);
$r = $processor->process($message('RU000A000003', 'coupon', '2026-06-02', 'transferred', 'full', '18', '2026-06-17'));
check('17.06 «О передаче» → только A3', ['A3'], $r['events']);

echo "\n--- транши: ЕвроТранс, 12-й купон 06.04.2026 (20,14 руб.; пришло 2,32 и 1,53) ---\n";
$addCoupon(40, '2026-04-06', '20.1400');
$r = $processor->process($message('RU000A000004', 'coupon', '2026-04-06', 'received', 'partial', '2.32', '2026-04-06'));
check('первый транш 2,32 → B1', ['B1'], $r['events']);
$r = $processor->process($message('RU000A000004', 'coupon', '2026-04-06', 'received', 'partial', '1.53', '2026-04-08'));
check('второй транш 1,53 → B4, всего 3,85', [['B4'], '3.8500'], [$r['events'], $coupon(40, '2026-04-06')['actual_value_per_bond']]);
check('срок полного дефолта — 20.04.2026', '2026-04-20', $coupon(40, '2026-04-06')['full_default_date_planned']);
$r = $processor->process($message('RU000A000004', 'coupon', '2026-04-06', 'received', 'partial', '16.29', '2026-04-15'));
check('третий транш закрывает долг → B5, а не ещё один B4', [['B5'], 'paid', '20.1400'], [$r['events'], $coupon(40, '2026-04-06')['status'], $coupon(40, '2026-04-06')['actual_value_per_bond']]);
check('одна история на весь купон', 1, (int) $db->query('SELECT COUNT(*) FROM event_stories WHERE security_id = 40')->fetchColumn());

echo "\n--- выход из техдефолта: Нэппи Клаб, 16-й купон 12.01.2026 (20,75 руб.) ---\n";
$addCoupon(50, '2026-01-12', '20.7500');
$processor->process($message('RU000A000005', 'coupon', '2026-01-12', 'received', 'none', null, '2026-01-13'));
check('срок полного дефолта — 26.01.2026', '2026-01-26', $coupon(50, '2026-01-12')['full_default_date_planned']);
$r = $processor->process($message('RU000A000005', 'coupon', '2026-01-12', 'received', 'full', null, '2026-01-19'));
check('19.01 выплата без суммы в сообщении → B5, факт = плану', [['B5'], '20.7500'], [$r['events'], $coupon(50, '2026-01-12')['actual_value_per_bond']]);

echo "\n--- привязка к графику, повторы, чужая бумага ---\n";
$addCoupon(60, '2026-10-10', null); // суббота, флоатер: сумма в графике ещё не известна
$r = $processor->process($message('RU000A000006', 'coupon', '2026-10-12', 'received', 'full', '11.5', '2026-10-12', 'msg-weekend'));
check('в графике суббота 10.10, источник назвал понедельник 12.10 → та же выплата', ['processed', ['A2']], [$r['result'], $r['events']]);
check('флоатер без плановой суммы: факт — из сообщения', ['paid', '11.5000'], [$coupon(60, '2026-10-10')['status'], $coupon(60, '2026-10-10')['actual_value_per_bond']]);
$r = $processor->process($message('RU000A000006', 'coupon', '2026-10-12', 'received', 'full', '11.5', '2026-10-12', 'msg-weekend'));
check('то же сообщение второй раз → повтор, событий нет', ['duplicate', []], [$r['result'], $r['events']]);
$r = $processor->process($message('RU000A000006', 'coupon', '2026-10-12', 'received', 'full', '11.5', '2026-10-13'));
check('другое сообщение о том же получении → изменений нет', 'no_change', $r['result']);
$r = $processor->process($message('RU000A999999', 'coupon', '2026-10-12', 'received', 'full', '1', '2026-10-12', 'msg-foreign'));
check('бумаги нет в базе → «чужая»: не ошибка, в архив не пишется', ['foreign', 0], [$r['result'], (int) $db->query("SELECT COUNT(*) FROM raw_messages WHERE source_ref = 'msg-foreign'")->fetchColumn()]);
$r = $processor->process($message('RU000A000006', 'coupon', '2027-03-15', 'received', 'full', '11.5', '2027-03-15', 'msg-gap'));
check('бумага наша, выплаты на такую дату в графике нет → не сопоставлено, сообщение сохранено с причиной', ['unmatched', false, 'failed'],
    [$r['result'], $r['retry'], $db->query("SELECT processing_status FROM raw_messages WHERE source_ref = 'msg-gap'")->fetchColumn()]);
$db->exec("INSERT INTO coupons (security_id, issuer_id, period_end_date, value_per_bond) VALUES (60, 6, '2027-03-15', '11.5000')");
$r = $processor->process($message('RU000A000006', 'coupon', '2027-03-15', 'received', 'full', '11.5', '2027-03-15', 'msg-gap'));
check('выплата появилась в графике → то же сообщение обрабатывается со второй попытки', ['processed', 1], [$r['result'], (int) $db->query("SELECT retry_count FROM raw_messages WHERE source_ref = 'msg-gap'")->fetchColumn()]);
check('в архиве сырых сообщений — исходный вид и отметка источника', ['nsd', '{"example":true}'], array_values($db->query("SELECT source, raw_payload FROM raw_messages WHERE source_ref = 'msg-weekend'")->fetch()));

echo "\n--- напоминание накануне выплаты ---\n";
$addCoupon(70, '2026-10-10', '25.0000');       // суббота
$addAmortization(70, '2026-10-10', '100.0000');
$addCoupon(71, '2026-10-13', '8.5000');        // вторник
$addRedemption(71, '2026-10-13', '1000.0000');
$db->exec("INSERT INTO securities (id, isin, issuer_id, short_name, status) VALUES (72, 'RU000A000072', 7, 'Погашенная', 'matured')");
$db->exec("INSERT INTO coupons (security_id, issuer_id, period_end_date, value_per_bond) VALUES (72, 7, '2026-10-10', '5.0000')");

$dry = $watch->remind('2026-10-09', false);
check('просмотр: одна бумага с выплатой завтра, ничего не создано', [1, 0, 0], [count($dry['items']), $dry['created'], (int) $db->query("SELECT COUNT(*) FROM events WHERE event_type_code = 'R1'")->fetchColumn()]);
$remind = $watch->remind('2026-10-09');
check('пятница 09.10: напоминание о субботней выплате — от даты по графику, не от понедельника', 1, $remind['created']);
$r1 = $db->query("SELECT security_id, event_date, payload_json FROM events WHERE event_type_code = 'R1'")->fetch();
$p = json_decode((string) $r1['payload_json'], true);
check('купон и амортизация одной бумаги — одно напоминание с двумя выплатами', [70, '2026-10-10', ['coupon', 'amortization']], [(int) $r1['security_id'], $r1['event_date'], array_column($p['payments'], 'kind')]);
check('в напоминании назван день, когда деньги придут на деле (12.10)', '2026-10-12', $p['effective_date']);
check('повторный запуск в тот же день — напоминание не дублируется', [0, 1], [$watch->remind('2026-10-09')['created'], $watch->remind('2026-10-09')['existing']]);
check('в четверг напоминаний о субботе нет (сутки, а не двое)', 0, count($watch->remind('2026-10-08', false)['items']));
check('понедельник 12.10: напоминание о вторнике — купон и погашение', ['coupon', 'redemption'], array_column($watch->remind('2026-10-12', false)['items'][0]['payments'], 'kind'));
$watch->remind('2026-10-12');

echo "\n--- НРД не сообщил о деньгах: полдень следующего рабочего дня, вечером — администратору ---\n";
check('суббота 10.10 — нерабочий день, проверка ничего не делает', 0, count($watch->checkNoReceipt('2026-10-10', true)['items']));
check('понедельник 12.10 — день исполнения субботней выплаты: проверять ещё рано, НРД сообщает на следующий день', 0, count($watch->checkNoReceipt('2026-10-12', true)['items']));
$adminOnly = $watch->checkNoReceipt('2026-10-13', false);
check('вторник 13.10, режим «только администратору»: бумага в списке, событий клиентам нет', [[70], 0],
    [array_map('intval', array_column($adminOnly['items'], 'security_id')), (int) $db->query("SELECT COUNT(*) FROM events WHERE event_type_code = 'B2a'")->fetchColumn()]);
$noon = $watch->checkNoReceipt('2026-10-13', true);
$b2a = json_decode((string) $db->query("SELECT payload_json FROM events WHERE event_type_code = 'B2a'")->fetchColumn(), true);
check('вторник 13.10, 12:02: по субботней выплате (исполнение в понедельник) сообщения нет → жёлтое B2a', [1, 'noon', '2026-10-12', '2026-10-10'],
    [$noon['created'], $b2a['check'], $b2a['due_date'], $b2a['payment_date']]);
check('повторный запуск — без дублей', [0, 1], [$watch->checkNoReceipt('2026-10-13', true)['created'], $watch->checkNoReceipt('2026-10-13', true)['existing']]);

// Во вторник после 17:00 НРД объявляет: купон не исполнен в срок. По амортизации — ничего.
$processor->process($message('RU000A000007', 'coupon', '2026-10-10', 'received', 'none', null, '2026-10-13'));
$silence = $watch->checkSilence('2026-10-13');
check('вторник, 19:00: по амортизации от НРД нет вообще ничего → B2b и строка администратору', [1, ['amortization']], [$silence['created'], array_column($silence['items'][0]['payments'], 'kind')]);
check('строка для администратора называет бумагу и вид выплаты', true, str_contains(PaymentWatch::adminLines($silence['items'])[0], 'Тихий БО-01 (RU000A000007), Тихий эмитент: амортизация'));
check('повторная проверка — администратору второй раз не сообщается', [0, []], [$watch->checkSilence('2026-10-13')['created'], $watch->checkSilence('2026-10-13')['items']]);
check('в среду 14.10 в списке — вторничные выплаты второй бумаги', [71], array_map('intval', array_column($watch->checkNoReceipt('2026-10-14', false)['items'], 'security_id')));
$processor->process($message('RU000A000071', 'coupon', '2026-10-13', 'received', 'full', '8.5', '2026-10-13'));
check('после «получено» по купону в списке остаётся только погашение', ['redemption'], array_column($watch->checkNoReceipt('2026-10-14', false)['items'][0]['payments'], 'kind'));
check('календарь без нужного года — предупреждение администратору', [null, true], [$watch->calendarGapWarning('2026-10-13'), str_contains((string) $watch->calendarGapWarning('2027-12-20'), '2028')]);

echo "\n--- рассылка: тексты и получатели ---\n";
$db->exec('INSERT INTO users (id, telegram_id) VALUES (1, 111), (2, 222)');
$db->exec("INSERT INTO watchlist (user_id, issuer_id, security_id, added_at) VALUES (1, 7, NULL, '2026-01-01 00:00:00'), (2, 7, 71, '2026-01-01 00:00:00'), (1, 2, NULL, '2026-01-01 00:00:00'), (1, 4, NULL, '2026-01-01 00:00:00')");
$db->exec("INSERT INTO events (issuer_id, event_type_code, event_date, status_text, payload_json) VALUES (7, 'C5', '2026-10-13', 'НКР: рейтинг подтверждён на уровне A.ru', '{\"agency\":\"nkr\",\"rating_from\":\"A.ru\",\"rating_to\":\"A.ru\"}')");
$telegram = new RecordingTelegramClient();
(new NotificationDispatcher($db, $telegram))->dispatchPending();
$textsFor = static fn (int $chatId): array => array_values(array_map(static fn (array $m): string => $m['text'], array_filter($telegram->sent, static fn (array $m): bool => $m['chat_id'] === $chatId)));
$all = implode("\n---\n", $textsFor(111));
$find = static function (string $needle) use ($telegram): string {
    foreach ($telegram->sent as $m) {
        if (str_contains($m['text'], $needle)) {
            return $m['text'];
        }
    }

    return '';
};

check('напоминание: заголовок с эмитентом, бумагой и ISIN, обе выплаты с суммами', true,
    str_contains($find('Завтра, 10.10.26'), "<b>⏰ Выплаты:</b>\nТихий эмитент · Тихий БО-01 (RU000A000007)\nЗавтра, 10.10.26, выплата: купон — 25.00 ₽ на бумагу; амортизация — 100.00 ₽ на бумагу."));
check('напоминание о выплате в выходной называет день поступления денег', true, str_contains($find('Завтра, 10.10.26'), 'Дата выпадает на выходной — деньги должны поступить 12.10.26.'));
check('напоминание о выплате в рабочий день — без оговорки про выходной', false, str_contains($find('Завтра, 13.10.26'), 'выходной'));
check('«купон получен»', true, str_contains($all, "<b>✅ Выплаты:</b>\nТихий эмитент · Тихий БО-02 (RU000A000071)\nКупон за 13.10.26 получен НРД: 8.50 ₽ на бумагу."));
check('жёлтое: НРД не сообщил о деньгах, с оговоркой, что это ещё не невыплата', true,
    str_contains($find('пока не сообщил'), "<b>🟡 Выплаты:</b>\nТихий эмитент · Тихий БО-01 (RU000A000007)\nПо выплате (купон, амортизация) за 10.10.26 НРД пока не сообщил о поступлении денег от эмитента, хотя обычно к этому времени сообщение уже выходит. Это ещё не подтверждённая невыплата: если деньги не пришли, НРД объявит о неисполнении сегодня, чаще после 17:00."));
check('красное «не выплачен в срок» со сроком полного дефолта', true,
    str_contains($find('Купон за 10.10.26 не выплачен'), 'Купон за 10.10.26 не выплачен в срок. Полный дефолт наступит, если долг не будет закрыт до 26.10.26 (осталось рабочих дней: 9).'));
check('B2b клиентам не уходит', '', $find('Нет сообщений НРД'));
check('«передано депонентам» клиентам не уходит (всего сообщений про КЛВЗ два: B2 и B5)', 2, count(array_filter($textsFor(111), static fn (string $t): bool => str_contains($t, 'КЛВЗ'))));
check('выход из техдефолта', true, str_contains($all, 'Купон за 22.06.26 выплачен полностью после просрочки: 13.42 ₽ на бумагу.'));
check('частичная выплата и доплата по траншам', [true, true], [
    str_contains($all, 'Купон за 06.04.26 выплачен частично: получено 2.32 ₽ из 20.14 ₽ на бумагу.'),
    str_contains($all, 'Купон за 06.04.26: доплата 1.53 ₽, всего получено 3.85 ₽ из 20.14 ₽ на бумагу.'),
]);
$second = $textsFor(222);
check('подписка на один выпуск (БО-02): события другого выпуска того же эмитента не приходят', 0, count(array_filter($second, static fn (string $t): bool => str_contains($t, 'Тихий БО-01'))));
check('…а события своего выпуска и события уровня эмитента (рейтинг) приходят', [true, true], [
    (bool) array_filter($second, static fn (string $t): bool => str_contains($t, 'Тихий БО-02')),
    (bool) array_filter($second, static fn (string $t): bool => str_contains($t, 'рейтинг подтверждён')),
]);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
