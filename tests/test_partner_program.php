<?php

declare(strict_types=1);

/**
 * Офлайн-проверка партнёрской программы и промокодов (решения
 * пользователя от 13.10.2026, docs/PARTNER_PROGRAM.md): весь путь через
 * настоящий обработчик бота — от входящего сообщения Telegram до записи в
 * базе и ответа.
 *   1. Администратор назначает партнёру промокод командой в боте.
 *   2. Клиент приходит по ссылке с промокодом.
 *   3. Клиент вводит промокод сам: предложение через 10 секунд после
 *      «Знакомства», ввод сообщением, кнопка в «Подписке».
 *   4. Правила: один раз, не свой, только до первой оплаты.
 *   5. Итоги по партнёрам за месяц: привёл, оплатили, вознаграждение.
 *
 * Обработчик пишет на диалекте MySQL только в одном месте (регистрация
 * пользователя); здесь этот запрос на лету переводится под SQLite.
 *
 * Запуск (из корня репозитория):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_partner_program.php
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Fns\NalogBiClientInterface;
use BondKeeper\Fns\NalogBiResult;
use BondKeeper\Partners\PartnerProgram;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Telegram\BotCommandHandler;
use BondKeeper\Telegram\DelayedMessages;
use BondKeeper\Telegram\TelegramBotConfig;
use BondKeeper\Telegram\TelegramClientInterface;

/** Переводит запрос регистрации пользователя с диалекта MySQL на SQLite. */
final class MysqlOnSqlite extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT(telegram_id) DO UPDATE SET', $query);
        $query = (string) preg_replace('/\bVALUES\((\w+)\)/', 'excluded.$1', $query);

        return parent::prepare($query, $options);
    }
}

final class Chat implements TelegramClientInterface
{
    /** @var array<int, array{chat: int, text: string, markup: ?array}> всё, что бот отправил */
    public array $sent = [];
    /** @var array<int, string> всплывающие ответы на нажатия кнопок */
    public array $toasts = [];
    /** @var array<int, string> тексты, на которые бот заменил свои сообщения */
    public array $edits = [];

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null, bool $disableWebPagePreview = false): bool
    {
        $this->sent[] = ['chat' => $chatId, 'text' => $text, 'markup' => $replyMarkup];

        return true;
    }

    public function sendMessageReturningId(int $chatId, string $text, ?array $replyMarkup = null): ?int
    {
        $this->sendMessage($chatId, $text, $replyMarkup);

        return count($this->sent);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        $this->toasts[] = (string) $text;

        return true;
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool
    {
        $this->edits[] = $text;

        return true;
    }

    public function lastSendWasBlockedByUser(): bool
    {
        return false;
    }
}

