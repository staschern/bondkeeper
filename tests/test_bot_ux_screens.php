<?php

declare(strict_types=1);

/**
 * Офлайн-проверка форматирования экранов Статус/Подписка/О сервисе
 * (docs/BOT_UX_SPEC.md, разделы 4-6) — через Reflection напрямую к
 * приватным методам BotCommandHandler, В ОБХОД handleUpdate()/
 * ensureUser()/setDialogState() (те пишут через ON DUPLICATE KEY UPDATE,
 * недоступный в SQLite — тот же приём, что в Фазе 1 для EventPublisher).
 * Тестируем именно то, что реально хрупко: форматирование дат, склейка
 * нескольких рейтингов, подстановка чисел — не саму машину состояний.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=pdo_sqlite -d extension=mbstring tests/test_bot_ux_screens.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Telegram\BotCommandHandler;
use BondKeeper\Telegram\TelegramClientInterface;

final class FakeTelegramClient implements TelegramClientInterface
{
    /** @var array{chat_id: int, message_id: int, text: string, keyboard: ?array}|null последний вызов editMessageText() — для проверок содержимого/кнопок */
    public ?array $lastEdit = null;

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): bool
    {
        return true;
    }

    public function sendMessageReturningId(int $chatId, string $text, ?array $replyMarkup = null): ?int
    {
        return 999;
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        return true;
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool
    {
        $this->lastEdit = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'keyboard' => $replyMarkup];

        return true;
    }

    public function lastSendWasBlockedByUser(): bool
    {
        return false; // не нужно этому тесту (BotCommandHandler не читает этот флаг) — просто для соответствия интерфейсу
    }
}

$failures = 0;
$checks = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, inn TEXT, full_name TEXT, short_name TEXT)');
// UNIQUE(user_id, issuer_id) — упрощение боевой схемы для теста: в
// реальной MySQL уникальность обеспечивает STORED GENERATED
// issuer_only_key (NULL != NULL в обычном UNIQUE не сработал бы, раз
// security_id тут всегда NULL) — здесь всё тестируемое слежение именно
// "по эмитенту целиком", этого достаточно для проверки поведения при
// дубле.
$db->exec('CREATE TABLE watchlist (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, issuer_id INTEGER, security_id INTEGER, UNIQUE(user_id, issuer_id))');
$db->exec('CREATE TABLE fns_blocks (issuer_id INTEGER PRIMARY KEY, is_fns_blocked INTEGER, block_date TEXT, active_bank_count INTEGER, blocked_amount TEXT)');
$db->exec('CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, outlook TEXT, last_action_date TEXT)');
$db->exec('CREATE TABLE subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, tariff_code TEXT, status TEXT, current_period_end TEXT)');
$db->exec('CREATE TABLE tariffs (code TEXT PRIMARY KEY, name TEXT, max_tracked_issuers INTEGER, duration_days INTEGER)');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, telegram_id INTEGER)');
$db->exec('CREATE TABLE support_thread_map (admin_chat_id INTEGER, admin_message_id INTEGER, user_id INTEGER, PRIMARY KEY (admin_chat_id, admin_message_id))');
$db->exec('CREATE TABLE securities (id INTEGER PRIMARY KEY AUTOINCREMENT, isin TEXT, secid TEXT, issuer_id INTEGER)');

$db->exec("INSERT INTO tariffs (code, name, max_tracked_issuers, duration_days) VALUES ('free', 'Free', 10, 14)");

// Эмитент 1: заблокирован ФНС, два рейтинга (НКР + АКРА).
$db->exec("INSERT INTO issuers (id, inn, full_name, short_name) VALUES (1, '7706107510', 'ПАО Роснефть', 'Роснефть')");
$db->exec("INSERT INTO fns_blocks (issuer_id, is_fns_blocked, block_date, active_bank_count, blocked_amount) VALUES (1, 1, '2026-08-15', 3, '1500000.00')");
$db->exec("INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date) VALUES (1, 'nkr', 'AAA.ru', 'stable', '2026-07-01')");
$db->exec("INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date) VALUES (1, 'acra', 'AAA(RU)', 'positive', '2026-06-20')");

// Эмитент 2: без блокировок, без рейтинга.
$db->exec("INSERT INTO issuers (id, inn, full_name, short_name) VALUES (2, '7736050003', 'ПАО Газпром', 'Газпром')");

