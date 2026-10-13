<?php

declare(strict_types=1);

/**
 * Офлайн-проверка нового меню бота и раздела «Настройки ⚙️» (ТЗ
 * пользователя от 12.10.2026, docs/BOT_UX_SPEC.md, раздел 10):
 *   - главная клавиатура, подменю «Выбор компаний», переезд «Статуса»;
 *   - отключение и включение напоминаний (весь список / по эмитентам);
 *   - свой интервал для купонов, амортизаций, оферт и погашений;
 *   - выбор выпусков эмитента.
 * Тексты кнопок и сообщений сверяются дословно с ТЗ.
 *
 * Как и tests/test_bot_ux_screens.php — через Reflection к приватным
 * методам BotCommandHandler, в обход handleUpdate()/ensureUser() (там
 * ON DUPLICATE KEY UPDATE, недоступный в SQLite).
 *
 * Запуск (из корня репозитория):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_bot_settings.php
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Fns\NalogBiClientInterface;
use BondKeeper\Fns\NalogBiResult;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Telegram\BotCommandHandler;
use BondKeeper\Telegram\NotificationPreferences;
use BondKeeper\Telegram\TelegramClientInterface;

final class RecordingTelegram implements TelegramClientInterface
{
    /** @var array{text: string, keyboard: ?array}|null */
    public ?array $lastEdit = null;
    /** @var array<int, array{text: string, keyboard: ?array}> */
    public array $sent = [];

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null, bool $disableWebPagePreview = false): bool
    {
        $this->sent[] = ['text' => $text, 'keyboard' => $replyMarkup];

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
        $this->lastEdit = ['text' => $text, 'keyboard' => $replyMarkup];

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
        return NalogBiResult::fromJson(['rows' => []]);
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

function makeDb(bool $withSettings = true): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, inn TEXT, full_name TEXT, short_name TEXT)');
    $db->exec("CREATE TABLE securities (id INTEGER PRIMARY KEY, isin TEXT, secid TEXT, issuer_id INTEGER, short_name TEXT, status TEXT DEFAULT 'active')");
    $db->exec("CREATE TABLE watchlist (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, issuer_id INTEGER, security_id INTEGER, added_at TEXT DEFAULT (datetime('now')), UNIQUE(user_id, issuer_id))");
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, telegram_id INTEGER)');
    $db->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, tariff_code TEXT, status TEXT, current_period_end TEXT)');
    $db->exec('CREATE TABLE tariffs (code TEXT PRIMARY KEY, name TEXT, max_tracked_issuers INTEGER, duration_days INTEGER)');
    $db->exec('CREATE TABLE fns_blocks (issuer_id INTEGER PRIMARY KEY, is_fns_blocked INTEGER, block_date TEXT, active_bank_count INTEGER, blocked_amount TEXT, reason TEXT, verification TEXT, date_verification TEXT)');
    $db->exec('CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, outlook TEXT, last_action_date TEXT)');
    $db->exec('CREATE TABLE issuer_spv_links (spv_inn TEXT PRIMARY KEY, issuer_id INTEGER, spv_name TEXT, note TEXT)');
    $db->exec('CREATE TABLE bot_dialog_state (user_id INTEGER PRIMARY KEY, state TEXT, context_json TEXT)');
    $db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, issuer_id INTEGER, security_id INTEGER, event_type_code TEXT, event_date TEXT, reminder_days INTEGER)');
    $db->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_id INTEGER, channel TEXT, status TEXT, failure_reason TEXT)');
    if ($withSettings) {
        $db->exec('CREATE TABLE user_notification_settings (user_id INTEGER PRIMARY KEY, reminders_enabled INTEGER NOT NULL DEFAULT 1, coupon_days INTEGER, amortization_days INTEGER, redemption_days INTEGER, offer_days INTEGER)');
        $db->exec('CREATE TABLE user_reminder_mutes (user_id INTEGER, issuer_id INTEGER, PRIMARY KEY (user_id, issuer_id))');
        $db->exec('CREATE TABLE user_excluded_securities (user_id INTEGER, security_id INTEGER, PRIMARY KEY (user_id, security_id))');
    }
    $db->exec("INSERT INTO tariffs VALUES ('free', 'Free', 10, 14)");
    $db->exec("INSERT INTO users (id, telegram_id) VALUES (1, 111), (2, 222)");
    $db->exec("INSERT INTO subscriptions (user_id, tariff_code, status, current_period_end) VALUES (1, 'free', 'active', '2099-01-01')");
    $db->exec("INSERT INTO issuers (id, inn, full_name, short_name) VALUES (1, '7700000001', 'ПАО Альфа', 'Альфа'), (2, '7700000002', 'ПАО Бета', 'Бета'), (3, '7700000003', 'ПАО Гамма', 'Гамма')");
    // У «Альфы» десять выпусков в обращении и один погашенный; у «Беты» — один.
    for ($i = 1; $i <= 10; $i++) {
        $db->exec(sprintf("INSERT INTO securities (id, isin, issuer_id, short_name) VALUES (%d, 'RU000A0ALF%02d', 1, 'Альфа БО-%02d')", $i, $i, $i));
    }
    $db->exec("INSERT INTO securities (id, isin, issuer_id, short_name, status) VALUES (11, 'RU000A0ALF11', 1, 'Альфа БО-11', 'matured')");
    $db->exec("INSERT INTO securities (id, isin, issuer_id, short_name) VALUES (20, 'RU000A0BET01', 2, 'Бета БО-01'), (30, 'RU000A0GAM01', 3, 'Гамма БО-01')");
    $db->exec('INSERT INTO watchlist (user_id, issuer_id) VALUES (1, 1), (1, 2)');

    return $db;
}

