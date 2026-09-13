<?php

declare(strict_types=1);

namespace BondKeeper\Telegram;

use BondKeeper\Ratings\IssuerMatcher;
use PDO;
use PDOException;

/**
 * Разбор входящих Update от TelegramClient::getUpdates() — команды из
 * ТЗ `docs/BOT_UX_SPEC.md`: reply-клавиатура «Выбор компаний 📝 /
 * Статус 📍 / Подписка 🎁 / О сервисе 🌐 / Помощь 🛠» (кнопка называлась
 * «Выбор эмитентов» до 13 сентября 2026 — переименована по прямому
 * запросу пользователя, сам раздел и callback_data (`iss:...`) остались
 * прежними), плюс старые слэш-команды `/watch`/`/unwatch`/`/list`/`/help`
 * (оставлены как есть, для тех, кто ими уже пользуется). Платежи/апгрейд
 * тарифа — вне этого MVP, только соблюдение лимита `tariffs.max_tracked_issuers`.
 *
 * === Раздел «Выбор компаний» — инлайн-кнопки (`callback_query`) ===
 * "Добавить новых эмитентов" → поиск свободным текстом (по ИНН/ISIN/
 * тикеру-SECID точно, по фрагменту названия — LIKE) с кнопками-
 * вариантами, либо листалка по алфавиту с переключателями ➕/✅
 * (`editMessageText`, одно и то же сообщение, не спам новыми).
 * "Редактировать список" — текущий watchlist кнопками-удалялками +
 * кнопка вернуться к добавлению. Все callback_data — префикс `iss:`,
 * разбор в dispatchIssuerCallback(). Слэш-команды `/watch`/`/unwatch`/
 * `/list` оставлены рабочими как раньше — независимый путь для тех, кто
 * ими уже пользуется, не убран ради нового UX.
 *
 * === Раздел «Помощь» — чат-релей с администратором ===
 * Простая машина состояний на пользователя (`bot_dialog_state`):
 * `awaiting_support_name` → `awaiting_support_message` → `in_support_chat`.
 * Пока пользователь в `in_support_chat`, ЛЮБОЙ его свободный текст (не
 * распознанный ни как команда, ни как кнопка меню) уходит администратору
 * (`config/telegram_bot.php: admin_telegram_id`), а Reply администратора
 * на конкретное такое сообщение возвращается тому же пользователю —
 * связка хранится в `support_thread_map` по id отправленного
 * администратору сообщения (Reply на произвольное чужое сообщение или
 * без Reply вообще — молча игнорируется, это осознанное решение из ТЗ,
 * не пробел).
 *
 * Регистрация пользователя (ensureUser()) выполняется на КАЖДОЕ входящее
 * сообщение, а не только на /start — так что если пользователь почему-то
 * напишет боту сразу /watch (например, по диплинку) без предварительного
 * /start, команда всё равно сработает. /start остаётся отдельной
 * командой ради приветственного текста (плюс он же навешивает
 * reply-клавиатуру меню), а не потому что только он регистрирует.
 *
 * Офсет getUpdates() у вызывающего кода (bin/daemon_telegram_bot.php)
 * держится только в памяти процесса — при перезапуске Telegram может
 * повторно прислать уже обработанный апдейт ("at least once" у Bot API).
 * Все обработчики ниже это переживают безопасно: /start и ensureUser()
 * идемпотентны (INSERT ... ON DUPLICATE KEY UPDATE), /watch на уже
 * отслеживаемого эмитента поймает нарушение уникального ключа и вернёт
 * "уже отслеживаете" вместо ошибки, /unwatch на уже убранного эмитента —
 * "не отслеживали", /list и /help вообще не пишут в БД.
 */
final class BotCommandHandler
{
    private const BTN_ISSUERS = 'Выбор компаний 📝';
    private const BTN_STATUS = 'Статус 📍';
    private const BTN_SUBSCRIPTION = 'Подписка 🎁';
    private const BTN_ABOUT = 'О сервисе 🌐';
    private const BTN_HELP = 'Помощь 🛠';
    private const BROWSE_PAGE_SIZE = 8;

    public function __construct(
        private readonly PDO $db,
        private readonly TelegramClientInterface $telegram,
        private readonly IssuerMatcher $matcher,
        /** 0 — не настроено, раздел "Помощь" тогда не работает (см. forwardToAdmin()). */
        private readonly int $adminTelegramId = 0,
    ) {
    }

    /** @param array<string, mixed> $update сырой объект Update от Bot API */
    public function handleUpdate(array $update): void
    {
        $callbackQuery = $update['callback_query'] ?? null;
        if (is_array($callbackQuery)) {
            $this->handleCallbackQuery($callbackQuery);
            return;
        }

        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            // Прочие типы апдейтов (edited_message и т.п.) — молча пропускаем.
            return;
        }