// Бумага эмитента 1 — для проверки "умного поиска" по ISIN/тикеру.
$db->exec("INSERT INTO securities (isin, secid, issuer_id) VALUES ('RU000A1035N9', 'RU000ROSN01', 1)");

// Ещё 10 эмитентов (id 3-12) — для проверки пагинации листалки (>8 на страницу).
$letters = ['Алроса', 'Башнефть', 'Вымпелком', 'Детский мир', 'Евраз', 'Лукойл', 'Магнит', 'Норникель', 'Полюс', 'Сургутнефтегаз'];
foreach ($letters as $i => $name) {
    $id = $i + 3;
    $db->exec("INSERT INTO issuers (id, inn, full_name, short_name) VALUES ({$id}, '000000000{$i}', '{$name} ПАО', '{$name}')");
}

$db->exec('INSERT INTO watchlist (user_id, issuer_id) VALUES (1, 1)');
$db->exec('INSERT INTO watchlist (user_id, issuer_id) VALUES (1, 2)');
$db->exec("INSERT INTO subscriptions (user_id, tariff_code, status, current_period_end) VALUES (1, 'free', 'active', '2026-09-21')");

$telegram = new FakeTelegramClient();
$handler = new BotCommandHandler($db, $telegram, new IssuerMatcher($db), 555);
$ref = new ReflectionClass($handler);

function callPrivate(ReflectionClass $ref, object $obj, string $method, array $args)
{
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($obj, $args);
}

// --- Статус: заблокированный эмитент с двумя рейтингами ---
$status1 = callPrivate($ref, $handler, 'formatIssuerStatus', [1, 'Роснефть', '7706107510']);
check('Статус: название и ИНН на месте', str_starts_with($status1, 'Роснефть | ИНН 7706107510'));
check('Статус: дата блокировки в формате дд.мм.гг', str_contains($status1, '15.08.26'));
check('Статус: количество банков', str_contains($status1, 'Количество заблокированных счетов: 3'));
check('Статус: сумма блокировки', str_contains($status1, '1500000.00'));
check('Статус: НКР с человекочитаемым названием агентства', str_contains($status1, 'Агентство: НКР | Рейтинг: AAA.ru | Прогноз: stable | Дата действия: 01.07.26'));
check('Статус: АКРА тоже присутствует (два рейтинга по одному эмитенту)', str_contains($status1, 'Агентство: АКРА | Рейтинг: AAA(RU)'));

// --- Статус: эмитент без блокировок и без рейтинга ---
$status2 = callPrivate($ref, $handler, 'formatIssuerStatus', [2, 'Газпром', '7736050003']);
check('Статус: "нет блокировки" при is_fns_blocked=0', str_contains($status2, 'Заблокированных счетов нет ✅'));
check('Статус: "без рейтинга" когда нет строк current_ratings', str_contains($status2, 'Эмитент без рейтинга'));

// --- Полный экран handleStatus() для пользователя с обоими эмитентами ---
$fullStatus = callPrivate($ref, $handler, 'handleStatus', [1])['text'];
check('handleStatus(): оба эмитента в ответе', str_contains($fullStatus, 'Роснефть') && str_contains($fullStatus, 'Газпром'));

// --- Пустой список ---
$emptyStatus = callPrivate($ref, $handler, 'handleStatus', [999]);
check('handleStatus(): пустой список — грустный смайлик из ТЗ', str_contains($emptyStatus['text'], 'пуст 😢'));
check('handleStatus(): пустой список даёт кнопку "Выбор компаний"', isset($emptyStatus['keyboard']['inline_keyboard'][0][0]['callback_data']) && $emptyStatus['keyboard']['inline_keyboard'][0][0]['callback_data'] === 'iss:add_menu');

