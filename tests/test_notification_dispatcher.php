<?php

declare(strict_types=1);

/**
 * Офлайн-проверка Этапа 4, Фазы 3 (NotificationDispatcher) на SQLite —
 * ПОЛНОСТЬЮ тестируемо (в отличие от BotCommandHandler): вся логика —
 * обычные INSERT/UPDATE/SELECT + один INSERT с UNIQUE-ключом (ловим
 * нарушение как штатный "уже обработано"), нигде нет MySQL-only
 * ON DUPLICATE KEY UPDATE.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=pdo_sqlite -d extension=mbstring tests/test_notification_dispatcher.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Telegram\NotificationDispatcher;
use BondKeeper\Telegram\TelegramClientInterface;

final class FakeTelegramClient implements TelegramClientInterface
{
    /** @var array<int, array{chat_id: int, text: string, parse_mode: ?string, disable_web_page_preview: bool}> */
    public array $sent = [];
    public bool $nextSendShouldFail = false;
    public bool $nextFailureIsBlock = false;
    private bool $lastBlocked = false;

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null, bool $disableWebPagePreview = false): bool
    {
        $this->lastBlocked = false;
        if ($this->nextSendShouldFail) {
            $this->lastBlocked = $this->nextFailureIsBlock;
            $this->nextSendShouldFail = false;
            $this->nextFailureIsBlock = false;

            return false;
        }
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => $parseMode, 'disable_web_page_preview' => $disableWebPagePreview];

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
        return $this->lastBlocked;
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
$db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

$db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, short_name TEXT, inn TEXT)');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, telegram_id INTEGER, telegram_bot_blocked INTEGER DEFAULT 0)');
// added_at — DEFAULT (datetime('now')), тот же приём, что и detected_at
// у events ниже: естественный порядок строк в этом файле (watchlist
// всегда вставляется РАНЬШЕ соответствующего события) сам по себе
// удовлетворяет новому фильтру `w.added_at <= e.detected_at` (см.
// докблок NotificationDispatcher::fetchPendingPairs(), баг найден 17
// сентября 2026) — <= (не <) специально, чтобы вставки в одну и ту же
// секунду не считались "событие раньше подписки".
// security_id в watchlist и events — для отбора по бумаге (Этап 5,
// выплаты); здесь везде NULL: эмитент отслеживается целиком, события —
// уровня эмитента. Сам отбор по бумаге проверяется в tests/test_payments.php.
$db->exec("CREATE TABLE watchlist (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, issuer_id INTEGER, security_id INTEGER, added_at TEXT DEFAULT (datetime('now')))");
$db->exec('CREATE TABLE event_types (code TEXT PRIMARY KEY, notify_client INTEGER)');
$db->exec("CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, issuer_id INTEGER, event_type_code TEXT, status_text TEXT, payload_json TEXT, detected_at TEXT DEFAULT (datetime('now')))");
$db->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_id INTEGER, channel TEXT, status TEXT, failure_reason TEXT, sent_at TEXT, UNIQUE(user_id, event_id, channel))');

$db->exec("INSERT INTO event_types (code, notify_client) VALUES ('C5', 1), ('E1', 1), ('A1', 0), ('R1', 1), ('A2', 1), ('B1', 1), ('B2a', 1)"); // A1 — есть в схеме, но notify_client=FALSE, для проверки фильтра

$db->exec("INSERT INTO issuers (id, short_name, inn) VALUES (1, 'Роснефть', '7706107510')");
$db->exec("INSERT INTO users (id, telegram_id, telegram_bot_blocked) VALUES (1, 1007481909, 0)");
$db->exec('INSERT INTO watchlist (user_id, issuer_id) VALUES (1, 1)');

$telegram = new FakeTelegramClient();
$dispatcher = new NotificationDispatcher($db, $telegram);

