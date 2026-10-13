<?php

declare(strict_types=1);

/**
 * Офлайн-проверка напоминаний с настройками пользователя (ТЗ от
 * 12.10.2026):
 *   1. WorkingCalendar — о каких датах напоминают в данный рабочий день
 *      при интервале N календарных дней;
 *   2. PaymentWatch — напоминания о выплатах за N дней;
 *   3. OfferWatch — напоминание об оферте (R2), по умолчанию за 30 дней;
 *   4. NotificationDispatcher + NotificationPreferences — кому что уходит:
 *      отключённые напоминания, отключённый эмитент, исключённый выпуск,
 *      свой интервал; тексты напоминания об оферте.
 * Календарь рабочих дней — настоящий (config/working_calendar.php).
 *
 * Запуск (из корня репозитория):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_reminder_settings.php
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Events\EventPublisher;
use BondKeeper\Payments\OfferWatch;
use BondKeeper\Payments\PaymentsConfig;
use BondKeeper\Payments\PaymentWatch;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Telegram\NotificationDispatcher;
use BondKeeper\Telegram\NotificationPreferences;
use BondKeeper\Telegram\TelegramClientInterface;

final class Outbox implements TelegramClientInterface
{
    /** @var array<int, array<int, string>> chat_id → тексты */
    public array $byChat = [];

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null, bool $disableWebPagePreview = false): bool
    {
        $this->byChat[$chatId][] = $text;

        return true;
    }

    public function sendMessageReturningId(int $chatId, string $text, ?array $replyMarkup = null): ?int
    {
        return 1;
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

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
$db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, short_name TEXT, inn TEXT)');
$db->exec("CREATE TABLE securities (id INTEGER PRIMARY KEY, isin TEXT, issuer_id INTEGER, short_name TEXT, currency TEXT DEFAULT 'RUB', status TEXT DEFAULT 'active', maturity_date TEXT)");
$row = "id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, issuer_id INTEGER, %s TEXT, value_per_bond TEXT, status TEXT DEFAULT 'planned'";
$db->exec('CREATE TABLE coupons (' . sprintf($row, 'period_end_date') . ')');
$db->exec('CREATE TABLE amortizations (' . sprintf($row, 'payment_date_planned') . ')');
$db->exec('CREATE TABLE redemptions (' . sprintf($row, 'payment_date_planned') . ", redemption_type TEXT DEFAULT 'scheduled_maturity')");
$db->exec("CREATE TABLE offers (id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, issuer_id INTEGER, offer_type TEXT, source TEXT DEFAULT 'moex', announcement_date TEXT,
    period_start TEXT, period_end TEXT, execution_date_planned TEXT, price_percent TEXT, status TEXT DEFAULT 'announced')");
$db->exec('CREATE TABLE event_types (code TEXT PRIMARY KEY, default_priority TEXT, notify_client INTEGER)');
$db->exec("INSERT INTO event_types VALUES ('R1','info',1),('R2','info',1),('A2','green',1),('C5','yellow',1)");
$db->exec("CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, issuer_id INTEGER, event_type_code TEXT, story_id INTEGER, raw_message_id INTEGER,
    event_date TEXT, reminder_days INTEGER, published_at TEXT, detected_at TEXT DEFAULT (datetime('now')), amount_planned TEXT, amount_actual TEXT, status_text TEXT, priority TEXT, payload_json TEXT)");
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, telegram_id INTEGER, telegram_bot_blocked INTEGER DEFAULT 0)');
$db->exec("CREATE TABLE watchlist (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, issuer_id INTEGER, security_id INTEGER, added_at TEXT DEFAULT '2026-01-01 00:00:00')");
$db->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_id INTEGER, channel TEXT, status TEXT, failure_reason TEXT, sent_at TEXT, UNIQUE(user_id, event_id, channel))');
$db->exec('CREATE TABLE user_notification_settings (user_id INTEGER PRIMARY KEY, reminders_enabled INTEGER NOT NULL DEFAULT 1, coupon_days INTEGER, amortization_days INTEGER, redemption_days INTEGER, offer_days INTEGER)');
$db->exec('CREATE TABLE user_reminder_mutes (user_id INTEGER, issuer_id INTEGER, PRIMARY KEY (user_id, issuer_id))');
$db->exec('CREATE TABLE user_excluded_securities (user_id INTEGER, security_id INTEGER, PRIMARY KEY (user_id, security_id))');