// --- Подписка ---
$subscription = callPrivate($ref, $handler, 'handleSubscription', [1]);
check('Подписка: первая строка жирная, дальше пустая строка (не всё сплошняком)', str_contains($subscription, "Ваша текущая подписка: <b>Free</b>\n\n"));
check('Подписка: лимит тарифа жирным (10)', str_contains($subscription, 'Количество эмитентов доступных для отслеживания: <b>10</b>'));
check('Подписка: текущее число в списке жирным (2)', str_contains($subscription, 'Количество эмитентов в списке на отслеживание: <b>2</b>'));
// current_period_end фикстуры — '2026-09-21'; считаем ожидаемое число дней
// той же формулой, что и сам daysUntil() — иначе тест зависит от того, в
// какой день его реально запускают, и рано или поздно "протухнет".
$expectedDaysLeft = max(0, (int) ceil((strtotime('2026-09-21') - time()) / 86400));
check(
    'Подписка (тариф free, решение от 13 сентября): дней до окончания пробного периода, посчитано от current_period_end',
    str_contains($subscription, "Дней до окончания пробного периода: <b>{$expectedDaysLeft}</b>")
);
check('Подписка: название тарифа из tariffs.name, не хардкод', str_contains($subscription, 'Ваша текущая подписка: <b>Free</b>'));
check('Подписка: ссылка на канал из ТЗ', str_contains($subscription, 't.me/Bond_Keeper'));

// Будущий платный тариф (ещё не существует продуктово, но код должен
// быть готов) — для НЕ-free тарифа остаётся настоящая дата, а не счётчик
// дней (автопродление касается только Free, пока нет платной тарификации).
$db->exec("INSERT INTO tariffs (code, name, max_tracked_issuers, duration_days) VALUES ('basic', 'Basic', 20, 30)");
$db->exec("INSERT INTO users (id, telegram_id) VALUES (99, 999999999)");
$db->exec("INSERT INTO subscriptions (user_id, tariff_code, status, current_period_end) VALUES (99, 'basic', 'active', '2026-10-05')");
$paidSubscription = callPrivate($ref, $handler, 'handleSubscription', [99]);
check('Подписка (платный тариф): показывает настоящую дату жирным, не счётчик дней', str_contains($paidSubscription, 'Окончание действия подписки: <b>05.10.26</b>'));
check('Подписка (платный тариф): название тарифа "Basic" жирным', str_contains($paidSubscription, 'Ваша текущая подписка: <b>Basic</b>'));

// --- ensureFreeSubscription(): автопродление Free (решение от 13 сентября 2026) ---
$daysUntil = $ref->getMethod('daysUntil');
$daysUntil->setAccessible(true);
$ensureFreeSubscription = $ref->getMethod('ensureFreeSubscription');
$ensureFreeSubscription->setAccessible(true);

// Новый пользователь — подписка создаётся с нуля, current_period_end ~ +14 дней.
$db->exec("INSERT INTO users (id, telegram_id) VALUES (201, 201201201)");
$ensureFreeSubscription->invokeArgs($handler, [201]);
$newSub = $db->query('SELECT tariff_code, current_period_end FROM subscriptions WHERE user_id = 201')->fetch(PDO::FETCH_ASSOC);
check('ensureFreeSubscription(): новому пользователю создаёт free-подписку', $newSub !== false && $newSub['tariff_code'] === 'free');
check(
    'ensureFreeSubscription(): current_period_end нового пользователя ~14 дней вперёд',
    $newSub !== false && abs($daysUntil->invokeArgs($handler, [$newSub['current_period_end']]) - 14) <= 1
);

// Истёкшая free-подписка — молча продлевается ещё на duration_days.
$db->exec("INSERT INTO users (id, telegram_id) VALUES (202, 202202202)");
$db->exec("INSERT INTO subscriptions (user_id, tariff_code, status, current_period_end) VALUES (202, 'free', 'active', '2020-01-01 00:00:00')");
$ensureFreeSubscription->invokeArgs($handler, [202]);
$renewedSub = $db->query('SELECT current_period_end FROM subscriptions WHERE user_id = 202')->fetch(PDO::FETCH_ASSOC);
check(
    'ensureFreeSubscription(): истёкшая free-подписка продлена ещё на ~14 дней (не осталась в прошлом)',
    $renewedSub !== false && abs($daysUntil->invokeArgs($handler, [$renewedSub['current_period_end']]) - 14) <= 1
);