        $chat = $message['chat'] ?? null;
        $from = $message['from'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));
        if (!is_array($chat) || !is_array($from) || $text === '') {
            return;
        }

        $telegramId = (int) ($from['id'] ?? 0);
        $chatId = (int) ($chat['id'] ?? 0);
        if ($telegramId === 0 || $chatId === 0) {
            return;
        }

        // Администратор отвечает Reply на пересланное обращение — раздел
        // "Помощь" в докблоке класса. Проверяем ДО обычной регистрации
        // пользователя: это служебный канал, а не команда от клиента.
        // Если это НЕ такой случай (не админ, не Reply, либо Reply на
        // сообщение вне support_thread_map) — падаем в обычную ветку
        // ниже: администратор сам тоже обычный пользователь бота.
        if ($this->adminTelegramId !== 0 && $telegramId === $this->adminTelegramId) {
            $replyToMessageId = $message['reply_to_message']['message_id'] ?? null;
            if (is_int($replyToMessageId) && $this->relayAdminReplyToUser($chatId, $replyToMessageId, $text)) {
                return;
            }
        }

        $username = isset($from['username']) && $from['username'] !== '' ? (string) $from['username'] : null;
        $userId = $this->ensureUser($telegramId, $username);

        $known = $this->matchKnownCommand($userId, $text);
        if ($known !== null) {
            $this->telegram->sendMessage($chatId, $known['text'], $known['keyboard'] ?? null, $known['parseMode'] ?? null);
            return;
        }

        // Свободный текст, не распознанный ни как слэш-команда, ни как
        // кнопка меню — это либо шаг многошаговой формы ("Помощь",
        // поиск эмитента), либо просто "не понимаю" — handleFreeText().
        $reply = $this->handleFreeText($userId, $telegramId, $username, $text);
        $this->telegram->sendMessage($chatId, $reply['text'], $reply['keyboard'] ?? null);
    }

    /** @param array<string, mixed> $callbackQuery сырой объект CallbackQuery от Bot API */
    private function handleCallbackQuery(array $callbackQuery): void
    {
        $id = (string) ($callbackQuery['id'] ?? '');
        $data = (string) ($callbackQuery['data'] ?? '');
        $from = $callbackQuery['from'] ?? null;
        $message = $callbackQuery['message'] ?? null;
        if ($id === '') {
            return; // без id ответить всё равно нечем
        }
        if ($data === '' || !is_array($from) || !is_array($message)) {
            $this->telegram->answerCallbackQuery($id);
            return;
        }

        $chat = $message['chat'] ?? null;
        $chatId = is_array($chat) ? (int) ($chat['id'] ?? 0) : 0;
        $messageId = (int) ($message['message_id'] ?? 0);
        $telegramId = (int) ($from['id'] ?? 0);
        if ($chatId === 0 || $messageId === 0 || $telegramId === 0) {
            $this->telegram->answerCallbackQuery($id);
            return;
        }

        $username = isset($from['username']) && $from['username'] !== '' ? (string) $from['username'] : null;
        $userId = $this->ensureUser($telegramId, $username);

        $toast = $this->dispatchIssuerCallback($userId, $chatId, $messageId, $data);
        $this->telegram->answerCallbackQuery($id, $toast);
    }

    /** @return array{text: string, keyboard?: array<string, mixed>, parseMode?: string}|null null — не команда и не кнопка меню, дальше — handleFreeText() */
    private function matchKnownCommand(int $userId, string $text): ?array
    {
        return match ($text) {
            '/start' => ['text' => $this->handleStart(), 'keyboard' => $this->mainMenuKeyboard(), 'parseMode' => 'HTML'],
            '/help' => ['text' => $this->helpText()],
            self::BTN_ISSUERS => $this->handleIssuerMenuEntry(),
            self::BTN_STATUS => $this->handleStatus($userId),
            self::BTN_SUBSCRIPTION => ['text' => $this->handleSubscription($userId)],
            self::BTN_ABOUT => ['text' => $this->aboutServiceText()],
            self::BTN_HELP => ['text' => $this->startSupportFlow($userId)],
            default => $this->matchSlashCommandWithArgument($userId, $text),
        };
    }

    /** @return array{text: string}|null */
    private function matchSlashCommandWithArgument(int $userId, string $text): ?array
    {
        if (!str_starts_with($text, '/')) {
            return null;
        }
        [$command, $argument] = $this->parseCommand($text);

        return match ($command) {
            '/watch' => ['text' => $this->handleWatch($userId, $argument)],
            '/unwatch' => ['text' => $this->handleUnwatch($userId, $argument)],
            '/list' => ['text' => $this->handleList($userId)],
            default => null,
        };
    }

    /** @return array<string, mixed> reply_markup для ReplyKeyboardMarkup — раскладка 2x2+1 из docs/BOT_UX_SPEC.md, раздел 2 */
    /**
     * Цвета — прямое указание пользователя (8 сентября 2026): синяя
     * (`primary`)/зелёная (`success`)/красная (`danger`) кнопки дают
     * белый текст сами по себе (часть стиля Bot API 9.4, не наша
     * настройка); "серая, чёрный текст" — это и есть цвет ПО УМОЛЧАНИЮ
     * (поле `style` просто не передаём), отдельного "grey" в Bot API нет.
     */
    private function mainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => self::BTN_ISSUERS, 'style' => 'primary'], ['text' => self::BTN_SUBSCRIPTION, 'style' => 'success']],
                [['text' => self::BTN_STATUS], ['text' => self::BTN_ABOUT, 'style' => 'danger']],
                [['text' => self::BTN_HELP]],
            ],
            'resize_keyboard' => true,
        ];
    }

    /** @return array{0: string, 1: string} [команда в нижнем регистре без "@BotName", аргумент (остаток строки, может быть '')] */
    private function parseCommand(string $text): array
    {
        $parts = preg_split('/\s+/', $text, 2) ?: [$text];
        $command = mb_strtolower($parts[0]);
        $command = preg_replace('/@\S+$/', '', $command) ?? $command; // "/watch@BondKeeperBot" -> "/watch" (групповые чаты, не используется в MVP, но не должно ломать)
        $argument = isset($parts[1]) ? trim($parts[1]) : '';

        return [$command, $argument];
    }

    /**
     * Регистрация/обновление пользователя. telegram_bot_blocked сбрасывается
     * здесь безусловно — раз пользователь прислал сообщение боту, он его
     * заведомо не заблокирован прямо сейчас (сброс отражает актуальный факт,
     * а не "прощаем" что-то).
     */
    private function ensureUser(int $telegramId, ?string $username): int
    {
        $this->db->prepare(
            'INSERT INTO users (telegram_id, telegram_username, last_active_at)
             VALUES (:telegram_id, :telegram_username, NOW())
             ON DUPLICATE KEY UPDATE
                telegram_username = VALUES(telegram_username),
                telegram_bot_blocked = 0,
                last_active_at = NOW()'
        )->execute([
            'telegram_id' => $telegramId,
            'telegram_username' => $username,
        ]);

        // lastInsertId() после ON DUPLICATE KEY UPDATE не гарантированно
        // отражает id обновлённой (не вставленной) строки без отдельного
        // трюка LAST_INSERT_ID(id) в самом запросе — проще и надёжнее
        // просто перечитать id отдельным SELECT по UNIQUE-ключу telegram_id.
        $select = $this->db->prepare('SELECT id FROM users WHERE telegram_id = :telegram_id');
        $select->execute(['telegram_id' => $telegramId]);
        $userId = (int) $select->fetchColumn();

        $this->ensureFreeSubscription($userId);

        return $userId;
    }

    /**
     * Новому пользователю — сразу подписка на 'free' (нет ни одной строки
     * в subscriptions вообще, не только на 'free'), чтобы currentLimit()
     * ниже всегда находил хоть какую-то активную подписку и не считал
     * лимит равным 0 по ошибке.
     *
     * current_period_end = дата регистрации + tariffs.duration_days —
     * НЕ бессрочный sentinel (было так до ТЗ от 7 сентября 2026, миграция
     * 018_bot_ux_tariff_and_dialog_state.sql поменяла free на 14
     * календарных дней с автопродлением до запуска платной
     * тарификации — сам механизм автопродления вне этого метода, см.
     * docs/BOT_UX_SPEC.md, раздел 5, отдельный фоновый скрипт, ещё не
     * реализован). duration_days читаем из БД, а не хардкодим 14 —
     * тариф меняется миграцией, код не должен её дублировать.
     */
    private function ensureFreeSubscription(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM subscriptions WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $durationStmt = $this->db->prepare("SELECT duration_days FROM tariffs WHERE code = 'free'");
        $durationStmt->execute();
        $durationDays = (int) ($durationStmt->fetchColumn() ?: 14);

        $this->db->prepare(
            "INSERT INTO subscriptions (user_id, tariff_code, status, current_period_end)
             VALUES (:user_id, 'free', 'active', DATE_ADD(NOW(), INTERVAL :duration_days DAY))"
        )->execute(['user_id' => $userId, 'duration_days' => $durationDays]);
    }

    /** Текст дословно из ТЗ (docs/BOT_UX_SPEC.md, раздел 1.2) — не перефразировать без запроса пользователя. */
    /**
     * HTML (не Markdown) — Bot API парсит только <b>/<i>/... в тексте с
     * parse_mode=HTML, экранировать тут нечего (в статичном тексте и в
     * названиях кнопок нет ни одного символа "<"/">"/"&"). Названия кнопок
     * выделены жирным по прямому запросу пользователя (13 сентября 2026) —
     * только здесь, сами константы BTN_* остаются обычным текстом: это же
     * значение уходит в reply-клавиатуру и в match() для распознавания
     * нажатия, а Telegram не рендерит разметку в подписях кнопок.
     */
    private function handleStart(): string
    {
        return "Знакомство 👋\n\n"
            . "Привет! Меня зовут Bond…, только не James, а BondKeeper и я буду твоим надёжным помощником на рынке облигаций! 📈\n"
            . "Перед тем, как начать, расскажу немного о своём меню.\n\n"
            . "Чтобы открыть его, просто нажми на кнопку справа от окна сообщения 😉 (квадрат с четырьмя кружочками внутри).\n\n"
            . "Пробежимся по разделам:\n"
            . '<b>' . self::BTN_ISSUERS . '</b>' . " — жми сюда, чтобы составить или отредактировать список эмитентов, за которыми нужно следить.\n"
            . '<b>' . self::BTN_STATUS . '</b>' . " — жми сюда, чтобы получить актуальную информацию по конкретному эмитенту или всему списку.\n"
            . '<b>' . self::BTN_SUBSCRIPTION . '</b>' . " — жми сюда, чтобы получить информацию о текущей подписке и тарифах.\n"
            . '<b>' . self::BTN_ABOUT . '</b>' . " — жми сюда, чтобы получить больше информации о самом проекте и сервисе. Я подробно расскажу о том, какие данные нам доступны и как их можно использовать.\n"
            . '<b>' . self::BTN_HELP . '</b>' . " — жми сюда, если у тебя возникли какие-то вопросы или сложности, связанные с проектом. Наша поддержка оперативно тебе поможет и ответит на все вопросы.";
    }

    // === Раздел "Выбор компаний" — docs/BOT_UX_SPEC.md раздел 3 ===

    /** Вход в раздел — сообщение с двумя инлайн-кнопками, дословно из ТЗ. */
    private function handleIssuerMenuEntry(): array
    {
        return [
            'text' => 'Что делаем со списком эмитентов?',
            'keyboard' => ['inline_keyboard' => [
                [['text' => 'Добавить новых эмитентов', 'callback_data' => 'iss:add_menu']],
                [['text' => 'Редактировать список', 'callback_data' => 'iss:edit_list']],
            ]],
        ];
    }

    /**
     * Разбор callback_data вида "iss:<действие>[:...]" — единственный
     * префикс, за который отвечает этот класс на инлайн-кнопках.
     * Возвращает текст всплывающего тоста (answerCallbackQuery) либо
     * null (без тоста) — сама видимая часть обновляется отдельно, через
     * editMessageText() внутри каждого обработчика.
     */
    private function dispatchIssuerCallback(int $userId, int $chatId, int $messageId, string $data): ?string
    {
        $parts = explode(':', $data);
        if (($parts[0] ?? '') !== 'iss') {
            return null;
        }

        return match ($parts[1] ?? '') {
            'add_menu' => $this->showIssuerSearchPrompt($userId, $chatId, $messageId),
            'edit_list' => $this->showEditListScreen($userId, $chatId, $messageId),
            'browse' => $this->showIssuerBrowsePage($userId, $chatId, $messageId, (int) ($parts[2] ?? 0)),
            'pick' => $this->pickIssuer($userId, $chatId, $messageId, (int) ($parts[2] ?? 0)),
            'toggle' => $this->toggleIssuer($userId, $chatId, $messageId, (int) ($parts[2] ?? 0), (int) ($parts[3] ?? 0)),
            'remove' => $this->removeIssuerFromEditList($userId, $chatId, $messageId, (int) ($parts[2] ?? 0)),
            default => null,
        };
    }

    private function showIssuerSearchPrompt(int $userId, int $chatId, int $messageId): ?string
    {
        $this->setDialogState($userId, 'awaiting_issuer_search');

        $text = "Напишите название компании, ИНН, тикер или ISIN облигации — подберу подходящих эмитентов.\n\n"
            . 'Не знаете, что искать? Откройте список ниже 👇';
        $keyboard = ['inline_keyboard' => [[['text' => '📋 Показать список', 'callback_data' => 'iss:browse:0']]]];
        $this->telegram->editMessageText($chatId, $messageId, $text, $keyboard);

        return null;
    }

    private function showEditListScreen(int $userId, int $chatId, int $messageId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT i.id, i.short_name FROM watchlist w JOIN issuers i ON i.id = w.issuer_id WHERE w.user_id = :user_id ORDER BY i.short_name'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();

        $buttons = [];
        foreach ($rows as $row) {
            $buttons[] = [['text' => '🗑 ' . $row['short_name'], 'callback_data' => 'iss:remove:' . $row['id']]];
        }
        $buttons[] = [['text' => '➕ Добавить ещё', 'callback_data' => 'iss:add_menu']];

        $text = $rows === []
            ? 'Список пуст — добавьте эмитентов кнопкой ниже.'
            : 'Ваш список отслеживания — нажмите на эмитента, чтобы убрать его:';

        $this->telegram->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $buttons]);

        return null;
    }

    /** Листалка по алфавиту, переключатели ➕/✅ на месте — та же идея, что предлагалась 6 сентября (см. память). */
    private function showIssuerBrowsePage(int $userId, int $chatId, int $messageId, int $page): ?string
    {
        $page = max(0, $page);
        $offset = $page * self::BROWSE_PAGE_SIZE;

        $total = (int) $this->db->query('SELECT COUNT(*) FROM issuers')->fetchColumn();

        $stmt = $this->db->prepare('SELECT id, short_name FROM issuers ORDER BY short_name LIMIT :limit OFFSET :offset');
        $stmt->bindValue('limit', self::BROWSE_PAGE_SIZE, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $watchedStmt = $this->db->prepare('SELECT issuer_id FROM watchlist WHERE user_id = :user_id');
        $watchedStmt->execute(['user_id' => $userId]);
        $watched = array_map('intval', $watchedStmt->fetchAll(PDO::FETCH_COLUMN));

        $buttons = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $mark = in_array($id, $watched, true) ? '✅ ' : '➕ ';
            $buttons[] = [['text' => $mark . $row['short_name'], 'callback_data' => "iss:toggle:{$id}:{$page}"]];
        }

        $nav = [];
        if ($page > 0) {
            $nav[] = ['text' => '◀️ Назад', 'callback_data' => 'iss:browse:' . ($page - 1)];
        }
        if ($offset + self::BROWSE_PAGE_SIZE < $total) {
            $nav[] = ['text' => 'Вперёд ▶️', 'callback_data' => 'iss:browse:' . ($page + 1)];
        }
        if ($nav !== []) {
            $buttons[] = $nav;
        }

        $text = 'Список эмитентов (стр. ' . ($page + 1) . "):\n\nНе нашли? Просто напишите название компании.";
        $this->telegram->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $buttons]);

        return null;
    }

    private function toggleIssuer(int $userId, int $chatId, int $messageId, int $issuerId, int $page): ?string
    {
        $checkStmt = $this->db->prepare('SELECT 1 FROM watchlist WHERE user_id = :user_id AND issuer_id = :issuer_id AND security_id IS NULL');
        $checkStmt->execute(['user_id' => $userId, 'issuer_id' => $issuerId]);
        $alreadyWatched = $checkStmt->fetchColumn() !== false;

        if ($alreadyWatched) {
            $this->db->prepare('DELETE FROM watchlist WHERE user_id = :user_id AND issuer_id = :issuer_id AND security_id IS NULL')
                ->execute(['user_id' => $userId, 'issuer_id' => $issuerId]);
            $toast = 'Убрано';
        } else {
            $limit = $this->currentTariffLimit($userId);
            if ($limit !== null) {
                $countStmt = $this->db->prepare('SELECT COUNT(DISTINCT issuer_id) FROM watchlist WHERE user_id = :user_id');
                $countStmt->execute(['user_id' => $userId]);
                if ((int) $countStmt->fetchColumn() >= $limit) {
                    $this->showIssuerBrowsePage($userId, $chatId, $messageId, $page);

                    return "Достигнут лимит тарифа: {$limit}";
                }
            }

            try {
                $this->db->prepare('INSERT INTO watchlist (user_id, issuer_id) VALUES (:user_id, :issuer_id)')
                    ->execute(['user_id' => $userId, 'issuer_id' => $issuerId]);
                $toast = 'Добавлено';
            } catch (PDOException $e) {
                if (!$this->isDuplicateKeyViolation($e)) {
                    throw $e;
                }
                $toast = 'Уже отслеживаете';
            }
        }

        $this->showIssuerBrowsePage($userId, $chatId, $messageId, $page);

        return $toast;
    }

    /**
     * Выбор одного варианта из результатов поиска. В отличие от
     * toggleIssuer() (листалка) — тут по ТЗ ожидается сообщение "Список
     * успешно обновлён! 🥳..." (docs/BOT_UX_SPEC.md раздел 3.2); решили
     * ОТРЕДАКТИРОВАТЬ то же сообщение этим текстом, а не слать новое —
     * меньше мусора в чате при добавлении нескольких эмитентов подряд.
     * Состояние остаётся awaiting_issuer_search — можно сразу писать
     * следующее название, не открывая раздел заново.
     */
    private function pickIssuer(int $userId, int $chatId, int $messageId, int $issuerId): ?string
    {
        $name = $this->issuerDisplayName($issuerId) ?? "эмитент #{$issuerId}";

        $limit = $this->currentTariffLimit($userId);
        if ($limit !== null) {
            $countStmt = $this->db->prepare('SELECT COUNT(DISTINCT issuer_id) FROM watchlist WHERE user_id = :user_id');
            $countStmt->execute(['user_id' => $userId]);
            if ((int) $countStmt->fetchColumn() >= $limit) {
                return "Достигнут лимит тарифа: {$limit}";
            }
        }

        try {
            $this->db->prepare('INSERT INTO watchlist (user_id, issuer_id) VALUES (:user_id, :issuer_id)')
                ->execute(['user_id' => $userId, 'issuer_id' => $issuerId]);
        } catch (PDOException $e) {
            if (!$this->isDuplicateKeyViolation($e)) {
                throw $e;
            }

            return "Уже отслеживаете «{$name}»";
        }

        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            "Список успешно обновлён! 🥳\nЧтобы получить актуальную информацию по конкретному эмитенту или всему списку нажми на команду " . self::BTN_STATUS
        );

        return "Добавлено: {$name}";
    }

    private function removeIssuerFromEditList(int $userId, int $chatId, int $messageId, int $issuerId): ?string
    {
        $stmt = $this->db->prepare('DELETE FROM watchlist WHERE user_id = :user_id AND issuer_id = :issuer_id');
        $stmt->execute(['user_id' => $userId, 'issuer_id' => $issuerId]);
        $removed = $stmt->rowCount() > 0;

        $this->showEditListScreen($userId, $chatId, $messageId);

        return $removed ? 'Убрано' : null;
    }

    /**
     * "Умный поиск" (docs/BOT_UX_SPEC.md раздел 3.1) — по порядку: ISIN
     * точного вида → ИНН точного вида → тикер (SECID) точного вида →
     * фрагмент названия (LIKE). Первое, что дало результат, и
     * возвращается — не смешиваем находки из разных путей поиска.
     *
     * @return array<int, array{id: int, short_name: string}> максимум 8 вариантов
     */
    private function searchIssuers(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        if (preg_match('/^[A-Za-z]{2}[A-Za-z0-9]{9}\d$/', $query)) {
            $stmt = $this->db->prepare(
                'SELECT i.id, i.short_name FROM securities s JOIN issuers i ON i.id = s.issuer_id WHERE s.isin = :isin'
            );
            $stmt->execute(['isin' => mb_strtoupper($query)]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return [['id' => (int) $row['id'], 'short_name' => (string) $row['short_name']]];
            }
        }

        $issuerIdByInn = $this->matcher->findIssuerIdByInn($query);
        if ($issuerIdByInn !== null) {
            $name = $this->issuerDisplayName($issuerIdByInn);
            if ($name !== null) {
                return [['id' => $issuerIdByInn, 'short_name' => $name]];
            }
        }

        $secidStmt = $this->db->prepare(
            'SELECT i.id, i.short_name FROM securities s JOIN issuers i ON i.id = s.issuer_id WHERE s.secid = :secid LIMIT 1'
        );
        $secidStmt->execute(['secid' => $query]);
        $secidRow = $secidStmt->fetch();
        if ($secidRow !== false) {
            return [['id' => (int) $secidRow['id'], 'short_name' => (string) $secidRow['short_name']]];
        }

        // Два РАЗНЫХ именованных плейсхолдера с одним и тем же значением —
        // не :fragment дважды. Database::connection() держит
        // PDO::ATTR_EMULATE_PREPARES=false (настоящие подготовленные
        // запросы MySQL), а в этом режиме повтор одного :имени в тексте
        // запроса требует значение на КАЖДОЕ вхождение — execute() с
        // одним ключом 'fragment' на оба вхождения падает с "SQLSTATE[HY093]:
        // Invalid parameter number" (под SQLite-эмуляцией в офлайн-тестах
        // такого ограничения нет, поэтому баг не был пойман офлайн —
        // нашёлся только вживую на реальной MySQL).
        $likeStmt = $this->db->prepare(
            'SELECT id, short_name FROM issuers WHERE short_name LIKE :fragment1 OR full_name LIKE :fragment2 ORDER BY short_name LIMIT 8'
        );
        $likeFragment = '%' . $query . '%';
        $likeStmt->execute(['fragment1' => $likeFragment, 'fragment2' => $likeFragment]);

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'short_name' => (string) $r['short_name']],
            $likeStmt->fetchAll()
        );
    }

    /** @return array{text: string, keyboard?: array<string, mixed>} */
    private function handleIssuerSearchText(string $query): array
    {
        $matches = $this->searchIssuers($query);

        if ($matches === []) {
            return [
                'text' => "Не нашёл ничего по «{$query}» — проверьте название, ИНН, тикер или ISIN.",
                'keyboard' => ['inline_keyboard' => [[['text' => '📋 Показать полный список', 'callback_data' => 'iss:browse:0']]]],
            ];
        }

        $buttons = array_map(
            static fn (array $m): array => [['text' => $m['short_name'], 'callback_data' => 'iss:pick:' . $m['id']]],
            $matches
        );
        $buttons[] = [['text' => '📋 Показать полный список', 'callback_data' => 'iss:browse:0']];

        return [
            'text' => 'Нашёл вариант(ы) — выберите нужный, либо откройте полный список:',
            'keyboard' => ['inline_keyboard' => $buttons],
        ];
    }

    /** @return array{text: string, keyboard?: array<string, mixed>} docs/BOT_UX_SPEC.md, раздел 4 — формат дословно из ТЗ. */
    private function handleStatus(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT i.id, i.short_name, i.inn
             FROM watchlist w
             JOIN issuers i ON i.id = w.issuer_id
             WHERE w.user_id = :user_id
             ORDER BY i.short_name'
        );
        $stmt->execute(['user_id' => $userId]);
        $issuers = $stmt->fetchAll();

        if ($issuers === []) {
            return [
                'text' => 'Ваш список эмитентов для отслеживания пуст 😢',
                'keyboard' => ['inline_keyboard' => [[['text' => self::BTN_ISSUERS, 'callback_data' => 'iss:add_menu']]]],
            ];
        }

        $blocks = array_map(
            fn (array $issuer): string => $this->formatIssuerStatus((int) $issuer['id'], (string) $issuer['short_name'], (string) $issuer['inn']),
            $issuers
        );

        return ['text' => implode("\n\n", $blocks)];
    }

    private function formatIssuerStatus(int $issuerId, string $shortName, string $inn): string
    {
        $fnsStmt = $this->db->prepare(
            'SELECT is_fns_blocked, block_date, active_bank_count, blocked_amount FROM fns_blocks WHERE issuer_id = :issuer_id'
        );
        $fnsStmt->execute(['issuer_id' => $issuerId]);
        $fns = $fnsStmt->fetch();

        $fnsLine = ($fns === false || !(bool) $fns['is_fns_blocked'])
            ? 'Заблокированных счетов нет ✅'
            : sprintf(
                'Дата блокировки: %s | Количество заблокированных счетов: %d | Заблокированная сумма: %s',
                BotFormatting::formatDate($fns['block_date'] !== null ? (string) $fns['block_date'] : null),
                (int) $fns['active_bank_count'],
                $fns['blocked_amount'] !== null ? (string) $fns['blocked_amount'] : 'не указана'
            );

        $ratingsStmt = $this->db->prepare(
            'SELECT agency, rating, outlook, last_action_date FROM current_ratings WHERE issuer_id = :issuer_id ORDER BY agency'
        );
        $ratingsStmt->execute(['issuer_id' => $issuerId]);
        $ratings = $ratingsStmt->fetchAll();

        $ratingLines = $ratings === []
            ? ['Эмитент без рейтинга']
            : array_map(
                fn (array $r): string => sprintf(
                    'Агентство: %s | Рейтинг: %s | Прогноз: %s | Дата действия: %s',
                    BotFormatting::agencyDisplayName((string) $r['agency']),
                    (string) $r['rating'],
                    $r['outlook'] !== null ? (string) $r['outlook'] : '—',
                    BotFormatting::formatDate($r['last_action_date'] !== null ? (string) $r['last_action_date'] : null)
                ),
                $ratings
            );

        return "{$shortName} | ИНН {$inn} | {$fnsLine}\n" . implode("\n", $ratingLines);
    }


    /**
     * docs/BOT_UX_SPEC.md, раздел 5 — текст адаптирован под решение
     * пользователя (8 сентября 2026): у тарифа 'free' `current_period_end`
     * технически ни на что не влияет (лимит проверяется только по
     * `max_tracked_issuers`, не по дате) — значит показывать реальную
     * дату пользователю бессмысленно и даже вводит в заблуждение (через
     * 14 дней выглядела бы "просроченной", хотя ничего не меняется).
     * Вместо даты — честная формулировка "действует, пока...". Для
     * БУДУЩИХ платных тарифов (когда появятся) — обычная дата, там она
     * уже будет что-то реально значить.
     */
    private function handleSubscription(int $userId): string
    {
        $stmt = $this->db->prepare(
            "SELECT s.tariff_code, s.current_period_end, t.name, t.max_tracked_issuers
             FROM subscriptions s
             JOIN tariffs t ON t.code = s.tariff_code
             WHERE s.user_id = :user_id AND s.status = 'active'
             ORDER BY s.id DESC LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        $countStmt = $this->db->prepare('SELECT COUNT(DISTINCT issuer_id) FROM watchlist WHERE user_id = :user_id');
        $countStmt->execute(['user_id' => $userId]);
        $current = (int) $countStmt->fetchColumn();

        $tariffName = $row !== false ? (string) $row['name'] : 'Free';
        $limit = $row !== false && $row['max_tracked_issuers'] !== null ? (string) (int) $row['max_tracked_issuers'] : 'без ограничения';
        $periodEnd = ($row !== false && $row['tariff_code'] === 'free')
            ? 'действует, пока не запущены платные тарифы'
            : ($row !== false ? BotFormatting::formatDate((string) $row['current_period_end']) : '—');

        return "Ваша текущая подписка: {$tariffName}\n"
            . "Количество эмитентов доступных для отслеживания: {$limit}\n"
            . "Количество эмитентов в списке на отслеживание: {$current}\n"
            . "Окончание действия подписки: {$periodEnd}\n\n"
            . "Благодарим за интерес к нашему проекту! 🙏\n"
            . "Ещё больше возможностей уже скоро откроется на платном тарифе 😉\n"
            . "Следите за новостями: t.me/Bond_Keeper";
    }

    /** docs/BOT_UX_SPEC.md, раздел 6 — текст дословно из ТЗ. Кнопка "Читать статью" не добавлена — URL ещё не прислан заказчиком. */
    private function aboutServiceText(): string
    {
        return "Благодарим за интерес к нашему проекту! 🙏\n"
            . "BondKeeper — российский digital-сервис мониторинга корпоративных облигаций. Сервис объединяет в одном канале уведомлений структурированные данные по облигациям, корпоративные раскрытия (включая информацию о поступлении денежных средств для купонных выплат, амортизаций, оферт и погашений), сигналы о наличии решений ФНС о приостановки операций по счетам, сигналы о пересмотре кредитного рейтинга эмитентов от РА. Основная цель сервиса — своевременно предоставить пользователю критически важную информацию, чтобы на их основе он мог принять собственное инвестиционное решение.\n\n"
            . "Главные преимущества проекта:\n"
            . "- скорость получения информации;\n"
            . "- пользователь сам настраивает список интересующих его эмитентов и облигаций (исключён рыночный шум и бесконечный спам);\n"
            . "- все сигналы (выплаты, блокировки и рейтинги) объединены в один удобный канал уведомлений.\n\n"
            . "Мы постоянно дорабатываем и улучшаем наш сервис и на сегодняшний день его функционал включает: информацию о блокировках ФНС и уведомления о рейтинговых действиях.\n\n"
            . "Получить больше технической информации о самом проекте можно в статье — ссылку добавим здесь, как только она будет готова.";
    }

    // === Раздел "Помощь" — чат-релей, docs/BOT_UX_SPEC.md раздел 7 ===

    private function startSupportFlow(int $userId): string
    {
        $dialog = $this->getDialogState($userId);
        if ($dialog !== null && $dialog['state'] === 'in_support_chat') {
            return 'Вы уже на связи с поддержкой — просто напишите сообщение, и я передам его дальше.';
        }

        $this->setDialogState($userId, 'awaiting_support_name');

        return "Если у тебя возник вопрос, нужна помощь или есть пожелание по проекту — я передам его нашей команде.\n\n"
            . 'Как к тебе обращаться?';
    }

    /** @return array{text: string, keyboard?: array<string, mixed>} */
    private function handleFreeText(int $userId, int $telegramId, ?string $username, string $text): array
    {
        $dialog = $this->getDialogState($userId);
        if ($dialog === null) {
            return ['text' => "Не понимаю эту команду.\n\n" . $this->helpText()];
        }

        return match ($dialog['state']) {
            'awaiting_issuer_search' => $this->handleIssuerSearchText($text),
            'awaiting_support_name' => ['text' => $this->receiveSupportName($userId, $text)],
            'awaiting_support_message' => ['text' => $this->receiveSupportMessage($userId, $telegramId, $username, $dialog['context'], $text)],
            'in_support_chat' => ['text' => $this->relayUserMessageToAdmin($userId, $telegramId, $username, $dialog['context'], $text)],
            default => ['text' => "Не понимаю эту команду.\n\n" . $this->helpText()],
        };
    }

    private function receiveSupportName(int $userId, string $name): string
    {
        $this->setDialogState($userId, 'awaiting_support_message', ['name' => $name]);

        return "Приятно познакомиться, {$name}! Опишите, пожалуйста, ваш вопрос или пожелание.";
    }

    /** @param array<string, mixed> $context */
    private function receiveSupportMessage(int $userId, int $telegramId, ?string $username, array $context, string $text): string
    {
        $name = (string) ($context['name'] ?? 'без имени');
        if (!$this->forwardToAdmin($userId, $name, $telegramId, $username, $text)) {
            return 'Не удалось передать обращение — попробуйте ещё раз чуть позже.';
        }

        $this->setDialogState($userId, 'in_support_chat', $context);

        return 'Спасибо! Мы получили ваше обращение и ответим прямо здесь, в этом чате.';
    }

    /** @param array<string, mixed> $context */
    private function relayUserMessageToAdmin(int $userId, int $telegramId, ?string $username, array $context, string $text): string
    {
        $name = (string) ($context['name'] ?? 'без имени');

        return $this->forwardToAdmin($userId, $name, $telegramId, $username, $text)
            ? 'Передал в поддержку 👍'
            : 'Не удалось передать сообщение — попробуйте ещё раз чуть позже.';
    }

    private function forwardToAdmin(int $userId, string $name, int $telegramId, ?string $username, string $text): bool
    {
        if ($this->adminTelegramId === 0) {
            return false;
        }

        $usernamePart = $username !== null ? "@{$username}" : 'без username';
        $adminText = "📩 Обращение в поддержку\nОт: {$name} ({$usernamePart}, id {$telegramId})\n\n{$text}";

        $messageId = $this->telegram->sendMessageReturningId($this->adminTelegramId, $adminText);
        if ($messageId === null) {
            return false;
        }

        $this->db->prepare(
            'INSERT INTO support_thread_map (admin_chat_id, admin_message_id, user_id) VALUES (:admin_chat_id, :admin_message_id, :user_id)'
        )->execute([
            'admin_chat_id' => $this->adminTelegramId,
            'admin_message_id' => $messageId,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * Reply администратора на конкретное пересланное сообщение —
     * возвращает false, если это НЕ Reply на что-то из
     * support_thread_map (не наш случай, вызывающий код должен
     * обработать сообщение как обычное от пользователя-администратора).
     */
    private function relayAdminReplyToUser(int $adminChatId, int $replyToMessageId, string $text): bool
    {
        $stmt = $this->db->prepare(
            'SELECT u.telegram_id
             FROM support_thread_map m
             JOIN users u ON u.id = m.user_id
             WHERE m.admin_chat_id = :admin_chat_id AND m.admin_message_id = :admin_message_id'
        );
        $stmt->execute(['admin_chat_id' => $adminChatId, 'admin_message_id' => $replyToMessageId]);
        $userTelegramId = $stmt->fetchColumn();

        if ($userTelegramId === false) {
            return false;
        }

        $this->telegram->sendMessage((int) $userTelegramId, "💬 Ответ от поддержки:\n{$text}");

        return true;
    }

    /** @return array{state: string, context: array<string, mixed>}|null */
    private function getDialogState(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT state, context_json FROM bot_dialog_state WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $context = $row['context_json'] !== null ? json_decode((string) $row['context_json'], true) : [];

        return ['state' => (string) $row['state'], 'context' => is_array($context) ? $context : []];
    }

    /** @param array<string, mixed> $context */
    private function setDialogState(int $userId, string $state, array $context = []): void
    {
        $this->db->prepare(
            'INSERT INTO bot_dialog_state (user_id, state, context_json)
             VALUES (:user_id, :state, :context_json)
             ON DUPLICATE KEY UPDATE
                state = VALUES(state),
                context_json = VALUES(context_json)'
        )->execute([
            'user_id' => $userId,
            'state' => $state,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function handleWatch(int $userId, string $argument): string
    {
        if ($argument === '') {
            return "Укажите название компании или ИНН после команды, например:\n/watch Роснефть";
        }

        $issuerId = $this->resolveIssuer($argument);
        if ($issuerId === null) {
            return "Не нашёл эмитента «{$argument}» — проверьте название или ИНН и попробуйте ещё раз.";
        }

        $limit = $this->currentTariffLimit($userId);
        if ($limit !== null) {
            $countStmt = $this->db->prepare('SELECT COUNT(DISTINCT issuer_id) FROM watchlist WHERE user_id = :user_id');
            $countStmt->execute(['user_id' => $userId]);
            $current = (int) $countStmt->fetchColumn();
            if ($current >= $limit) {
                return "Достигнут лимит вашего тарифа: {$limit} эмитентов (сейчас отслеживаете {$current}). "
                    . "Уберите кого-то из списка (/unwatch) или используйте более старший тариф.";
            }
        }

        $name = $this->issuerDisplayName($issuerId) ?? $argument;

        try {
            $this->db->prepare(
                'INSERT INTO watchlist (user_id, issuer_id) VALUES (:user_id, :issuer_id)'
            )->execute(['user_id' => $userId, 'issuer_id' => $issuerId]);
        } catch (PDOException $e) {
            if ($this->isDuplicateKeyViolation($e)) {
                return "Вы уже отслеживаете «{$name}».";
            }
            throw $e;
        }

        return "Добавлено в список отслеживания: «{$name}». Буду присылать уведомления о рейтинговых действиях и блокировках счетов ФНС.";
    }

    private function handleUnwatch(int $userId, string $argument): string
    {
        if ($argument === '') {
            return "Укажите название компании или ИНН после команды, например:\n/unwatch Роснефть";
        }

        $issuerId = $this->resolveIssuer($argument);
        if ($issuerId === null) {
            return "Не нашёл эмитента «{$argument}» — проверьте название или ИНН.";
        }

        $name = $this->issuerDisplayName($issuerId) ?? $argument;

        // security_id IS NULL — снимаем только слежение "за эмитентом
        // целиком" (единственный вид слежения, который умеет создавать
        // /watch в этом MVP); слежение за конкретной бумагой этой командой
        // не трогаем, если оно вдруг когда-то появится другим путём.
        $stmt = $this->db->prepare(
            'DELETE FROM watchlist WHERE user_id = :user_id AND issuer_id = :issuer_id AND security_id IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'issuer_id' => $issuerId]);

        return $stmt->rowCount() > 0
            ? "Убрано из списка отслеживания: «{$name}»."
            : "Вы не отслеживали «{$name}».";
    }

    private function handleList(int $userId): string
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT i.short_name
             FROM watchlist w
             JOIN issuers i ON i.id = w.issuer_id
             WHERE w.user_id = :user_id
             ORDER BY i.short_name'
        );
        $stmt->execute(['user_id' => $userId]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($names === []) {
            return "Список отслеживания пуст. Добавьте эмитента: /watch <название или ИНН>.";
        }

        $lines = array_map(static fn (int $i, string $name): string => ($i + 1) . ". {$name}", array_keys($names), $names);

        return "Вы отслеживаете:\n" . implode("\n", $lines);
    }

    private function helpText(): string
    {
        return "Команды:\n"
            . "/watch <название или ИНН> — добавить эмитента в список отслеживания\n"
            . "/unwatch <название или ИНН> — убрать эмитента из списка\n"
            . "/list — показать список отслеживания\n"
            . "/help — это сообщение";
    }

    private function resolveIssuer(string $query): ?int
    {
        // findIssuerIdByInn() сам нормализует и молча вернёт null для
        // строки, не похожей на ИНН (см. IssuerMatcher::normalizeInn) —
        // отдельная проверка "это ИНН или имя" тут не нужна.
        return $this->matcher->findIssuerIdByInn($query) ?? $this->matcher->findIssuerIdByName($query);
    }

    private function issuerDisplayName(int $issuerId): ?string
    {
        $stmt = $this->db->prepare('SELECT short_name FROM issuers WHERE id = :id');
        $stmt->execute(['id' => $issuerId]);
        $name = $stmt->fetchColumn();

        return $name !== false ? (string) $name : null;
    }

    /**
     * @return int|null null = без ограничения (tariffs.max_tracked_issuers
     * IS NULL, например тариф 'expert'); нет ни одной активной подписки
     * вообще (не должно случаться — ensureFreeSubscription() всегда
     * создаёт хотя бы 'free' новому пользователю) — возвращаем 0, а не
     * null, чтобы не открывать безлимит по умолчанию при непредвиденном
     * состоянии данных.
     */
    private function currentTariffLimit(int $userId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT t.max_tracked_issuers
             FROM subscriptions s
             JOIN tariffs t ON t.code = s.tariff_code
             WHERE s.user_id = :user_id AND s.status = 'active'
             ORDER BY s.id DESC
             LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $value = $stmt->fetchColumn();
        // fetchColumn() возвращает false ТОЛЬКО когда строк нет вообще —
        // если строка есть, а само значение max_tracked_issuers = NULL
        // (безлимитный тариф), возвращается именно null, не false.
        if ($value === false) {
            return 0;
        }

        return $value === null ? null : (int) $value;
    }

    /**
     * MySQL: SQLSTATE 23000 + "Duplicate entry" в тексте. SQLite (офлайн-
     * тесты) сообщает иначе ("UNIQUE constraint failed") — проверяем оба
     * варианта, чтобы один и тот же код можно было честно тестировать
     * офлайн (см. tests/ пакета Фазы 1) и на бою.
     */
    private function isDuplicateKeyViolation(PDOException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Duplicate entry') || str_contains($message, 'UNIQUE constraint failed');
    }
}
