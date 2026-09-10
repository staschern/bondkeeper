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
    /** @var array<int, array{chat_id: int, text: string}> */
    public array $sent = [];
    public bool $nextSendShouldFail = false;
    public bool $nextFailureIsBlock = false;
    private bool $lastBlocked = false;

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): bool
    {
        $this->lastBlocked = false;
        if ($this->nextSendShouldFail) {
            $this->lastBlocked = $this->nextFailureIsBlock;
            $this->nextSendShouldFail = false;
            $this->nextFailureIsBlock = false;

            return false;
        }
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

$db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, short_name TEXT)');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, telegram_id INTEGER, telegram_bot_blocked INTEGER DEFAULT 0)');
$db->exec('CREATE TABLE watchlist (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, issuer_id INTEGER)');
$db->exec('CREATE TABLE event_types (code TEXT PRIMARY KEY, notify_client INTEGER)');
$db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, issuer_id INTEGER, event_type_code TEXT, payload_json TEXT)');
$db->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, event_id INTEGER, channel TEXT, status TEXT, failure_reason TEXT, sent_at TEXT, UNIQUE(user_id, event_id, channel))');

$db->exec("INSERT INTO event_types (code, notify_client) VALUES ('C5', 1), ('E1', 1), ('A1', 0)"); // A1 — есть в схеме, но notify_client=FALSE, для проверки фильтра

$db->exec("INSERT INTO issuers (id, short_name) VALUES (1, 'Роснефть')");
$db->exec("INSERT INTO users (id, telegram_id, telegram_bot_blocked) VALUES (1, 1007481909, 0)");
$db->exec('INSERT INTO watchlist (user_id, issuer_id) VALUES (1, 1)');

$telegram = new FakeTelegramClient();
$dispatcher = new NotificationDispatcher($db, $telegram);

// --- C5: подтверждение без изменений ---
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (1, 1, 'C5', '"
    . json_encode(['agency' => 'nkr', 'rating_from' => 'AA.ru', 'rating_to' => 'AA.ru', 'outlook_from' => 'stable', 'outlook_to' => 'stable', 'source_url' => null], JSON_UNESCAPED_UNICODE)
    . "')");

$sentCount = $dispatcher->dispatchPending();
check('dispatchPending(): одно уведомление отправлено (C5 без изменений)', $sentCount === 1);
check('C5 без изменений: текст "подтверждён на уровне"', str_contains($telegram->sent[0]['text'], 'рейтинг подтверждён на уровне AA.ru'));
check('C5: chat_id — telegram_id пользователя', $telegram->sent[0]['chat_id'] === 1007481909);
check('notifications: статус sent', $db->query("SELECT status FROM notifications WHERE event_id = 1")->fetchColumn() === 'sent');

// --- Идемпотентность: повторный проход не шлёт то же самое событие снова ---
$secondPass = $dispatcher->dispatchPending();
check('dispatchPending(): повторный проход — 0 новых отправок (идемпотентность)', $secondPass === 0);
check('notifications: всё ещё ровно одна строка на (user=1, event=1)', (int) $db->query('SELECT COUNT(*) FROM notifications WHERE user_id = 1 AND event_id = 1')->fetchColumn() === 1);

// --- C5: реальное изменение рейтинга ---
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (2, 1, 'C5', '"
    . json_encode(['agency' => 'acra', 'rating_from' => 'A+.ru', 'rating_to' => 'AA-.ru', 'outlook_from' => 'stable', 'outlook_to' => 'positive', 'source_url' => 'https://example.com/press/1'], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('C5 с изменением: формат "было -> стало"', str_contains($telegram->sent[0]['text'], 'A+.ru → AA-.ru'));
check('C5: агентство АКРА в читаемом виде', str_contains($telegram->sent[0]['text'], 'АКРА'));
check('C5: прогноз в тексте', str_contains($telegram->sent[0]['text'], 'прогноз: positive'));
check('C5: ссылка на источник в тексте', str_contains($telegram->sent[0]['text'], 'https://example.com/press/1'));

// --- E1: started ---
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (3, 1, 'E1', '"
    . json_encode(['kind' => 'started', 'old_blocked_amount' => null, 'new_blocked_amount' => '1500000.00', 'active_bank_count' => 2, 'block_date' => '2026-08-20', 'reason' => 'Код 01: взыскание задолженности'], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('E1 started: количество банков', str_contains($telegram->sent[0]['text'], '2 банк(ов)'));
check('E1 started: сумма', str_contains($telegram->sent[0]['text'], '1500000.00'));
check('E1 started: дата дд.мм.гг', str_contains($telegram->sent[0]['text'], '20.08.26'));
check('E1 started: причина', str_contains($telegram->sent[0]['text'], 'взыскание задолженности'));

// --- E1: amount_changed ---
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (4, 1, 'E1', '"
    . json_encode(['kind' => 'amount_changed', 'old_blocked_amount' => '1500000.00', 'new_blocked_amount' => '2000000.00', 'active_bank_count' => 3, 'block_date' => '2026-08-20', 'reason' => null], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('E1 amount_changed: было/стало', str_contains($telegram->sent[0]['text'], 'было 1500000.00 ₽, стало 2000000.00 ₽'));

// --- E1: lifted ---
$db->exec("INSERT INTO events (id, issuer_id, event_type_code, payload_json) VALUES (5, 1, 'E1', '"
    . json_encode(['kind' => 'lifted', 'old_blocked_amount' => '2000000.00', 'new_blocked_amount' => null, 'active_bank_count' => 0, 'block_date' => '2026-09-01', 'reason' => null], JSON_UNESCAPED_UNICODE)
    . "')");
$telegram->sent = [];
$dispatcher->dispatchPending();
check('E1 lifted: "снята"', str_contains($telegram->sent[0]['text'], 'блокировка счетов ФНС снята'));

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

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