// Ещё НЕ истёкшая free-подписка — не трогаем раньше срока.
$db->exec("INSERT INTO users (id, telegram_id) VALUES (203, 203203203)");
$futureEnd = date('Y-m-d H:i:s', time() + 5 * 86400);
$db->exec("INSERT INTO subscriptions (user_id, tariff_code, status, current_period_end) VALUES (203, 'free', 'active', '{$futureEnd}')");
$ensureFreeSubscription->invokeArgs($handler, [203]);
$untouchedSub = $db->query('SELECT current_period_end FROM subscriptions WHERE user_id = 203')->fetch(PDO::FETCH_ASSOC);
check('ensureFreeSubscription(): ещё не истёкшая free-подписка не продлевается раньше срока', $untouchedSub !== false && $untouchedSub['current_period_end'] === $futureEnd);

// Платная (не free) подписка — этот метод её вообще не трогает, даже истёкшую.
$db->exec("INSERT INTO users (id, telegram_id) VALUES (204, 204204204)");
$db->exec("INSERT INTO subscriptions (user_id, tariff_code, status, current_period_end) VALUES (204, 'basic', 'active', '2020-01-01 00:00:00')");
$ensureFreeSubscription->invokeArgs($handler, [204]);
$paidUntouched = $db->query('SELECT current_period_end FROM subscriptions WHERE user_id = 204')->fetch(PDO::FETCH_ASSOC);
check('ensureFreeSubscription(): платную подписку не трогает, даже истёкшую', $paidUntouched !== false && $paidUntouched['current_period_end'] === '2020-01-01 00:00:00');

// --- О сервисе — просто не падает и содержит ключевые фразы ---
$about = callPrivate($ref, $handler, 'aboutServiceText', []);
check('О сервисе: "BondKeeper" жирным', str_contains($about, '<b>BondKeeper</b>'));
check('О сервисе: пустая строка после первой строки (решение от 13 сентября)', str_contains($about, "Благодарим за интерес к нашему проекту! 🙏\n\n"));
check('О сервисе: содержит блок преимуществ', str_contains($about, 'Главные преимущества проекта'));
check(
    'О сервисе: последний абзац — новый текст со ссылкой на статью (решение от 16 сентября)',
    str_contains($about, 'Получить больше информации о самом проекте можно в статье по ссылке ниже 👇')
);

// --- О сервисе — инлайн-кнопка "Читать статью 📚" со ссылкой (16 сентября 2026) ---
$aboutKeyboard = callPrivate($ref, $handler, 'aboutServiceKeyboard', []);
$aboutButton = $aboutKeyboard['inline_keyboard'][0][0] ?? null;
check('О сервисе: кнопка "Читать статью 📚" присутствует', $aboutButton !== null && $aboutButton['text'] === 'Читать статью 📚');
check('О сервисе: кнопка ведёт на присланную статью', $aboutButton !== null && $aboutButton['url'] === 'https://teletype.in/@kvint_invest/-mSGn-tfdhZ');

// --- Знакомство — заголовок "Знакомство" жирным (решение от 16 сентября 2026) ---
$greeting = callPrivate($ref, $handler, 'handleStart', []);
check('Знакомство: слово "Знакомство" в заголовке жирным', str_contains($greeting, '<b>Знакомство</b> 👋'));

// --- BotFormatting::formatDate() edge cases (вынесено из BotCommandHandler в общий класс) ---
check('formatDate(null) -> тире', \BondKeeper\Telegram\BotFormatting::formatDate(null) === '—');
check('formatDate("") -> тире', \BondKeeper\Telegram\BotFormatting::formatDate('') === '—');
check('formatDate corretно режет время, если оно есть', \BondKeeper\Telegram\BotFormatting::formatDate('2026-01-05 10:00:00') === '05.01.26');

// --- Релей "Помощь": forwardToAdmin() + relayAdminReplyToUser() ---
// (setDialogState/getDialogState/ensureUser НЕ трогаем — они пишут через
// ON DUPLICATE KEY UPDATE, недоступный в SQLite, тот же нюанс, что в
// Фазе 1 — см. докблок наверху файла).
$db->exec('INSERT INTO users (id, telegram_id) VALUES (1, 1007481909)');

$forwarded = callPrivate($ref, $handler, 'forwardToAdmin', [1, 'Никита', 1007481909, 'one_who_does', 'Тестовое обращение']);
check('forwardToAdmin(): вернул true (FakeTelegramClient всегда "успешно")', $forwarded === true);