$db = makeDb();
$telegram = new RecordingTelegram();
$handler = new BotCommandHandler($db, $telegram, new IssuerMatcher($db), 555, new NoFns());
$ref = new ReflectionClass($handler);
$call = static function (string $method, array $args) use ($ref, $handler): mixed {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($handler, $args);
};
/** Нажатие инлайн-кнопки раздела «Настройки»: тост и то, что стало на экране. */
$press = static function (string $data, int $userId = 1) use ($call, $telegram): array {
    $telegram->lastEdit = null;
    $toast = $call('dispatchSettingsCallback', [$userId, 100, 200, $data]);

    return [$toast, $telegram->lastEdit['text'] ?? null, array_map(static fn (array $row): array => array_column($row, 'text'), $telegram->lastEdit['keyboard']['inline_keyboard'] ?? [])];
};
$buttons = static fn (?array $keyboard): array => array_map(
    static fn (array $row): array => array_map(static fn (array $b): string => $b['text'] . ' → ' . ($b['callback_data'] ?? ''), $row),
    $keyboard['inline_keyboard'] ?? [],
);
$prefs = new NotificationPreferences($db);

// =====================================================================
echo "--- главное меню ---\n";
$keyboard = $call('mainMenuKeyboard', []);
check('клавиатура: на месте «Статус» — «Настройки ⚙️», формат тот же (без цвета)',
    [['Выбор компаний 📝', 'Подписка 🎁'], [['text' => 'Настройки ⚙️'], 'О сервисе 🌐'], ['Помощь 🛠']],
    [array_column($keyboard['keyboard'][0], 'text'), [$keyboard['keyboard'][1][0], $keyboard['keyboard'][1][1]['text']], array_column($keyboard['keyboard'][2], 'text')]);
check('/menu возвращает клавиатуру меню одним коротким сообщением', ['Меню 👇', ['Выбор компаний 📝', 'Подписка 🎁'], true],
    [$call('matchKnownCommand', [1, '/menu'])['text'], array_column($call('matchKnownCommand', [1, '/menu'])['keyboard']['keyboard'][0], 'text'),
        str_contains($call('helpText', []), '/menu — вернуть меню, если оно пропало')]);
$start = $call('handleStart', []);
check('знакомство: рассказано про «Настройки ⚙️», кнопки «Статус 📍» в тексте больше нет',
    [true, false, true], [str_contains($start, "<b>Настройки ⚙️</b> — жми сюда, чтобы настроить отправку уведомлений и напоминаний.\n"), str_contains($start, 'Статус 📍'),
        str_contains($start, '<b>Выбор компаний 📝</b> — жми сюда, чтобы составить или отредактировать список эмитентов, за которыми нужно следить. '
            . "Тут же можно получить актуальную информацию по конкретному эмитенту или всему списку.\n")]);