// --- C5: подтверждение без изменений ---
// status_text — то, что реально кладёт EventPublisher::publishRatingAction()
// (sourceTitle ?? buildRatingStatusText()) — тут синтетический текст вместо
// реального заголовка пресс-релиза, логика та же.
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, status_text, payload_json) VALUES (1, 1, 'C5', "
    . $db->quote('подтверждён на уровне AA.ru, прогноз: stable') . ", '"
    . json_encode(['agency' => 'nkr', 'rating_from' => 'AA.ru', 'rating_to' => 'AA.ru', 'outlook_from' => 'stable', 'outlook_to' => 'stable', 'source_url' => null], JSON_UNESCAPED_UNICODE)
    . "')");

$sentCount = $dispatcher->dispatchPending();
check('dispatchPending(): одно уведомление отправлено (C5 без изменений)', $sentCount === 1);
check('C5: заголовок темы жирным', str_contains($telegram->sent[0]['text'], '<b>🔔 Новости рейтингов:</b>'));
check('C5 без изменений: текст status_text дословно', str_contains($telegram->sent[0]['text'], 'подтверждён на уровне AA.ru, прогноз: stable'));
check('C5: chat_id — telegram_id пользователя', $telegram->sent[0]['chat_id'] === 1007481909);
check('C5: parse_mode=HTML', $telegram->sent[0]['parse_mode'] === 'HTML');
check('C5: disable_web_page_preview=true', $telegram->sent[0]['disable_web_page_preview'] === true);
check('notifications: статус sent', $db->query("SELECT status FROM notifications WHERE event_id = 1")->fetchColumn() === 'sent');

// --- Идемпотентность: повторный проход не шлёт то же самое событие снова ---
$secondPass = $dispatcher->dispatchPending();
check('dispatchPending(): повторный проход — 0 новых отправок (идемпотентность)', $secondPass === 0);
check('notifications: всё ещё ровно одна строка на (user=1, event=1)', (int) $db->query('SELECT COUNT(*) FROM notifications WHERE user_id = 1 AND event_id = 1')->fetchColumn() === 1);