$mapRow = $db->query('SELECT admin_chat_id, admin_message_id, user_id FROM support_thread_map')->fetch(PDO::FETCH_ASSOC);
check('forwardToAdmin(): записал строку в support_thread_map', $mapRow !== false);
check('forwardToAdmin(): admin_chat_id = сконфигурированный админ (555)', $mapRow !== false && (int) $mapRow['admin_chat_id'] === 555);
check('forwardToAdmin(): admin_message_id = то, что вернул FakeTelegramClient (999)', $mapRow !== false && (int) $mapRow['admin_message_id'] === 999);
check('forwardToAdmin(): user_id верный', $mapRow !== false && (int) $mapRow['user_id'] === 1);

$relayed = callPrivate($ref, $handler, 'relayAdminReplyToUser', [555, 999, 'Ответ от поддержки']);
check('relayAdminReplyToUser(): нашёл маппинг и вернул true', $relayed === true);

$notFound = callPrivate($ref, $handler, 'relayAdminReplyToUser', [555, 123456, 'Reply на чужое сообщение']);
check('relayAdminReplyToUser(): Reply НЕ на наше сообщение -> false (не наш случай)', $notFound === false);

// --- "Выбор компаний": умный поиск (searchIssuers) ---
$byIsin = callPrivate($ref, $handler, 'searchIssuers', ['RU000A1035N9']);
check('searchIssuers(): находит по ISIN', count($byIsin) === 1 && $byIsin[0]['id'] === 1);

$byIsinLowercase = callPrivate($ref, $handler, 'searchIssuers', ['ru000a1035n9']);
check('searchIssuers(): ISIN нечувствителен к регистру', count($byIsinLowercase) === 1 && $byIsinLowercase[0]['id'] === 1);

$byInn = callPrivate($ref, $handler, 'searchIssuers', ['7706107510']);
check('searchIssuers(): находит по ИНН', count($byInn) === 1 && $byInn[0]['id'] === 1);

$bySecid = callPrivate($ref, $handler, 'searchIssuers', ['RU000ROSN01']);
check('searchIssuers(): находит по тикеру (SECID)', count($bySecid) === 1 && $bySecid[0]['id'] === 1);

$byFragment = callPrivate($ref, $handler, 'searchIssuers', ['нефт']);
check('searchIssuers(): фрагмент "нефт" находит и Роснефть, и Башнефть, и Сургутнефтегаз', count($byFragment) === 3);

$noMatch = callPrivate($ref, $handler, 'searchIssuers', ['ЗАВЕДОМО НЕСУЩЕСТВУЮЩАЯ КОМПАНИЯ']);
check('searchIssuers(): нет совпадений -> пустой массив', $noMatch === []);

// --- handleIssuerSearchText(): текст + кнопки ---
$searchReply = callPrivate($ref, $handler, 'handleIssuerSearchText', ['Роснефть']);
check('handleIssuerSearchText(): одна кнопка на найденный вариант + кнопка полного списка', count($searchReply['keyboard']['inline_keyboard']) === 2);
check('handleIssuerSearchText(): callback_data кнопки — iss:pick:1', $searchReply['keyboard']['inline_keyboard'][0][0]['callback_data'] === 'iss:pick:1');

$noMatchReply = callPrivate($ref, $handler, 'handleIssuerSearchText', ['ЗАВЕДОМО НЕСУЩЕСТВУЮЩАЯ КОМПАНИЯ']);
check('handleIssuerSearchText(): "Не нашёл" содержит сам запрос', str_contains($noMatchReply['text'], 'Не нашёл') && str_contains($noMatchReply['text'], 'ЗАВЕДОМО НЕСУЩЕСТВУЮЩАЯ КОМПАНИЯ'));
check('handleIssuerSearchText(): предлагает полный список', $noMatchReply['keyboard']['inline_keyboard'][0][0]['callback_data'] === 'iss:browse:0');