$legacy = $call('matchKnownCommand', [1, 'Статус 📍']);
check('старая кнопка «Статус 📍» (у кого клавиатура ещё прежняя): статус открывается, а перед ним приходит новая клавиатура',
    ['Ты можешь уточнить текущий статус по конкретной компании или по всему списку 📍', true, 'Настройки ⚙️'],
    [$legacy['text'], str_contains($legacy['before']['text'], 'Меню обновилось'), $legacy['before']['keyboard']['keyboard'][1][0]['text']]);
check('ответы без своих кнопок несут главную клавиатуру — она обновится и без /start', [true, true],
    [isset($call('matchKnownCommand', [1, 'Подписка 🎁'])['keyboard']['keyboard']), isset($call('matchKnownCommand', [1, '/help'])['keyboard']['keyboard'])]);

echo "\n--- «Выбор компаний» ---\n";
$issuers = $call('matchKnownCommand', [1, 'Выбор компаний 📝']);
check('сообщение из ТЗ', 'Настройка списка и проверка текущего статуса по эмитентам ⚙️', $issuers['text']);
check('четыре кнопки: две прежние со смайликами, статус и карточка', [
    ['Добавить новых эмитентов ➕ → iss:add_menu'], ['Редактировать список ✏️ → iss:edit_list'], ['Проверить статус 📌 → stat:menu'], ['Карточка эмитента 📋 → iss:card'],
], $buttons($issuers['keyboard']));

$telegram->lastEdit = null;
$call('dispatchStatusCallback', [1, 100, 200, 'stat:menu']);
check('«Проверить статус 📌»: новый текст и две кнопки со смайликами', [
    'Ты можешь уточнить текущий статус по конкретной компании или по всему списку 📍', [['Указать компанию ❤️ → stat:pick_menu'], ['Весь список 📋 → stat:all']],
], [$telegram->lastEdit['text'], $buttons($telegram->lastEdit['keyboard'])]);
$telegram->lastEdit = null;
$call('dispatchStatusCallback', [2, 100, 200, 'stat:menu']);
check('у пользователя с пустым списком — прежнее «список пуст»', true, str_contains((string) $telegram->lastEdit['text'], 'пуст 😢'));

$telegram->sent = [];
$telegram->lastEdit = null;
$toast = $call('dispatchIssuerCallback', [1, 100, 200, 'iss:card']);
check('«Карточка эмитента 📋»: отдельное сообщение-заглушка, меню остаётся на экране',
    [null, null, "Данный функционал находится в разработке и будет доступен позже.\nОжидайте анонса на канале @Bond_Keeper 😉"],
    [$toast, $telegram->lastEdit, $telegram->sent[0]['text'] ?? null]);

// =====================================================================
echo "\n--- «Настройки ⚙️» ---\n";
$settings = $call('matchKnownCommand', [1, 'Настройки ⚙️']);
check('сообщение дословно из ТЗ; пока ничего не менялось — без сводки',
    "Ты можешь самостоятельно настраивать уведомления так, как тебе удобно 😉\nСейчас ты по умолчанию получаешь уведомления по всем выпускам облигаций эмитентов в твоём списке. "
    . 'Также ты получаешь напоминания по всем выплатам эмитентов в списке за 1 рабочий день до даты самой выплаты, а по офертам за 30 дней.', $settings['text']);
check('две кнопки', [['Напоминания по выплатам 🗓 → set:reminders'], ['Уведомления по эмитентам ⏰ → set:bonds']], $buttons($settings['keyboard']));

[$toast, $text, $labels] = $press('set:reminders');
check('«Напоминания по выплатам 🗓»: текст и шесть кнопок в порядке из ТЗ', [
    'Ты можешь отключить напоминания или задать свой интервал ⌛️',
    [['Отключить напоминания ❌'], ['Включить напоминания ✅'], ['Свой интервал для купонов ⌛️'], ['Свой интервал для амортизации ⌛️'], ['Свой интервал для оферт ⌛️'], ['Свой интервал для погашений ⌛️']],
], [$text, $labels]);