final class NoFns implements NalogBiClientInterface
{
    public function check(string $inn): NalogBiResult
    {
        return NalogBiResult::fromJson([]);
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

function makeDb(bool $withMigration034 = true): MysqlOnSqlite
{
    $db = new MysqlOnSqlite('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, telegram_id INTEGER UNIQUE, telegram_username TEXT, telegram_bot_blocked INTEGER DEFAULT 0, last_active_at TEXT,
        first_paid_at TEXT, referred_by_user_id INTEGER, own_referral_code TEXT, used_referral_code TEXT'
        . ($withMigration034 ? ', referral_source TEXT, referred_at TEXT' : '') . ')');
    $db->exec('CREATE TABLE tariffs (code TEXT PRIMARY KEY, name TEXT, max_tracked_issuers INTEGER, duration_days INTEGER)');
    $db->exec("INSERT INTO tariffs VALUES ('free', 'Free', 10, 14)");
    $db->exec("CREATE TABLE subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, tariff_code TEXT, status TEXT, current_period_end TEXT)");
    $db->exec('CREATE TABLE watchlist (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, issuer_id INTEGER, security_id INTEGER)');
    $db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, short_name TEXT, full_name TEXT, inn TEXT)');
    $db->exec('CREATE TABLE bot_dialog_state (user_id INTEGER PRIMARY KEY, state TEXT NOT NULL, context_json TEXT)');
    $db->exec("CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, amount TEXT, status TEXT, paid_at TEXT)");
    if ($withMigration034) {
        $db->exec("CREATE TABLE partners (user_id INTEGER PRIMARY KEY, promo_code TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT 'active', reward_percent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec('CREATE TABLE bot_delayed_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, kind TEXT, due_at TEXT, sent_at TEXT)');
    }

    return $db;
}

const ADMIN = 1001;
const PARTNER = 2002;

$db = makeDb();
$chat = new Chat();
$bot = new BotCommandHandler($db, $chat, new IssuerMatcher($db), ADMIN, new NoFns(), 'BondKeeperTestBot');
$partners = new PartnerProgram($db);

/** Человек пишет боту; возвращает тексты ответов бота на это сообщение. */
$say = static function (int $telegramId, string $text, ?string $username = null) use ($bot, $chat): array {
    // Telegram присылает имя пользователя в каждом сообщении — запоминаем его с первого раза.
    static $names = [];
    $username = $names[$telegramId] ??= $username;
    $from = count($chat->sent);
    $bot->handleUpdate(['update_id' => 1, 'message' => ['chat' => ['id' => $telegramId], 'from' => ['id' => $telegramId, 'username' => $username], 'text' => $text]]);

    return array_column(array_slice($chat->sent, $from), 'text');
};
/** Человек нажимает кнопку под сообщением; возвращает [всплывающий ответ, новые сообщения бота]. */
$press = static function (int $telegramId, string $data) use ($bot, $chat): array {
    $from = count($chat->sent);
    $bot->handleUpdate(['update_id' => 1, 'callback_query' => ['id' => 'q', 'data' => $data, 'from' => ['id' => $telegramId],
        'message' => ['message_id' => 5, 'chat' => ['id' => $telegramId], 'text' => '']]]);

    return [end($chat->toasts), array_column(array_slice($chat->sent, $from), 'text')];
};
/** Прошло $seconds секунд: сдвигаем срок отложенных сообщений и даём боту их отправить. */
$wait = static function (int $seconds) use ($db, $bot, $chat): array {
    $db->exec("UPDATE bot_delayed_messages SET due_at = datetime(due_at, '-{$seconds} seconds') WHERE sent_at IS NULL");
    $from = count($chat->sent);
    $bot->sendDueDelayedMessages();

    return array_slice($chat->sent, $from);
};
$user = static fn (int $telegramId): array => $db->query("SELECT u.id, u.used_referral_code, u.referral_source, u.referred_at IS NOT NULL AS has_date,
        (SELECT p.telegram_id FROM users p WHERE p.id = u.referred_by_user_id) AS partner_telegram_id FROM users u WHERE u.telegram_id = {$telegramId}")->fetch() ?: [];
$state = static fn (int $telegramId): ?string => ($db->query("SELECT s.state FROM bot_dialog_state s JOIN users u ON u.id = s.user_id WHERE u.telegram_id = {$telegramId}")->fetchColumn()) ?: null;
$isGreeting = static fn (string $text): bool => str_starts_with($text, '<b>Знакомство</b> 👋');

// =====================================================================
echo "--- администратор назначает промокод ---\n";
$say(ADMIN, '/start', 'owner');
$say(PARTNER, '/start', 'Ivan_Partner');
$wait(60);   // обоим пришло предложение ввести промокод — здесь оно не проверяется

check('человека, который не нажимал «Старт», назначить нельзя', true, str_contains($say(ADMIN, '/partner @nobody IVAN10')[0], 'не найден среди пользователей бота'));
check('промокод кириллицей или слишком короткий — отказ с подсказкой', [true, true],
    [str_contains($say(ADMIN, '/partner @ivan_partner ИВАН10')[0], 'латинские буквы, цифры'), str_contains($say(ADMIN, '/partner @ivan_partner ab')[0], 'от 3 до 32 символов')]);
$reply = $say(ADMIN, '/partner @ivan_partner ivan10 20')[0];
check('назначение: имя без учёта регистра, код приводится к верхнему регистру, в ответе — процент и готовая ссылка',
    "Партнёр назначен ✅\n@Ivan_Partner, Telegram ID 2002\nПромокод: IVAN10\nПроцент вознаграждения: 20 %\nСсылка для клиентов: https://t.me/BondKeeperTestBot?start=IVAN10", $reply);
check('в базе: партнёр, промокод, процент', ['IVAN10', 'active', '20'], array_values(array_intersect_key($partners->partner($user(PARTNER)['id']), ['promo_code' => 1, 'status' => 1, 'reward_percent' => 1])));
check('без имени и кода — подсказка по командам', true, str_starts_with($say(ADMIN, '/partner')[0], 'Команды партнёрской программы:'));
$press(PARTNER, 'promo:skip');
check('не администратору команды не отвечают', true, str_starts_with($say(PARTNER, '/partner @owner HACK1')[0], 'Не понимаю эту команду'));
check('…и партнёром он от этого не стал', null, $partners->partner($user(ADMIN)['id']));

$say(3003, '/start', 'second');
$wait(60);
check('тот же промокод второму человеку не выдать', true, str_contains($say(ADMIN, '/partner @second IVAN10')[0], 'уже занят другим партнёром'));
check('назначение по числовому Telegram ID, без процента', true, str_contains($say(ADMIN, '/partner 3003 Second_1')[0], "Промокод: SECOND_1\nПроцент вознаграждения: не задан"));

// =====================================================================
echo "\n--- клиент пришёл по ссылке с промокодом ---\n";
$replies = $say(4004, '/start IVAN10', 'anna');
check('приветствие, затем сразу сообщение о промокоде', [2, true, "Промокод IVAN10 применён ✅\nСкидка будет применена при первой оплате подписки."],
    [count($replies), $isGreeting($replies[0]), $replies[1]]);
check('в базе: кто привёл, какой код, откуда', ['IVAN10', 'link', 1, PARTNER], [$user(4004)['used_referral_code'], $user(4004)['referral_source'], (int) $user(4004)['has_date'], (int) $user(4004)['partner_telegram_id']]);
check('предложение ввести промокод такому клиенту не приходит', [], $wait(60));
check('регистр в ссылке не важен', 'IVAN10', (static function () use ($say, $user) { $say(4005, '/start ivan10'); return $user(4005)['used_referral_code']; })());

$replies = $say(4006, '/start NOSUCHCODE');
check('ссылка с несуществующим кодом: обычное приветствие без ошибок', [1, true, null], [count($replies), $isGreeting($replies[0]), $user(4006)['used_referral_code']]);
$prompt = $wait(10);
check('…и через 10 секунд — предложение ввести промокод', [1, 4006], [count($prompt), $prompt[0]['chat'] ?? null]);

// =====================================================================
echo "\n--- клиент вводит промокод сам ---\n";
$replies = $say(5005, '/start', 'boris');
check('новому пользователю сначала приходит только «Знакомство»', [1, true], [count($replies), $isGreeting($replies[0])]);
check('через 5 секунд ещё ничего', [], $wait(5));
$prompt = $wait(5);
check('через 10 секунд — предложение ввести промокод с кнопкой «Нет промокода»',
    ["У тебя есть промокод? 🎟\nПришли его сообщением — и получишь скидку на первую оплату подписки.", 'Нет промокода', 'promo:skip'],
    [$prompt[0]['text'] ?? null, $prompt[0]['markup']['inline_keyboard'][0][0]['text'] ?? null, $prompt[0]['markup']['inline_keyboard'][0][0]['callback_data'] ?? null]);
check('повторно предложение не приходит', [], $wait(600));
check('опечатка: бот просит прислать ещё раз и продолжает ждать', ["Такого промокода нет 😕\nПроверь, нет ли опечатки, и пришли его ещё раз.", 'awaiting_promo_code'], [$say(5005, 'IVAN1O')[0], $state(5005)]);
check('верный код (в любом регистре, с пробелами) принят', "Промокод IVAN10 принят ✅\nСкидка будет применена при первой оплате подписки.", $say(5005, '  Ivan10 ')[0]);
check('в базе: источник — ввёл сам; бот больше не ждёт промокод', ['IVAN10', 'manual', PARTNER, null], [$user(5005)['used_referral_code'], $user(5005)['referral_source'], (int) $user(5005)['partner_telegram_id'], $state(5005)]);
check('после этого обычный текст — снова «Не понимаю эту команду»', true, str_starts_with($say(5005, 'IVAN10')[0], 'Не понимаю эту команду'));

$say(5006, '/start');
$wait(10);
[$toast] = $press(5006, 'promo:skip');
check('«Нет промокода»: бот перестаёт ждать и подсказывает, где ввести позже', ["Хорошо 👌\nЕсли промокод появится, его можно ввести в разделе «Подписка 🎁».", null], [end($chat->edits), $state(5006)]);

$say(5007, '/start');
$say(5007, 'Выбор компаний 📝');
$press(5007, 'iss:add_menu');
$wait(10);
check('человек за 10 секунд успел начать поиск компании — предложение приходит, но поиск не перебивается', 'awaiting_issuer_search', $state(5007));

$say(5008, '/start');
$wait(10);
$db->exec("UPDATE bot_dialog_state SET context_json = '{\"until\": 1}' WHERE state = 'awaiting_promo_code'");
check('промокод бот ждёт час: позже текст понимается как обычно', [true, null], [str_starts_with($say(5008, 'привет')[0], 'Не понимаю эту команду'), $state(5008)]);

check('кто уже пользовался ботом, по «Старту» предложение не получает', [], (static function () use ($say, $wait) { $say(5006, '/start'); return $wait(60); })());

// =====================================================================
echo "\n--- раздел «Подписка 🎁» ---\n";
$from = count($chat->sent);
$say(5006, 'Подписка 🎁');
$screen = $chat->sent[$from];
check('пока промокода нет — под экраном кнопка «Ввести промокод 🎟», в тексте промокода нет', ['Ввести промокод 🎟', 'promo:enter', false],
    [$screen['markup']['inline_keyboard'][0][0]['text'] ?? null, $screen['markup']['inline_keyboard'][0][0]['callback_data'] ?? null, str_contains($screen['text'], 'Промокод')]);
[, $messages] = $press(5006, 'promo:enter');
check('кнопка: бот просит прислать промокод и ждёт его', [['Пришли промокод сообщением 🎟'], 'awaiting_promo_code'], [$messages, $state(5006)]);
check('промокод второго партнёра принят', true, str_starts_with($say(5006, 'second_1')[0], 'Промокод SECOND_1 принят ✅'));
$from = count($chat->sent);
$say(5006, 'Подписка 🎁');
$screen = $chat->sent[$from];
check('после ввода: в тексте — промокод и скидка, под экраном снова главная клавиатура', [true, true],
    [str_contains($screen['text'], "Промокод: <b>SECOND_1</b> — скидка на первую оплату\n"), isset($screen['markup']['keyboard'])]);

// =====================================================================
echo "\n--- правила ---\n";
check('сменить промокод нельзя: ни ссылкой, ни вводом', ['IVAN10', 'У тебя уже применён промокод IVAN10.'],
    [(static function () use ($say, $user) { $say(4004, '/start SECOND_1'); return $user(4004)['used_referral_code']; })(), $press(4004, 'promo:enter')[0]]);
$press(PARTNER, 'promo:enter');
check('свой промокод применить нельзя', ['Свой промокод применить нельзя 🙂', null], [$say(PARTNER, 'IVAN10')[0], $user(PARTNER)['used_referral_code']]);
check('…а чужой партнёр применить может', true, (static function () use ($press, $say) { $press(PARTNER, 'promo:enter'); return str_starts_with($say(PARTNER, 'SECOND_1')[0], 'Промокод SECOND_1 принят'); })());

$say(6006, '/start');
$wait(10);
$db->exec("UPDATE users SET first_paid_at = '2026-10-01 10:00:00' WHERE telegram_id = 6006");
check('после первой оплаты промокод не принимается', ['Промокод действует только на первую оплату подписки.', null], [$say(6006, 'IVAN10')[0], $user(6006)['used_referral_code']]);
check('…и кнопки в «Подписке» у него уже нет', true, (static function () use ($say, $chat) { $from = count($chat->sent); $say(6006, 'Подписка 🎁'); return isset($chat->sent[$from]['markup']['keyboard']); })());
check('скидка положена тому, у кого есть промокод и не было оплат', ['IVAN10', null], [$partners->discountCode($user(4004)['id']), $partners->discountCode($user(6006)['id'])]);
$db->exec("UPDATE users SET first_paid_at = '2026-10-05 12:00:00' WHERE telegram_id = 4004");
check('после первой оплаты скидки больше нет — промокод одноразовый', null, $partners->discountCode($user(4004)['id']));

check('партнёр приостановлен: по его коду новые клиенты не записываются', [true, null],
    [str_contains($say(ADMIN, '/partner_off @ivan_partner')[0], 'приостановлен: по промокоду IVAN10 новые клиенты не записываются'),
        (static function () use ($say, $user) { $say(7007, '/start IVAN10'); return $user(7007)['used_referral_code']; })()]);
check('…прежние клиенты остаются за ним; после возврата код снова работает', [PARTNER, 'IVAN10'],
    [(int) $user(5005)['partner_telegram_id'], (static function () use ($say, $press, $user) { $say(ADMIN, '/partner_on @ivan_partner'); $press(7007, 'promo:enter'); $say(7007, 'IVAN10'); return $user(7007)['used_referral_code']; })()]);
$reply = $say(ADMIN, '/partner @ivan_partner IVAN2026')[0];
check('смена промокода: прежний перестаёт действовать, клиенты остаются, процент сохраняется', [true, true, PARTNER, '20'],
    [str_contains($reply, "Партнёр обновлён ✅") && str_contains($reply, 'Прежний промокод IVAN10 больше не действует'),
        str_starts_with((static function () use ($say, $press) { $say(7008, '/start'); $press(7008, 'promo:enter'); return $say(7008, 'IVAN10')[0]; })(), 'Такого промокода нет'),
        (int) $user(4004)['partner_telegram_id'], $partners->partner($user(PARTNER)['id'])['reward_percent']]);

// =====================================================================
echo "\n--- итоги за месяц ---\n";
// Клиенты Ивана: 4004 и 4005 (по ссылке), 5005 и 7007 (ввели сами). Клиенты второго партнёра: 5006 и сам Иван.
$pay = static function (int $telegramId, string $amount, string $paidAt, string $status = 'succeeded') use ($db, $user): void {
    $db->prepare('INSERT INTO payments (user_id, amount, status, paid_at) VALUES (?, ?, ?, ?)')->execute([$user($telegramId)['id'], $amount, $status, $paidAt]);
};
$db->exec("UPDATE users SET referred_at = '2026-09-20 10:00:00' WHERE telegram_id = 4005");   // привели в прошлом месяце
$db->exec("UPDATE users SET referred_at = '2026-10-10 10:00:00' WHERE telegram_id IN (4004, 5005, 7007, 5006, 2002)");
$pay(4004, '1490.00', '2026-10-05 12:00:00');
$pay(4004, '1490.00', '2026-10-28 12:00:00');
$pay(5005, '990.00', '2026-10-31 23:59:59');
$pay(5005, '990.00', '2026-11-01 00:00:00');             // уже ноябрь
$pay(4005, '1490.00', '2026-10-15 09:00:00', 'failed');   // неуспешный платёж не считается
$pay(5006, '500.00', '2026-10-20 09:00:00');
$db->exec("UPDATE users SET first_paid_at = '2026-10-31 23:59:59' WHERE telegram_id = 5005");

$report = $partners->report('2026-10');
$ivan = $report[0];
check('первый партнёр: привёл четверых (двое по ссылке, двое ввели сами), за октябрь — троих', [4, 2, 2, 3],
    [$ivan['referred_total'], $ivan['referred_by_link'], $ivan['referred_manual'], $ivan['referred_in_month']]);
check('…оплаты за октябрь: два клиента, три платежа, 3970.00; ноябрьский и неуспешный не вошли', [2, 3, '3970.00', 2],
    [$ivan['paying_clients_in_month'], $ivan['payments_in_month'], $ivan['amount_in_month'], $ivan['paid_clients_total']]);
check('…впервые оплатили: в октябре двое, в ноябре никто', [2, 0], [$ivan['first_paid_in_month'], $partners->report('2026-11')[0]['first_paid_in_month']]);
check('…вознаграждение — 20 % от суммы', '794.00', $ivan['reward_in_month']);
check('второй партнёр: процент не задан — сумма есть, вознаграждение не посчитано', [2, '500.00', null], [$report[1]['referred_total'], $report[1]['amount_in_month'], $report[1]['reward_in_month']]);
check('ноябрь: один платёж на 990.00', ['990.00', '198.00', 0], [$partners->report('2026-11')[0]['amount_in_month'], $partners->report('2026-11')[0]['reward_in_month'], $partners->report('2026-11')[0]['referred_in_month']]);

$text = $say(ADMIN, '/partners 2026-10')[0];
check('команда /partners: те же числа текстом', [true, true, true, true, true],
    [str_starts_with($text, "Партнёры — итоги за 2026-10\n\n1. @Ivan_Partner · IVAN2026 · 20 %\n"),
        str_contains($text, '   привёл: 4 (за месяц 3; по ссылке 2, ввели сами 2)'),
        str_contains($text, '   оплаты за месяц: клиентов 2 (впервые 2), платежей 3, сумма 3 970.00 ₽'),
        str_contains($text, '   вознаграждение: 794.00 ₽'),
        str_ends_with($text, 'Всего: оплат на 4 470.00 ₽, вознаграждений на 794.00 ₽')]);
check('неверный месяц — понятный ответ', true, str_contains($say(ADMIN, '/partners октябрь')[0], 'Месяц должен быть в виде ГГГГ-ММ'));

// =====================================================================
echo "\n--- настройки и миграция ---\n";
$configFile = tempnam(sys_get_temp_dir(), 'bk_bot_');
file_put_contents($configFile, "<?php\nreturn ['bot_token' => '1:AA', 'admin_telegram_id' => 5, 'bot_username' => '@BondKeeperBot'];\n");
check('имя бота из настроек читается без «@»; если его нет — пусто', ['BondKeeperBot', ''], [TelegramBotConfig::fromFile($configFile)->botUsername,
    (static function () use ($configFile) { file_put_contents($configFile, "<?php\nreturn ['bot_token' => '1:AA'];\n"); return TelegramBotConfig::fromFile($configFile)->botUsername; })()]);
unlink($configFile);
$plainBot = new BotCommandHandler($db, $chat, new IssuerMatcher($db), ADMIN, new NoFns());
$from = count($chat->sent);
$plainBot->handleUpdate(['update_id' => 1, 'message' => ['chat' => ['id' => ADMIN], 'from' => ['id' => ADMIN, 'username' => 'owner'], 'text' => '/partner 3003 SECOND_2']]);
check('имя бота не задано — ссылка показывается шаблоном', true, str_contains($chat->sent[$from]['text'], 'https://t.me/ИМЯ_БОТА?start=SECOND_2'));

$oldDb = makeDb(false);
$oldChat = new Chat();
$oldBot = new BotCommandHandler($oldDb, $oldChat, new IssuerMatcher($oldDb), ADMIN, new NoFns());
$oldBot->handleUpdate(['update_id' => 1, 'message' => ['chat' => ['id' => 9], 'from' => ['id' => 9], 'text' => '/start IVAN10']]);
check('миграция 034 не применена: «Старт» со ссылкой работает как обычный, отложенных сообщений нет, бот не падает',
    [1, true, 0, null], [count($oldChat->sent), $isGreeting($oldChat->sent[0]['text']), $oldBot->sendDueDelayedMessages(), $oldBot->secondsUntilNextDelayedMessage()]);
$oldBot->handleUpdate(['update_id' => 1, 'message' => ['chat' => ['id' => ADMIN], 'from' => ['id' => ADMIN], 'text' => '/partners']]);
check('…администратору команда отвечает, что нужна миграция', true, str_contains(end($oldChat->sent)['text'], '034_partner_program.sql'));
check('проверка структуры называет миграцию', [true, null], [str_contains((string) PartnerProgram::schemaProblem($oldDb), '034_partner_program.sql'), PartnerProgram::schemaProblem($db)]);

$queue = new DelayedMessages($db);
$queue->schedule(1, DelayedMessages::KIND_PROMO_PROMPT, 10);
$queue->schedule(1, DelayedMessages::KIND_PROMO_PROMPT, 10);
check('очередь: одно и то же сообщение не ставится дважды; до него около 10 секунд', [1, true],
    [(int) $db->query('SELECT COUNT(*) FROM bot_delayed_messages WHERE user_id = 1 AND sent_at IS NULL')->fetchColumn(), in_array($queue->secondsUntilNext(), [9, 10], true)]);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