// --- showIssuerBrowsePage(): пагинация + отметки ✅/➕ ---
callPrivate($ref, $handler, 'showIssuerBrowsePage', [1, 5001, 42, 0]);
$page0 = $telegram->lastEdit;
check('showIssuerBrowsePage(): страница 0 — 8 эмитентов', count($page0['keyboard']['inline_keyboard']) === 8 + 1); // +1 строка навигации
check('showIssuerBrowsePage(): на странице 0 есть кнопка "Вперёд"', str_contains(json_encode($page0['keyboard'], JSON_UNESCAPED_UNICODE), 'Вперёд'));
check('showIssuerBrowsePage(): на странице 0 НЕТ кнопки "Назад" (первая страница)', !str_contains(json_encode($page0['keyboard'], JSON_UNESCAPED_UNICODE), 'Назад'));
// Алфавитный порядок: страница 0 = Алроса..Магнит (Газпром сюда попадает), Роснефть/Сургутнефтегаз — на странице 1.
check('showIssuerBrowsePage(): Газпром (уже в watchlist, буква "Г") отмечен ✅ на странице 0', str_contains(json_encode($page0['keyboard'], JSON_UNESCAPED_UNICODE), '✅ Газпром'));

callPrivate($ref, $handler, 'showIssuerBrowsePage', [1, 5001, 42, 1]);
$page1 = $telegram->lastEdit;
check('showIssuerBrowsePage(): страница 1 — оставшиеся 4 эмитента', count($page1['keyboard']['inline_keyboard']) === 4 + 1);
check('showIssuerBrowsePage(): на странице 1 есть кнопка "Назад"', str_contains(json_encode($page1['keyboard'], JSON_UNESCAPED_UNICODE), 'Назад'));
check('showIssuerBrowsePage(): на странице 1 НЕТ кнопки "Вперёд" (последняя страница)', !str_contains(json_encode($page1['keyboard'], JSON_UNESCAPED_UNICODE), 'Вперёд'));
check('showIssuerBrowsePage(): Роснефть (уже в watchlist, буква "Р") отмечена ✅ на странице 1', str_contains(json_encode($page1['keyboard'], JSON_UNESCAPED_UNICODE), '✅ Роснефть'));

// --- toggleIssuer(): добавление/удаление через листалку ---
$toastAdd = callPrivate($ref, $handler, 'toggleIssuer', [1, 5001, 42, 3, 0]); // issuer_id=3 (Алроса), page=0
check('toggleIssuer(): добавление возвращает тост "Добавлено"', $toastAdd === 'Добавлено');
$watchCountAfterAdd = (int) $db->query('SELECT COUNT(*) FROM watchlist WHERE user_id = 1 AND issuer_id = 3')->fetchColumn();
check('toggleIssuer(): реально добавлено в watchlist', $watchCountAfterAdd === 1);

$toastRemove = callPrivate($ref, $handler, 'toggleIssuer', [1, 5001, 42, 3, 0]); // повторный тап — снимает
check('toggleIssuer(): повторный тап снимает, тост "Убрано"', $toastRemove === 'Убрано');
$watchCountAfterRemove = (int) $db->query('SELECT COUNT(*) FROM watchlist WHERE user_id = 1 AND issuer_id = 3')->fetchColumn();
check('toggleIssuer(): реально убрано из watchlist', $watchCountAfterRemove === 0);

// Лимит тарифа при переключении (free=10, у user=1 уже 2 в watchlist — Роснефть+Газпром) — добавим ещё 8, десятый уже не пройдёт.
for ($i = 4; $i <= 11; $i++) {
    callPrivate($ref, $handler, 'toggleIssuer', [1, 5001, 42, $i, 0]);
}
$countAtLimit = (int) $db->query('SELECT COUNT(DISTINCT issuer_id) FROM watchlist WHERE user_id = 1')->fetchColumn();
check('toggleIssuer(): дошли ровно до лимита тарифа (10)', $countAtLimit === 10);
$toastOverLimit = callPrivate($ref, $handler, 'toggleIssuer', [1, 5001, 42, 12, 0]);
check('toggleIssuer(): сверх лимита -> тост с упоминанием лимита, БЕЗ добавления', str_contains((string) $toastOverLimit, 'лимит'));
$countStillAtLimit = (int) $db->query('SELECT COUNT(DISTINCT issuer_id) FROM watchlist WHERE user_id = 1')->fetchColumn();
check('toggleIssuer(): счётчик не превысил лимит', $countStillAtLimit === 10);

// Откатим до 2 (Роснефть+Газпром), чтобы не мешать следующим проверкам pickIssuer()/removeIssuerFromEditList().
$db->exec('DELETE FROM watchlist WHERE user_id = 1 AND issuer_id NOT IN (1, 2)');