echo "\n--- отключение и включение напоминаний ---\n";
check('«Включить напоминания ✅», когда ничего не отключено', 'Напоминания включены ✅', $press('set:on')[1]);
check('«Отключить напоминания ❌»: выбор — весь список или эмитенты',
    ['Ты можешь отключить напоминания для всего списка или выбрать конкретных эмитентов ❌', [['Отключить для всего списка 📋'], ['Выбрать эмитентов ➕']]], array_slice($press('set:off'), 1));
[$toast, $text] = $press('set:off_all');
check('«Отключить для всего списка 📋»: напоминания отключены', ['Отключено', 'Напоминания отключены для всего списка ❌', false], [$toast, $text, $prefs->get(1)['reminders_enabled']]);
check('у другого пользователя ничего не изменилось', true, $prefs->get(2)['reminders_enabled']);
check('«Включить напоминания ✅», когда есть отключённое: выбор — весь список или эмитенты',
    ['Ты можешь включить напоминания для всего списка или выбрать конкретных эмитентов ✅', [['Включить для всего списка 📋'], ['Выбрать эмитентов ➕']]], array_slice($press('set:on'), 1));
[$toast, $text] = $press('set:on_all');
check('«Включить для всего списка 📋»', ['Включено', 'Напоминания включены ✅', true], [$toast, $text, $prefs->get(1)['reminders_enabled']]);

[, $text, $labels] = $press('set:off_pick');
check('«Отключить → Выбрать эмитентов»: список пользователя, у всех колокольчик', [true, [['🔔 Альфа'], ['🔔 Бета']]], [str_contains((string) $text, 'чтобы отключить напоминания'), $labels]);
[$toast, , $labels] = $press('set:mute:1');
check('нажатие на эмитента отключает напоминания по нему', ['Напоминания отключены', [['🔕 Альфа'], ['🔔 Бета']], [1]], [$toast, $labels, $prefs->mutedIssuerIds(1)]);
check('эмитента не из своего списка отключить нельзя', ['Этой компании уже нет в твоём списке.', [1]], [$press('set:mute:3')[0], $prefs->mutedIssuerIds(1)]);
[, $text, $labels] = $press('set:on_pick');
check('«Включить → Выбрать эмитентов»: только те, по кому отключено', [true, [['🔕 Альфа']]], [str_contains((string) $text, 'чтобы включить напоминания'), $labels]);
[$toast, $text] = $press('set:unmute:1');
check('включили последнего отключённого — «Напоминания включены ✅»', ['Напоминания включены', 'Напоминания включены ✅', []], [$toast, $text, $prefs->mutedIssuerIds(1)]);

$press('set:off_all');
[, , $labels] = $press('set:on_pick');
check('отключён весь список → «Выбрать эмитентов» показывает всех', [['🔕 Альфа'], ['🔕 Бета']], $labels);
[, , $labels] = $press('set:unmute:2');
check('включили одного: напоминания снова работают, остальные отключены поимённо', [true, [1], [['🔕 Альфа']]], [$prefs->get(1)['reminders_enabled'], $prefs->mutedIssuerIds(1), $labels]);
$press('set:on_all');