$db->exec("INSERT INTO issuers VALUES (1, 'ООО «Образец»', '7700000001'), (2, 'АО «Другой»', '7700000002')");
$addBond = static function (int $id, int $issuerId, string $name, string $status = 'active') use ($db): void {
    $db->prepare('INSERT INTO securities (id, isin, issuer_id, short_name, status, maturity_date) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, sprintf('RU000A0TEST%02d', $id), $issuerId, $name, $status, '2030-01-01']);
};
$addPayment = static function (string $table, int $securityId, string $date, string $value = '10.0000') use ($db): void {
    $column = $table === 'coupons' ? 'period_end_date' : 'payment_date_planned';
    $issuer = (int) $db->query("SELECT issuer_id FROM securities WHERE id = {$securityId}")->fetchColumn();
    $db->prepare("INSERT INTO {$table} (security_id, issuer_id, {$column}, value_per_bond) VALUES (?, ?, ?, ?)")->execute([$securityId, $issuer, $date, $value]);
};
$addOffer = static function (int $securityId, string $type, string $date, array $extra = []) use ($db): void {
    $issuer = (int) $db->query("SELECT issuer_id FROM securities WHERE id = {$securityId}")->fetchColumn();
    $row = $extra + ['security_id' => $securityId, 'issuer_id' => $issuer, 'offer_type' => $type, 'execution_date_planned' => $date];
    $db->prepare('INSERT INTO offers (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
};

$calendar = WorkingCalendar::fromFile(dirname(__DIR__) . '/config/working_calendar.php');
$publisher = new EventPublisher($db);
$prefs = new NotificationPreferences($db);
$reminders = static fn (string $code): array => $db->query(
    "SELECT s.short_name || ' ' || e.event_date || ' за ' || e.reminder_days FROM events e JOIN securities s ON s.id = e.security_id WHERE e.event_type_code = '{$code}' ORDER BY e.event_date, e.security_id, e.reminder_days"
)->fetchAll(PDO::FETCH_COLUMN);

// =====================================================================
echo "--- о каких датах напоминают в рабочий день при интервале N ---\n";
check('N = 1, пятница 16.10: суббота, воскресенье и понедельник — прежнее правило', ['2026-10-17', '2026-10-18', '2026-10-19'], $calendar->scheduleDatesRemindedOn('2026-10-16'));
check('N = 1, среда: только четверг', ['2026-10-15'], $calendar->scheduleDatesRemindedOn('2026-10-14'));
check('N = 3, среда 14.10: до субботы 17.10 включительно (17 − 3 = 14)', ['2026-10-15', '2026-10-16', '2026-10-17'], $calendar->scheduleDatesRemindedOn('2026-10-14', 3));
check('N = 3, пятница 16.10: до среды 21.10 — её «день минус три» воскресенье, напоминаем в пятницу',
    ['2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21'], $calendar->scheduleDatesRemindedOn('2026-10-16', 3));
check('в выходной не напоминают ни при каком интервале', [[], []], [$calendar->scheduleDatesRemindedOn('2026-10-17', 3), $calendar->scheduleDatesRemindedOn('2026-10-18', 30)]);
$thirty = $calendar->scheduleDatesRemindedOn('2026-10-14', 30);
check('N = 30, среда 14.10: от завтра до пятницы 13.11', ['2026-10-15', '2026-11-13', 30], [$thirty[0], end($thirty), count($thirty)]);

// =====================================================================
echo "\n--- выплаты: напоминание за N дней ---\n";
$addBond(1, 1, 'Образец БО-01');
$addBond(2, 1, 'Образец БО-02');
$addBond(3, 2, 'Другой БО-01');
$addPayment('coupons', 1, '2026-10-15');        // четверг
$addPayment('coupons', 1, '2026-10-17');        // суббота
$addPayment('amortizations', 1, '2026-10-17', '250.0000');
$addPayment('coupons', 2, '2026-10-20');        // вторник: 20 − 3 = суббота → пятница
$addPayment('coupons', 3, '2026-10-17');        // другой эмитент
$watch = new PaymentWatch($db, $publisher, $calendar);

$preview = $watch->remind('2026-10-13', false, 3, [1]);
check('вторник 13.10, N = 3: в диапазоне 14–16.10 одна выплата; просмотр ничего не создаёт', [['Образец БО-01'], 0],
    [array_column($preview['items'], 'security_name'), (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn()]);
$watch->remind('2026-10-13', true, 3, [1]);
check('создано напоминание «за 3 дня» о выплате 15.10 (до неё уже меньше трёх дней, а напоминания ещё не было)', ['Образец БО-01 2026-10-15 за 3'], $reminders('R1'));
$wed = $watch->remind('2026-10-14', true, 3, [1]);
check('среда 14.10, N = 3: добавилась суббота 17.10 (купон и амортизация — одним событием); 15.10 второй раз не создаётся',
    [1, 1, ['Образец БО-01 2026-10-15 за 3', 'Образец БО-01 2026-10-17 за 3']], [$wed['created'], $wed['existing'], $reminders('R1')]);
$payload = json_decode((string) $db->query("SELECT payload_json FROM events WHERE event_date = '2026-10-17' AND reminder_days = 3")->fetchColumn(), true);
check('в событии — интервал, день напоминания и обе выплаты', [3, '2026-10-14', ['coupon', 'amortization']], [$payload['days_before'], $payload['reminder_date'], array_column($payload['payments'], 'kind')]);
check('эмитент, за которым такие пользователи не следят, в «свой интервал» не попадает', 0,
    (int) $db->query("SELECT COUNT(*) FROM events e JOIN securities s ON s.id = e.security_id WHERE s.issuer_id = 2")->fetchColumn());
$watch->remind('2026-10-16', true, 3, [1]);
check('пятница 16.10, N = 3: вторничный купон (20.10) — его «день минус три» суббота', 'Образец БО-02 2026-10-20 за 3', $reminders('R1')[2]);

$default = $watch->remind('2026-10-16');
check('обычные напоминания (за 1 рабочий день) идут своим чередом и по всем эмитентам', [2, ['Образец БО-01 2026-10-17 за 1', 'Другой БО-01 2026-10-17 за 1']],
    [$default['created'], array_values(array_filter($reminders('R1'), static fn (string $r): bool => str_ends_with($r, 'за 1')))]);
check('повторный запуск ничего не дублирует', [0, 0], [$watch->remind('2026-10-16')['created'], $watch->remind('2026-10-16', true, 3, [1])['created']]);
// Напоминание, созданное до миграции 032: интервала у него нет.
$addPayment('coupons', 2, '2026-10-22');
$db->exec("INSERT INTO events (security_id, issuer_id, event_type_code, event_date, payload_json) VALUES (2, 1, 'R1', '2026-10-22', '{}')");
check('прежнее напоминание без интервала считается сделанным «за 1 день» — второе не создаётся', 0, $watch->remind('2026-10-21')['created']);
check('пустой список эмитентов — ничего не делаем', 0, count($watch->remind('2026-10-16', false, 5, [])['items']));

// =====================================================================
echo "\n--- оферты: напоминание R2 ---\n";
$addBond(10, 1, 'Образец БО-10');
$addBond(11, 1, 'Образец БО-11');
$addBond(12, 1, 'Образец БО-12', 'tech_default');
$addBond(13, 1, 'Образец БО-13', 'default');
$addBond(14, 2, 'Другой БО-14');
$addOffer(10, 'put', '2026-11-13', ['period_start' => '2026-10-27', 'period_end' => '2026-11-02', 'price_percent' => '100.000']); // пятница: 13.11 − 30 = среда 14.10
$addOffer(11, 'put', '2026-11-16');                                                // понедельник: − 30 = суббота 17.10 → пятница 16.10
$addOffer(11, 'call', '2026-11-16', ['price_percent' => '100.000']);
$addOffer(12, 'put', '2027-07-27');
$addOffer(13, 'put', '2026-11-13');                                                // бумага в дефолте
$addOffer(14, 'put', '2026-11-13', ['status' => 'cancelled']);                     // отменена НРД
$offers = new OfferWatch($db, $publisher, $calendar);

check('вторник 13.10: до оферты 13.11 ещё 31 день — рано', [], $offers->remind('2026-10-13')['items']);
$wed = $offers->remind('2026-10-14', false);
check('среда 14.10: ровно 30 дней до оферты 13.11; просмотр ничего не создаёт', [['Образец БО-10'], []], [array_column($wed['items'], 'security_name'), $reminders('R2')]);
$offers->remind('2026-10-14');
check('создано напоминание за 30 дней; оферта бумаги в дефолте и отменённая оферта — не напоминаются', ['Образец БО-10 2026-11-13 за 30'], $reminders('R2'));
$payload = json_decode((string) $db->query("SELECT payload_json FROM events WHERE event_type_code = 'R2'")->fetchColumn(), true);
check('в событии — вид, период, цена и день напоминания',
    ['2026-11-13', '2026-10-14', 30, ['type' => 'put', 'period_start' => '2026-10-27', 'period_end' => '2026-11-02', 'price_percent' => '100.000', 'announced' => false]],
    [$payload['offer_date'], $payload['reminder_date'], $payload['days_before'], $payload['offers'][0]]);
check('четверг 15.10: нового нет', 0, $offers->remind('2026-10-15')['created']);
$fri = $offers->remind('2026-10-16');
check('пятница 16.10: оферта в понедельник 16.11 («день минус 30» — суббота); пут и колл одной даты — одно событие', [1, ['put', 'call']],
    [$fri['created'], array_column(json_decode((string) $db->query("SELECT payload_json FROM events WHERE event_type_code = 'R2' AND security_id = 11")->fetchColumn(), true)['offers'], 'type')]);
check('повторный запуск ничего не дублирует', [0, 2], [$offers->remind('2026-10-16')['created'], $offers->remind('2026-10-16')['existing']]);

// Колл, объявленный НРД за две недели: до него меньше 30 дней, напоминания ещё не было.
$addBond(15, 1, 'Образец БО-15', 'tech_default');
$addOffer(15, 'call', '2026-10-30', ['source' => 'nsd', 'announcement_date' => '2026-10-16', 'price_percent' => '100.000']);
$late = $offers->remind('2026-10-19');
check('оферта появилась, когда до неё осталось меньше 30 дней, — напоминание уходит в ближайший рабочий день', [['Образец БО-15'], true, 'tech_default'],
    [array_column($late['items'], 'security_name'), json_decode((string) $db->query("SELECT payload_json FROM events WHERE security_id = 15")->fetchColumn(), true)['offers'][0]['announced'],
        json_decode((string) $db->query("SELECT payload_json FROM events WHERE security_id = 15")->fetchColumn(), true)['security_status']]);
check('оферта, дата которой уже сегодня или прошла, не напоминается', 0, $offers->remind('2026-10-30')['created']);

// Напоминание сразу после объявления НРД: опрос вызывает remind() только по бумагам из объявлений этого прохода.
$addBond(16, 2, 'Другой БО-16');
$addBond(17, 2, 'Другой БО-17');
$addOffer(16, 'call', '2026-10-29', ['source' => 'nsd', 'announcement_date' => '2026-10-19']);
$addOffer(17, 'put', '2026-10-28');
$now = $offers->remind('2026-10-19', true, 30, null, ['RU000A0TEST16']);
check('сразу после объявления: создано напоминание только по названной бумаге',
    [['Другой БО-16'], 1, ['Другой БО-16 2026-10-29 за 30']],
    [array_column($now['items'], 'security_name'), $now['created'], array_values(array_filter($reminders('R2'), static fn (string $r): bool => str_starts_with($r, 'Другой БО-16')))]);
check('…вторая оферта ждёт утреннего запуска: события по ней ещё нет', 0, (int) $db->query("SELECT COUNT(*) FROM events WHERE security_id = 17")->fetchColumn());
check('повторный проход опроса по той же бумаге ничего не дублирует; пустой список бумаг — ничего не делаем',
    [0, 1, []], [$offers->remind('2026-10-19', true, 30, null, ['RU000A0TEST16'])['created'], $offers->remind('2026-10-19', true, 30, null, ['RU000A0TEST16'])['existing'],
        $offers->remind('2026-10-19', true, 30, null, [])['items']]);
check('утренний запуск создаёт оставшееся и не повторяет созданное сразу', [['Другой БО-17'], 0],
    [array_column($offers->remind('2026-10-20')['items'], 'security_name'), $offers->remind('2026-10-20')['created']]);
check('в нерабочий день и «сразу» ничего не создаётся — напоминание уйдёт в ближайший рабочий день', [], $offers->remind('2026-10-24', true, 30, null, ['RU000A0TEST16'])['items']);
check('бумаги нет среди оферт — пусто, без ошибки (в том числе с кавычкой в строке)', [], $offers->remind('2026-10-19', true, 30, null, ["RU'; DROP TABLE offers; --"])['items']);

// Первый запуск (решение пользователя, 12.10.2026): о путах, которые уже были в базе и до которых меньше 10 дней, не напоминаем.
// В настройках — дата «день включения плюс 10 дней»; коллов правило не касается.
$addBond(18, 2, 'Другой БО-18');
$addBond(19, 2, 'Другой БО-19');
$addBond(20, 2, 'Другой БО-20');
$addBond(21, 2, 'Другой БО-21');
$addOffer(18, 'put', '2026-10-27');
$addOffer(19, 'call', '2026-10-27');
$addOffer(20, 'put', '2026-10-27');
$addOffer(20, 'call', '2026-10-27');
$addOffer(21, 'put', '2026-10-30');
$fresh = ['RU000A0TEST18', 'RU000A0TEST19', 'RU000A0TEST20', 'RU000A0TEST21'];
$firstRun = new OfferWatch($db, $publisher, $calendar, '2026-10-30');
$preview = $firstRun->remind('2026-10-21', false, 30, null, $fresh);
check('пут раньше даты из настроек пропущен; колл той же даты — нет; у бумаги с путом и коллом остался колл; пут на саму дату — напоминаем',
    [['Другой БО-19', 'Другой БО-20', 'Другой БО-21'], 1, ['call'], ['put']],
    [array_column($preview['items'], 'security_name'), $preview['skipped_puts'], array_column($preview['items'][1]['offers'], 'type'), array_column($preview['items'][2]['offers'], 'type')]);
check('без настройки о том же путе напомнили бы', ['Другой БО-18', 'Другой БО-19', 'Другой БО-20', 'Другой БО-21'],
    array_column($offers->remind('2026-10-21', false, 30, null, $fresh)['items'], 'security_name'));
$firstRun->remind('2026-10-21', true, 30, null, $fresh);
$nextDay = $firstRun->remind('2026-10-22', true, 30, null, $fresh);
check('на следующий день пропущенный пут не «догоняется», остальное не дублируется', [0, 1, 3, 0],
    [$nextDay['created'], $nextDay['skipped_puts'], $nextDay['existing'], (int) $db->query('SELECT COUNT(*) FROM events WHERE security_id = 18')->fetchColumn()]);
check('в событии бумаги с путом и коллом — только колл', ['call'],
    array_column(json_decode((string) $db->query('SELECT payload_json FROM events WHERE security_id = 20')->fetchColumn(), true)['offers'], 'type'));

$configFile = static function (string $body): string {
    $file = tempnam(sys_get_temp_dir(), 'bk_payments_');
    file_put_contents($file, "<?php\nreturn {$body};\n");

    return $file;
};
$broken = static function (string $body) use ($configFile): bool {
    try {
        PaymentsConfig::fromFile($configFile($body));
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'offer_put_reminders_from');
    }

    return false;
};
check('настройки: дата читается; строки нет — правило не действует', ['2026-10-23', true, null, false],
    [PaymentsConfig::fromFile($configFile("['offer_reminders' => true, 'offer_put_reminders_from' => '2026-10-23']"))->offerPutRemindersFrom,
        PaymentsConfig::fromFile($configFile("['offer_reminders' => true, 'offer_put_reminders_from' => '2026-10-23']"))->offerReminders,
        PaymentsConfig::fromFile($configFile("['reminders' => true]"))->offerPutRemindersFrom, PaymentsConfig::fromFile($configFile("['reminders' => true]"))->offerReminders]);
check('настройки: не дата, несуществующая дата или число без кавычек — понятная ошибка сразу', [true, true, true],
    [$broken("['offer_put_reminders_from' => '23.10.2026']"), $broken("['offer_put_reminders_from' => '2026-02-30']"), $broken("['offer_put_reminders_from' => 20261023]")]);

$custom = $offers->remind('2026-10-19', true, 45, [1]);
check('свой интервал 45 дней: отдельные события по тем же офертам (в том числе по близкой — «за 45 дней» о ней ещё не напоминали)',
    [['Образец БО-15 2026-10-30 за 45', 'Образец БО-10 2026-11-13 за 45', 'Образец БО-11 2026-11-16 за 45'], 3],
    [array_values(array_filter($reminders('R2'), static fn (string $r): bool => str_ends_with($r, 'за 45'))), $custom['created']]);
check('строка для лога называет бумагу, эмитента, вид и дату', '• Образец БО-10 (RU000A0TEST10), ООО «Образец»: пут, дата 13.11.2026', OfferWatch::adminLines($wed['items'])[0]);

// =====================================================================
echo "\n--- рассылка: кому что уходит ---\n";
$db->exec('DELETE FROM events');
$db->exec('INSERT INTO users (id, telegram_id) VALUES (1, 101), (2, 102), (3, 103), (4, 104), (5, 105)');
foreach ([1, 2, 3, 4, 5] as $userId) {
    $db->exec("INSERT INTO watchlist (user_id, issuer_id) VALUES ({$userId}, 1)");
}
$prefs->setDays(2, 'coupon', 3);          // у второго — купоны за 3 дня, остальное по умолчанию
$prefs->setRemindersEnabled(3, false);    // третий отключил напоминания целиком
$prefs->setIssuerMuted(4, 1, true);       // четвёртый — по этому эмитенту
$prefs->setSecurityExcluded(5, 1, true);  // пятый снял отметку с выпуска

$payments = [['kind' => 'coupon', 'amount_planned' => '12.3300'], ['kind' => 'amortization', 'amount_planned' => '250.0000']];
$base = ['isin' => 'RU000A0TEST01', 'security_name' => 'Образец БО-01', 'currency' => 'RUB'];
$event = static function (string $code, ?int $securityId, string $date, ?int $days, array $payload, string $status = '') use ($db): int {
    $db->prepare('INSERT INTO events (security_id, issuer_id, event_type_code, event_date, reminder_days, status_text, payload_json) VALUES (?, 1, ?, ?, ?, ?, ?)')
        ->execute([$securityId, $code, $date, $days, $status, json_encode($payload, JSON_UNESCAPED_UNICODE)]);

    return (int) $db->lastInsertId();
};
$r1 = $event('R1', 1, '2099-10-15', 1, $base + ['payment_date' => '2099-10-15', 'reminder_date' => '2099-10-14', 'payments' => $payments]);
$r1three = $event('R1', 1, '2099-10-15', 3, $base + ['payment_date' => '2099-10-15', 'reminder_date' => '2099-10-12', 'payments' => $payments]);
$r1old = $event('R1', 1, '2020-10-15', null, $base + ['payment_date' => '2020-10-15', 'payments' => [['kind' => 'coupon', 'amount_planned' => '1.0000']]]);
$r2 = $event('R2', 1, '2099-11-13', 30, $base + ['offer_date' => '2099-11-13', 'reminder_date' => '2099-10-14',
    'offers' => [['type' => 'put', 'period_start' => '2099-10-27', 'period_end' => '2099-11-02', 'price_percent' => '100.000', 'announced' => false]]]);
$a2 = $event('A2', 1, '2099-10-15', null, $base + ['kind' => 'coupon', 'payment_date' => '2099-10-15', 'amount_actual' => '12.3300']);
$c5 = $event('C5', null, '2099-10-15', null, [], 'НКР подтвердило рейтинг');

$outbox = new Outbox();
$dispatcher = new NotificationDispatcher($db, $outbox);
$dispatcher->dispatchPending();
$got = static fn (int $chat, string $needle): int => count(array_filter($outbox->byChat[$chat] ?? [], static fn (string $t): bool => str_contains($t, $needle)));
$reason = static fn (int $userId, int $eventId): ?string => ($db->query("SELECT failure_reason FROM notifications WHERE user_id = {$userId} AND event_id = {$eventId}")->fetchColumn()) ?: null;

check('без своих настроек: обычное напоминание с обеими выплатами, оферта, «купон получен», рейтинг — и ничего «за 3 дня»',
    [1, 0, 1, 1, 1], [$got(101, 'Завтра, 15.10.99, выплата: купон — 12.33 ₽ на бумагу; амортизация — 250.00 ₽ на бумагу.'), $got(101, '12.10'), $got(101, 'Оферты'), $got(101, 'получен НРД'), $got(101, 'рейтинг')]);
check('…напоминание «за 3 дня» ему не ушло и помечено', NotificationPreferences::SKIP_OTHER_INTERVAL, $reason(1, $r1three));
check('купоны за 3 дня: в напоминании «за 3 дня» только купон, в обычном — только амортизация',
    [1, 1, 0], [$got(102, '15.10.99 выплата: купон — 12.33 ₽ на бумагу.'), $got(102, 'Завтра, 15.10.99, выплата: амортизация — 250.00 ₽ на бумагу.'), $got(102, 'купон — 12.33 ₽ на бумагу; амортизация')]);
check('прежнее напоминание без интервала (только купон) ему не уходит: его купоны — за 3 дня', NotificationPreferences::SKIP_OTHER_INTERVAL, $reason(2, $r1old));
check('напоминания отключены: ни выплат, ни оферт; «купон получен» и рейтинг приходят',
    [0, 0, 1, 1, NotificationPreferences::SKIP_REMINDERS_OFF, NotificationPreferences::SKIP_REMINDERS_OFF],
    [$got(103, 'выплата:'), $got(103, 'Оферты'), $got(103, 'получен НРД'), $got(103, 'рейтинг'), $reason(3, $r1), $reason(3, $r2)]);
check('напоминания отключены по эмитенту: то же самое', [0, 0, 1, NotificationPreferences::SKIP_ISSUER_MUTED], [$got(104, 'выплата:'), $got(104, 'Оферты'), $got(104, 'получен НРД'), $reason(4, $r2)]);
check('выпуск исключён: по нему не приходит ничего, рейтинг эмитента — приходит',
    [0, 0, 0, 1, NotificationPreferences::SKIP_SECURITY_EXCLUDED], [$got(105, 'выплата:'), $got(105, 'Оферты'), $got(105, 'получен НРД'), $got(105, 'рейтинг'), $reason(5, $a2)]);
$before = array_sum(array_map('count', $outbox->byChat));
check('второй проход рассыльщика ничего не шлёт: неотправленное помечено и заново не оценивается', [0, $before], [$dispatcher->dispatchPending(), array_sum(array_map('count', $outbox->byChat))]);

// Третий включает напоминания обратно.
$prefs->enableAllReminders(3);
check('включил напоминания: на оценку вернулись только будущие — обычное, «за 3 дня» и оферта', 3, $prefs->reviveSuppressedReminders(3, '2026-10-12'));
$dispatcher->dispatchPending();
check('…и он получил обычное напоминание и оферту; «за 3 дня» — нет, это не его интервал; о прошедшей выплате — тоже нет',
    [1, 1, 0, NotificationPreferences::SKIP_OTHER_INTERVAL, NotificationPreferences::SKIP_REMINDERS_OFF],
    [$got(103, 'Завтра, 15.10.99'), $got(103, 'Оферты'), $got(103, '15.10.99 выплата: купон'), $reason(3, $r1three), $reason(3, $r1old)]);

echo "\n--- напоминание об оферте: тексты ---\n";
$render = static fn (array $offers, array $extra = []): string => $dispatcher->renderText('R2', $base + ['offer_date' => '2026-11-13', 'reminder_date' => '2026-10-14', 'offers' => $offers] + $extra,
    'ООО «Образец»', '7700000001');
check('пут с периодом предъявления и ценой',
    "<b>⏰ Оферты:</b>\nООО «Образец» | ИНН 7700000001 · Образец БО-01 (RU000A0TEST01)\n13.11.26 — оферта (пут), осталось 30 дн. Владельцы могут предъявить облигации к выкупу эмитентом. "
    . 'Период предъявления: 27.10.26 – 02.11.26. Цена выкупа: 100 % номинала.',
    $render([['type' => 'put', 'period_start' => '2026-10-27', 'period_end' => '2026-11-02', 'price_percent' => '100.000', 'announced' => false]]));
check('пут без периода и цены — без лишних слов', true,
    str_ends_with($render([['type' => 'put', 'period_start' => null, 'period_end' => null, 'price_percent' => null, 'announced' => false]]), 'Владельцы могут предъявить облигации к выкупу эмитентом.'));
check('колл по данным биржи — право эмитента', true,
    str_ends_with($render([['type' => 'call', 'price_percent' => '100.000', 'announced' => false]]), '13.11.26 — колл-опцион, осталось 30 дн. Эмитент вправе досрочно погасить выпуск в эту дату по цене 100 % номинала.'));
check('колл, объявленный НРД, — решение уже принято; доля номинала без лишних нулей', true,
    str_ends_with($render([['type' => 'call', 'price_percent' => '9.877', 'announced' => true]]), '13.11.26 — досрочное погашение по решению эмитента (колл), осталось 30 дн. Эмитент объявил о погашении выпуска: 9.877 % номинала.'));
check('бумага в техдефолте — отдельной строкой, что исполнение под вопросом', true,
    str_ends_with($render([['type' => 'put', 'price_percent' => '90.000', 'announced' => false]], ['security_status' => 'tech_default']),
        "Цена выкупа: 90 % номинала.\n⚠️ Бумага в техническом дефолте — исполнение оферты под вопросом."));
check('пут и колл одной даты — две строки в одном сообщении', 2, substr_count($render([['type' => 'put'], ['type' => 'call']]), '13.11.26 — '));

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