// --- pickIssuer(): добавление из результатов поиска ---
$pickToast = callPrivate($ref, $handler, 'pickIssuer', [1, 5001, 42, 4]); // Башнефть
check('pickIssuer(): тост содержит имя добавленного эмитента', str_contains((string) $pickToast, 'Башнефть'));
check('pickIssuer(): реально добавлено', (int) $db->query('SELECT COUNT(*) FROM watchlist WHERE user_id = 1 AND issuer_id = 4')->fetchColumn() === 1);
check('pickIssuer(): отредактировал сообщение текстом из ТЗ "Список успешно обновлён"', str_contains($telegram->lastEdit['text'], 'Список успешно обновлён! 🥳'));

$pickDuplicateToast = callPrivate($ref, $handler, 'pickIssuer', [1, 5001, 42, 4]); // ещё раз та же Башнефть
check('pickIssuer(): повторный pick того же эмитента -> "уже отслеживаете", не дублирует', str_contains((string) $pickDuplicateToast, 'же отслеживаете'));
check('pickIssuer(): дубль не создал вторую строку', (int) $db->query('SELECT COUNT(*) FROM watchlist WHERE user_id = 1 AND issuer_id = 4')->fetchColumn() === 1);

// --- showEditListScreen() / removeIssuerFromEditList() ---
callPrivate($ref, $handler, 'showEditListScreen', [1, 5001, 42]);
$editListView = $telegram->lastEdit;
check('showEditListScreen(): по кнопке на каждого отслеживаемого эмитента (3: Роснефть/Газпром/Башнефть) + "Добавить ещё"', count($editListView['keyboard']['inline_keyboard']) === 4);

$removeToast = callPrivate($ref, $handler, 'removeIssuerFromEditList', [1, 5001, 42, 4]); // убрать Башнефть
check('removeIssuerFromEditList(): тост "Убрано"', $removeToast === 'Убрано');
check('removeIssuerFromEditList(): реально удалено', (int) $db->query('SELECT COUNT(*) FROM watchlist WHERE user_id = 1 AND issuer_id = 4')->fetchColumn() === 0);

$removeAgainToast = callPrivate($ref, $handler, 'removeIssuerFromEditList', [1, 5001, 42, 4]); // повторное удаление — уже нет
check('removeIssuerFromEditList(): повторное удаление -> null (нечего убирать)', $removeAgainToast === null);

// --- dispatchIssuerCallback(): маршрутизация callback_data ---
$notIssuerPrefix = callPrivate($ref, $handler, 'dispatchIssuerCallback', [1, 5001, 42, 'other:something']);
check('dispatchIssuerCallback(): чужой префикс (не "iss:") -> null', $notIssuerPrefix === null);

$unknownAction = callPrivate($ref, $handler, 'dispatchIssuerCallback', [1, 5001, 42, 'iss:unknown_action']);
check('dispatchIssuerCallback(): неизвестное действие -> null', $unknownAction === null);

$routedRemove = callPrivate($ref, $handler, 'dispatchIssuerCallback', [1, 5001, 42, 'iss:remove:2']); // убрать Газпром через полный маршрут
check('dispatchIssuerCallback(): корректно маршрутизирует "remove" -> тост "Убрано"', $routedRemove === 'Убрано');
check('dispatchIssuerCallback(): реально убрало через полный маршрут', (int) $db->query('SELECT COUNT(*) FROM watchlist WHERE user_id = 1 AND issuer_id = 2')->fetchColumn() === 0);

// --- Цвета кнопок меню (прямое указание пользователя, 8 сентября 2026) ---
$menuKb = callPrivate($ref, $handler, 'mainMenuKeyboard', []);
$rows = $menuKb['keyboard'];
check('Меню: "Выбор компаний" — style=primary (синяя)', $rows[0][0]['style'] === 'primary');
check('Меню: "Подписка" — style=success (зелёная)', $rows[0][1]['style'] === 'success');
check('Меню: "Статус" — без style (серая по умолчанию)', !isset($rows[1][0]['style']));
check('Меню: "О сервисе" — style=danger (красная)', $rows[1][1]['style'] === 'danger');
check('Меню: "Помощь" — без style (серая по умолчанию)', !isset($rows[2][0]['style']));

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