// Напоминание, которое не ушло из-за отключения, возвращается на оценку, когда напоминания включают.
$db->exec("INSERT INTO events (id, issuer_id, security_id, event_type_code, event_date, reminder_days) VALUES (1, 1, 1, 'R2', '2099-01-01', 30), (2, 1, 1, 'R1', '2020-01-01', 1), (3, 1, 1, 'A2', '2099-01-01', NULL)");
$db->exec("INSERT INTO notifications (user_id, event_id, channel, status, failure_reason) VALUES
    (1, 1, 'telegram', 'failed', '" . NotificationPreferences::SKIP_REMINDERS_OFF . "'),
    (1, 2, 'telegram', 'failed', '" . NotificationPreferences::SKIP_REMINDERS_OFF . "'),
    (1, 3, 'telegram', 'failed', '" . NotificationPreferences::SKIP_SECURITY_EXCLUDED . "')");
$press('set:off_all');
$press('set:on_all');
check('после включения: напоминание о будущей оферте снова ждёт отправки; о прошедшей выплате и не напоминания — нет', [2, 3],
    array_map('intval', $db->query('SELECT event_id FROM notifications WHERE user_id = 1 ORDER BY event_id')->fetchAll(PDO::FETCH_COLUMN)));

// =====================================================================
echo "\n--- свой интервал ---\n";
[, $text] = $press('set:int:coupon');
check('«Свой интервал для купонов ⌛️»: вопрос из ТЗ, бот ждёт число',
    ['За какое количество календарных дней до выплаты ты бы хотел получать напоминания? ⏰', 'awaiting_reminder_days', '{"kind":"coupon"}'],
    [$text, $db->query('SELECT state FROM bot_dialog_state WHERE user_id = 1')->fetchColumn(), $db->query('SELECT context_json FROM bot_dialog_state WHERE user_id = 1')->fetchColumn()]);
$reply = static fn (string $text): string => $call('handleFreeText', [1, 111, null, $text])['text'];
check('не число — просим ещё раз, ничего не сохранено', [true, null], [str_contains($reply('три'), 'Нужно целое число от 1 до 365'), $prefs->get(1)['custom']['coupon']]);
check('ноль и 400 — вне диапазона', [true, true, null], [str_contains($reply('0'), 'Нужно целое число'), str_contains($reply('400'), 'Нужно целое число'), $prefs->get(1)['custom']['coupon']]);
check('число принято: интервал сохранён, бот больше не ждёт числа',
    ["Готово ✅ Напоминания о купонах теперь будут приходить за 3 дн. до выплаты.\nЕсли этот день выпадет на выходной, напоминание придёт в последний рабочий день перед ним.", 3, false],
    [$reply('3'), $prefs->get(1)['days']['coupon'], $db->query('SELECT 1 FROM bot_dialog_state WHERE user_id = 1')->fetchColumn()]);
check('остальные виды — по умолчанию: выплаты за 1 день, оферты за 30', ['coupon' => 3, 'amortization' => 1, 'redemption' => 1, 'offer' => 30], $prefs->get(1)['days']);
$press('set:int:offer');
check('интервал для оферт', [true, 45], [str_contains($reply('45'), 'Напоминания об офертах теперь будут приходить за 45 дн. до даты оферты.'), $prefs->get(1)['days']['offer']]);
$press('set:int:amortization');
$press('set:int:redemption');
check('каждая кнопка задаёт свой вид: последняя нажатая — погашения', [true, 7, 1],
    [str_contains($reply('7'), 'о погашениях'), $prefs->get(1)['days']['redemption'], $prefs->get(1)['days']['amortization']]);
check('неизвестный вид в кнопке не принимается', null, $press('set:int:dividends')[0]);
check('свои интервалы видны скрипту напоминаний: вид → дни → эмитенты пользователя',
    ['coupon' => [3 => [1, 2]], 'redemption' => [7 => [1, 2]], 'offer' => [45 => [1, 2]]], $prefs->customIntervals());
check('в «Настройках» появилась сводка изменённого', true, str_ends_with($call('handleSettingsMenuEntry', [1])['text'],
    "\n\nТвои настройки сейчас:\n• напоминания о купонах — за 3 дн. до выплаты\n• напоминания об офертах — за 45 дн. до даты оферты\n• напоминания о погашениях — за 7 дн. до выплаты"));

// =====================================================================
echo "\n--- «Уведомления по эмитентам ⏰»: выбор выпусков ---\n";
check('текст и кнопка из ТЗ', ['Ты можешь выбрать конкретные выпуски облигаций для получения уведомлений и напоминаний по выплатам ✅', [['Выбрать эмитента ➕']]], array_slice($press('set:bonds'), 1));
check('«Выбрать эмитента ➕»: эмитенты из списка пользователя', ['Выбери эмитента:', [['Альфа'], ['Бета']]], array_slice($press('set:bonds_pick'), 1));
[, $text, $labels] = $press('set:bonds_iss:1:0');
check('выпуски эмитента: название и ISIN, по умолчанию отмечены все; погашенного выпуска в списке нет',
    [true, '✅ Альфа БО-01 · RU000A0ALF01', 10, ['Вперёд ▶️'], ['Отметить все ✅', 'Снять все ➕']],
    [str_contains((string) $text, 'Альфа: выпуски облигаций (стр. 1 из 2).') && str_contains((string) $text, 'Отмечено: 10 из 10.'), $labels[0][0], count($labels), $labels[8], $labels[9]]);
[$toast, $text, $labels] = $press('set:bond:2:0');
check('снята отметка с выпуска: он исключён, на экране плюс', ['Уведомления по выпуску отключены', '➕ Альфа БО-02 · RU000A0ALF02', true, [2]],
    [$toast, $labels[1][0], str_contains((string) $text, 'Отмечено: 9 из 10.'), $prefs->excludedSecurityIds(1)]);
[$toast, , $labels] = $press('set:bond:2:0');
check('отметка возвращена', ['Уведомления по выпуску включены', '✅ Альфа БО-02 · RU000A0ALF02', []], [$toast, $labels[1][0], $prefs->excludedSecurityIds(1)]);
[, , $labels] = $press('set:bonds_iss:1:1');
check('вторая страница: два выпуска и «Назад»', [['✅ Альфа БО-09 · RU000A0ALF09'], ['✅ Альфа БО-10 · RU000A0ALF10'], ['◀️ Назад']], array_slice($labels, 0, 3));
[$toast] = $press('set:bonds_all:1:0:0');
check('«Снять все ➕»: исключены все выпуски в обращении', ['Отметки сняты со всех выпусков', range(1, 10)], [$toast, $prefs->excludedSecurityIds(1, 1)]);
$press('set:bond:5:0');
check('после «снять все» вернули один выпуск', [1, 2, 3, 4, 6, 7, 8, 9, 10], $prefs->excludedSecurityIds(1, 1));
check('выпуск чужого эмитента (не из списка) отметить нельзя', 'Этой компании уже нет в твоём списке.', $press('set:bond:30:0')[0]);
check('…и открыть его выпуски тоже', 'Эта компания больше не в вашем списке отслеживания.', $press('set:bonds_iss:3:0')[1]);
check('в сводке — число выпусков без уведомлений', true, str_contains($call('handleSettingsMenuEntry', [1])['text'], '• выпусков без уведомлений: 9'));

echo "\n--- эмитент убран из списка — его настройки тоже ---\n";
$prefs->setIssuerMuted(1, 1, true);
$prefs->setSecurityExcluded(1, 20, true);
$call('removeIssuerFromEditList', [1, 100, 200, 1]);
check('отключённые напоминания и снятые отметки по «Альфе» забыты; настройки «Беты» целы', [[], [20]], [$prefs->mutedIssuerIds(1), $prefs->excludedSecurityIds(1)]);

echo "\n--- миграция 032 ещё не применена ---\n";
$bare = makeDb(false);
$bareTelegram = new RecordingTelegram();
$bareHandler = new BotCommandHandler($bare, $bareTelegram, new IssuerMatcher($bare), 555, new NoFns());
$bareCall = static function (string $method, array $args) use ($bareHandler): mixed {
    $m = (new ReflectionClass($bareHandler))->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($bareHandler, $args);
};
ob_start();
$toast = $bareCall('dispatchSettingsCallback', [1, 100, 200, 'set:off_all']);
ob_end_clean();
check('кнопки настроек не роняют бота: понятный ответ', 'Настройки сейчас недоступны — попробуй чуть позже.', $toast);
check('вход в «Настройки» и удаление эмитента из списка работают', [true, 'Убрано'],
    [str_starts_with($bareCall('handleSettingsMenuEntry', [1])['text'], 'Ты можешь самостоятельно настраивать'), $bareCall('removeIssuerFromEditList', [1, 100, 200, 1])]);
check('проверка структуры называет миграцию', true, str_contains((string) NotificationPreferences::schemaProblem($bare), '032_notification_settings.sql'));
check('с миграцией — замечаний нет', null, NotificationPreferences::schemaProblem($db));

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