// --- C5: реальное изменение рейтинга, со ссылкой ---
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, status_text, payload_json) VALUES (2, 1, 'C5', "
    . $db->quote('A+.ru → AA-.ru, прогноз: positive') . ", '"
    . json_encode(['agency' => 'acra', 'rating_from' => 'A+.ru', 'rating_to' => 'AA-.ru', 'outlook_from' => 'stable', 'outlook_to' => 'positive', 'source_url' => 'https://example.com/press/1'], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('C5 с изменением: текст status_text дословно, не пересобран из payload', str_contains($telegram->sent[0]['text'], 'A+.ru → AA-.ru, прогноз: positive'));
check('C5: ссылка на источник отдельной строкой', str_contains($telegram->sent[0]['text'], "\nhttps://example.com/press/1"));

// --- E1: started — заголовок темы жирным, тело без собственного смайла ---
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (3, 1, 'E1', '"
    . json_encode(['kind' => 'started', 'old_blocked_amount' => null, 'new_blocked_amount' => '1500000.00', 'active_bank_count' => 2, 'block_date' => '2026-08-20', 'reason' => 'Код 01: взыскание задолженности'], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('E1 started: заголовок темы жирным', str_starts_with($telegram->sent[0]['text'], "<b>⚠️ Блокировки ФНC:</b>\n"));
check('E1 started: тело с ИНН, без повторного смайла', str_contains($telegram->sent[0]['text'], 'Роснефть | ИНН 7706107510: Блокировка счетов ФНС'));
check('E1 started: количество счетов', str_contains($telegram->sent[0]['text'], 'Количество заблокированных счетов: 2'));
check('E1 started: сумма с разделителем разрядов и ₽', str_contains($telegram->sent[0]['text'], 'Заблокированная сумма: 1 500 000.00 ₽'));
check('E1 started: дата дд.мм.гг', str_contains($telegram->sent[0]['text'], 'Дата блокировки: 20.08.26'));
check('E1 started: причина', str_contains($telegram->sent[0]['text'], 'взыскание задолженности'));

// --- E1: amount_changed ---
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (4, 1, 'E1', '"
    . json_encode(['kind' => 'amount_changed', 'old_blocked_amount' => '1500000.00', 'new_blocked_amount' => '2000000.00', 'active_bank_count' => 3, 'block_date' => '2026-08-20', 'reason' => null], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('E1 amount_changed: заголовок темы жирным', str_starts_with($telegram->sent[0]['text'], "<b>⚠️ Блокировки ФНC:</b>\n"));
check('E1 amount_changed: тело с ИНН', str_contains($telegram->sent[0]['text'], 'Роснефть | ИНН 7706107510: сумма блокировки ФНС изменилась'));
check('E1 amount_changed: было/стало с разделителем разрядов', str_contains($telegram->sent[0]['text'], 'было 1 500 000.00 ₽, стало 2 000 000.00 ₽'));
check('E1 amount_changed: число заблокированных счетов', str_contains($telegram->sent[0]['text'], '(заблокированных счетов: 3)'));

// --- E1: count_changed (новый вид, 17 сентября 2026 — решение от 4 сентября "не триггер" пересмотрено) ---
// id=12 — не 6, чтобы не столкнуться с event_id=6, занятым ниже A1-тестом.
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (12, 1, 'E1', '"
    . json_encode(['kind' => 'count_changed', 'old_active_bank_count' => 3, 'active_bank_count' => 4, 'block_date' => '2026-08-20', 'reason' => null], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('E1 count_changed: заголовок темы жирным', str_starts_with($telegram->sent[0]['text'], "<b>⚠️ Блокировки ФНC:</b>\n"));
check('E1 count_changed: тело с ИНН', str_contains($telegram->sent[0]['text'], 'Роснефть | ИНН 7706107510: количество заблокированных счетов ФНС изменилось'));
check('E1 count_changed: было/стало', str_contains($telegram->sent[0]['text'], 'было 3, стало 4'));

// --- E1: lifted — теперь тоже с ИНН (решение пользователя, 4 октября 2026 — раньше намеренно без него) ---
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (5, 1, 'E1', '"
    . json_encode(['kind' => 'lifted', 'old_blocked_amount' => '2000000.00', 'new_blocked_amount' => null, 'active_bank_count' => 0, 'block_date' => '2026-09-01', 'reason' => null], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check(
    'E1 lifted: заголовок темы с галочкой жирным, тело с ИНН, "снята (по состоянию на 01.09.26)"',
    $telegram->sent[0]['text'] === "<b>✅ Блокировки ФНC:</b>\nРоснефть | ИНН 7706107510: блокировка счетов ФНС снята (по состоянию на 01.09.26)."
);

// --- Фильтр notify_client=FALSE (event_types.A1) — событие не должно попасть в выборку вообще ---
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (6, 1, 'A1', '{}')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('notify_client=FALSE (A1): событие НЕ разослано', $telegram->sent === []);
check('notify_client=FALSE (A1): строки в notifications не появилось', (int) $db->query('SELECT COUNT(*) FROM notifications WHERE event_id = 6')->fetchColumn() === 0);

// --- Пользователь без наблюдателей: событие есть, но watchlist пуст для этого эмитента ---
$db->exec("INSERT INTO issuers (id, short_name) VALUES (2, 'Без подписчиков')");
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (7, 2, 'C5', '"
    . json_encode(['agency' => 'nkr', 'rating_from' => 'A.ru', 'rating_to' => 'A.ru', 'outlook_from' => null, 'outlook_to' => null, 'source_url' => null], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('Эмитент без наблюдателей: событие никому не отправлено', $telegram->sent === []);

// --- telegram_bot_blocked=TRUE: не шлём, сразу failed ---
$db->exec("INSERT INTO users (id, telegram_id, telegram_bot_blocked) VALUES (2, 555555, 1)");
$db->exec('INSERT INTO watchlist (user_id, issuer_id) VALUES (2, 1)');
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (8, 1, 'C5', '"
    . json_encode(['agency' => 'nkr', 'rating_from' => 'A.ru', 'rating_to' => 'A.ru', 'outlook_from' => null, 'outlook_to' => null, 'source_url' => null], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
$blockedUserSentTo = array_filter($telegram->sent, static fn (array $s): bool => $s['chat_id'] === 555555);
check('telegram_bot_blocked=TRUE: заблокированному пользователю не отправлено', $blockedUserSentTo === []);
check('telegram_bot_blocked=TRUE: notifications.status = failed для него', $db->query('SELECT status FROM notifications WHERE user_id = 2 AND event_id = 8')->fetchColumn() === 'failed');

// --- sendMessage() падает с "бот заблокирован" — проставляем users.telegram_bot_blocked ---
$db->exec("INSERT INTO users (id, telegram_id, telegram_bot_blocked) VALUES (3, 777777, 0)");
$db->exec('INSERT INTO watchlist (user_id, issuer_id) VALUES (3, 1)');
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (9, 1, 'C5', '"
    . json_encode(['agency' => 'nkr', 'rating_from' => 'A.ru', 'rating_to' => 'A.ru', 'outlook_from' => null, 'outlook_to' => null, 'source_url' => null], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->nextSendShouldFail = true;
$telegram->nextFailureIsBlock = true;
$dispatcher->dispatchPending();
check('sendMessage() падает с "blocked": users.telegram_bot_blocked проставлен', (int) $db->query('SELECT telegram_bot_blocked FROM users WHERE id = 3')->fetchColumn() === 1);
check('sendMessage() падает: notifications.status = failed', $db->query('SELECT status FROM notifications WHERE user_id = 3 AND event_id = 9')->fetchColumn() === 'failed');

// --- SELECT-фильтр: пара с уже существующей строкой в notifications вообще не выбирается заново ---
// Свежий эмитент (issuer_id=3) — только у него и только для него один
// подписчик и одно событие, чтобы не задеть "унаследованную историю"
// других эмитентов (watchlist подхватывает ВСЕ прошлые события того же
// эмитента, это осознанное поведение, а не баг — но здесь мешало бы
// проверить именно точечный случай).
$db->exec("INSERT INTO issuers (id, short_name) VALUES (3, 'Изолированный эмитент')");
$db->exec("INSERT INTO users (id, telegram_id, telegram_bot_blocked) VALUES (4, 888888, 0)");
$db->exec('INSERT INTO watchlist (user_id, issuer_id) VALUES (4, 3)');
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (10, 3, 'C5', '{}')");
$db->exec("INSERT INTO notifications (user_id, event_id, channel, status) VALUES (4, 10, 'telegram', 'sent')"); // как будто уже разослано раньше
$telegram->sent = [];
$dispatcher->dispatchPending();
$alreadyNotifiedSentTo = array_filter($telegram->sent, static fn (array $s): bool => $s['chat_id'] === 888888);
check('SELECT-фильтр: пара с уже существующей notifications-строкой не выбирается повторно', $alreadyNotifiedSentTo === []);

// --- Настоящая гонка на уровне INSERT: строки в notifications ЕЩЁ нет на
// момент SELECT (значит fetchPendingPairs() её бы выбрал), но кто-то
// вставляет её ПРЯМО ПЕРЕД нашим INSERT — проверяем через Reflection
// dispatchOne() напрямую, а не dispatchPending() (тот сам отфильтрует
// пару по SELECT раньше, чем дойдёт до INSERT, см. проверку выше).
$db->exec("INSERT INTO users (id, telegram_id, telegram_bot_blocked) VALUES (5, 999999, 0)");
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (11, 1, 'C5', '{}')");
$db->exec("INSERT INTO notifications (user_id, event_id, channel, status) VALUES (5, 11, 'telegram', 'queued')"); // "гонка": кто-то уже вставил
$ref = new ReflectionClass($dispatcher);
$dispatchOne = $ref->getMethod('dispatchOne');
$dispatchOne->setAccessible(true);
$telegram->sent = [];
$raceResult = $dispatchOne->invokeArgs($dispatcher, [5, 11]);
check('Гонка на INSERT: dispatchOne() возвращает false, не бросает исключение', $raceResult === false);
check('Гонка на INSERT: ничего не отправлено повторно', $telegram->sent === []);
check('Гонка на INSERT: вторая строка в notifications не появилась (осталась одна)', (int) $db->query('SELECT COUNT(*) FROM notifications WHERE user_id = 5 AND event_id = 11')->fetchColumn() === 1);

// ---------------------------------------------------------------------
// Регрессия: "бэклог" при добавлении эмитента в список (найдено 17
// сентября 2026, см. докблок fetchPendingPairs()) — живой пример из
// продакшена: пользователь добавил ООО «КОНТРОЛ лизинг» и разом получил
// 4 старых сообщения, накопленных ДО того, как он начал отслеживание.
// Эмитент id=6, событие СТАРОЕ (detected_at — явно в прошлом), ДО того,
// как пользователь начал следить (watchlist.added_at — позже события).
// ---------------------------------------------------------------------
echo "\n=== Регрессия: бэклог старых событий при добавлении эмитента ===\n";

$db->exec("INSERT INTO issuers (id, short_name, inn) VALUES (6, 'КОНТРОЛ лизинг', '7805485840')");
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json, detected_at) VALUES (20, 6, 'E1', '"
    . json_encode(['kind' => 'started', 'old_blocked_amount' => null, 'new_blocked_amount' => '500000000.00', 'active_bank_count' => 14, 'block_date' => '2026-08-14', 'reason' => null], JSON_UNESCAPED_UNICODE)
    . "', '2026-09-10 08:00:00')"); // старое событие, до добавления в список
$db->exec("INSERT INTO users (id, telegram_id, telegram_bot_blocked) VALUES (6, 111222333, 0)");
$db->exec("INSERT INTO watchlist (user_id, issuer_id, added_at) VALUES (6, 6, '2026-09-14 11:11:00')"); // добавлен ПОЗЖЕ события
$telegram->sent = [];
$dispatcher->dispatchPending();
$backlogSentTo = array_filter($telegram->sent, static fn (array $s): bool => $s['chat_id'] === 111222333);
check('Бэклог: старое событие (до added_at) НЕ отправлено новому подписчику', $backlogSentTo === []);
check('Бэклог: пара (user=6, event=20) не попала в notifications вообще', (int) $db->query('SELECT COUNT(*) FROM notifications WHERE user_id = 6 AND event_id = 20')->fetchColumn() === 0);

// Контрольный случай на том же эмитенте — событие ПОСЛЕ added_at (обычный
// новый E1 уже во время отслеживания) ДОЛЖНО дойти как обычно.
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json, detected_at) VALUES (21, 6, 'E1', '"
    . json_encode(['kind' => 'lifted', 'old_blocked_amount' => '500000000.00', 'new_blocked_amount' => null, 'active_bank_count' => 0, 'block_date' => '2026-09-15', 'reason' => null], JSON_UNESCAPED_UNICODE)
    . "', '2026-09-15 08:00:00')"); // новое событие, после added_at
$telegram->sent = [];
$dispatcher->dispatchPending();
$freshSentTo = array_filter($telegram->sent, static fn (array $s): bool => $s['chat_id'] === 111222333);
check('Бэклог: событие ПОСЛЕ added_at доходит как обычно (фильтр не ломает нормальный путь)', count($freshSentTo) === 1);

// ---------------------------------------------------------------------
// parse_mode=HTML (4 октября 2026): название эмитента и заголовок новости
// приходят из внешних источников (сайты агентств) — "&"/"<"/">" в них
// обязаны быть экранированы (BotFormatting::escapeHtml()), иначе Telegram
// откажет в отправке всего сообщения с "can't parse entities" или исказит
// текст. Реальный повод для проверки — названия с "&" в них не редкость
// (например, холдинги вида "Х & Ко").
// ---------------------------------------------------------------------
echo "\n=== parse_mode=HTML: экранирование внешнего текста ===\n";

$db->exec("INSERT INTO issuers (id, short_name, inn) VALUES (7, 'ООО «Х & Ко»', '7700000007')");
$db->exec("INSERT INTO users (id, telegram_id, telegram_bot_blocked) VALUES (7, 222333444, 0)");
$db->exec("INSERT INTO watchlist (user_id, issuer_id) VALUES (7, 7)");
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, status_text, payload_json) VALUES (22, 7, 'C5', "
    . $db->quote('Рейтинг присвоен <X> на уровне AA & B') . ", '"
    . json_encode(['agency' => 'acra', 'rating_from' => null, 'rating_to' => 'AA(RU)', 'outlook_to' => null, 'source_url' => null], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('C5: "&" в status_text экранирован как &amp;', str_contains($telegram->sent[0]['text'], 'AA &amp; B'));
check('C5: "<"/">" в status_text экранированы (не ломают HTML-теги вокруг)', str_contains($telegram->sent[0]['text'], 'Рейтинг присвоен &lt;X&gt; на уровне'));
check('C5: заголовок темы по-прежнему жирный тегом (сам не экранирован)', str_contains($telegram->sent[0]['text'], '<b>🔔 Новости рейтингов:</b>'));

$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (23, 7, 'E1', '"
    . json_encode(['kind' => 'started', 'old_blocked_amount' => null, 'new_blocked_amount' => '100.00', 'active_bank_count' => 1, 'block_date' => '2026-10-01', 'reason' => 'Решение № 1 < 2 & 3'], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('E1: название эмитента с "&" экранировано в тексте', str_contains($telegram->sent[0]['text'], 'ООО «Х &amp; Ко» | ИНН 7700000007'));
check('E1: причина блокировки с "<"/"&" экранирована', str_contains($telegram->sent[0]['text'], 'Решение № 1 &lt; 2 &amp; 3'));

// ---------------------------------------------------------------------
// Этап 5 (выплаты, 4 октября 2026): R1/A2/.../B2a — тот же заголовок темы
// жирным, что у C5/E1 ("⏰/✅/🔴/🟡 Выплаты:"), одна общая тема на все виды
// событий о выплатах, смайл меняется по характеру (напоминание/получено/
// просрочка/предупреждение). Логика PaymentWatch/PaymentProcessor и отбор
// по security_id — в tests/test_payments.php; тут только сами тексты,
// которые реально уходят в Telegram.
// ---------------------------------------------------------------------
echo "\n=== Этап 5: тексты уведомлений о выплатах ===\n";

$db->exec("INSERT INTO issuers (id, short_name, inn) VALUES (8, 'ПАО «СибАвтоТранс»', '5400001234')");
$db->exec("INSERT INTO users (id, telegram_id, telegram_bot_blocked) VALUES (8, 333444555, 0)");
$db->exec("INSERT INTO watchlist (user_id, issuer_id) VALUES (8, 8)");

// --- R1: напоминание накануне выплаты ---
$db->exec("INSERT INTO events (id, security_id, issuer_id, event_type_code, payload_json) VALUES (30, 100, 8, 'R1', '"
    . json_encode([
        'isin' => 'RU000A1ABCD1', 'security_name' => 'СибАвтоТранс-БО-01', 'payment_date' => '2026-04-16',
        'effective_date' => '2026-04-16', 'payments' => [['kind' => 'coupon', 'amount_planned' => '41.10']],
    ], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('R1: заголовок темы жирным', str_starts_with($telegram->sent[0]['text'], "<b>⏰ Выплаты:</b>\n"));
check('R1: заголовок бумаги — эмитент · бумага (ISIN)', str_contains($telegram->sent[0]['text'], 'ПАО «СибАвтоТранс» · СибАвтоТранс-БО-01 (RU000A1ABCD1)'));
check('R1: дата и сумма купона', str_contains($telegram->sent[0]['text'], 'Завтра, 16.04.26, выплата: купон — 41.10 ₽ на бумагу.'));

// --- A2: купон получен НРД ---
$db->exec("INSERT INTO events (id, security_id, issuer_id, event_type_code, payload_json) VALUES (31, 100, 8, 'A2', '"
    . json_encode(['kind' => 'coupon', 'isin' => 'RU000A1ABCD1', 'security_name' => 'СибАвтоТранс-БО-01', 'payment_date' => '2026-04-16', 'amount_actual' => '41.10'], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('A2: заголовок темы — галочка (получено)', str_starts_with($telegram->sent[0]['text'], "<b>✅ Выплаты:</b>\n"));
check('A2: текст "получен НРД" с суммой', str_contains($telegram->sent[0]['text'], 'Купон за 16.04.26 получен НРД: 41.10 ₽ на бумагу.'));

// --- B1: частичная выплата, со сроком полного дефолта ---
$db->exec("INSERT INTO events (id, security_id, issuer_id, event_type_code, payload_json) VALUES (32, 101, 8, 'B1', '"
    . json_encode([
        'kind' => 'coupon', 'isin' => 'RU000A1EFGH2', 'security_name' => 'ВЗВТ-01', 'payment_date' => '2026-05-10',
        'amount_planned' => '100.00', 'amount_actual' => '60.00', 'full_default_date' => '2026-05-24', 'working_days_to_default' => 10,
    ], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('B1: заголовок темы — красный (просрочка/отклонение)', str_starts_with($telegram->sent[0]['text'], "<b>🔴 Выплаты:</b>\n"));
check('B1: получено X из Y', str_contains($telegram->sent[0]['text'], 'получено 60.00 ₽ из 100.00 ₽ на бумагу.'));
check('B1: срок полного дефолта', str_contains($telegram->sent[0]['text'], 'Полный дефолт наступит, если долг не будет закрыт до 24.05.26 (осталось рабочих дней: 10).'));

// --- B2a: вечер дня выплаты, сообщения о получении ещё нет ---
$db->exec("INSERT INTO events (id, security_id, issuer_id, event_type_code, payload_json) VALUES (33, 100, 8, 'B2a', '"
    . json_encode(['payment_date' => '2026-04-16', 'payments' => [['kind' => 'coupon']], 'check' => 'evening'], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('B2a: заголовок темы — жёлтый (предупреждение)', str_starts_with($telegram->sent[0]['text'], "<b>🟡 Выплаты:</b>\n"));
check('B2a: "пока не поступили" (вечер, не "до сих пор")', str_contains($telegram->sent[0]['text'], 'пока не поступили в НРД'));
check('B2a: оговорка про возможное сообщение на следующий рабочий день', str_contains($telegram->sent[0]['text'], 'сообщение о получении может выйти на следующий рабочий день'));

// --- Экранирование: название бумаги с "&" тоже идёт через escapeHtml() ---
$db->exec("INSERT INTO events (id, security_id, issuer_id, event_type_code, payload_json) VALUES (34, 102, 8, 'A2', '"
    . json_encode(['kind' => 'redemption', 'isin' => 'RU000A1IJKL3', 'security_name' => 'Х & Y Финанс-01', 'payment_date' => '2026-06-01', 'amount_actual' => '1000.00'], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('Выплаты: "&" в названии бумаги экранирован', str_contains($telegram->sent[0]['text'], 'Х &amp; Y Финанс-01'));

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
