<?php

declare(strict_types=1);

namespace BondKeeper\Telegram;

use BondKeeper\Support\Logger;

/**
 * Тонкая обёртка над Telegram Bot API — тот же стиль, что
 * BondKeeper\Ratings\RatingsHttp: голый cURL, без внешних библиотек
 * (проект принципиально без composer, см. bin/bootstrap.php).
 *
 * Long polling (не webhook) — см. docs/STAGE4_EVENT_ENGINE.md: не нужен
 * публичный HTTPS-эндпоинт под MVP. getUpdates() блокируется на сервере
 * Telegram до $timeoutSeconds ИЛИ до первого нового апдейта — это и есть
 * "long poll", а не быстрый частый опрос: почти мгновенная реакция на
 * команду (ответ приходит сразу, как только апдейт появился), но без
 * лишних пустых HTTP-round-trip'ов, которые были бы при коротком sleep()
 * между обычными запросами.
 */
final class TelegramClient implements TelegramClientInterface
{
    private const API_BASE = 'https://api.telegram.org/bot';

    /** Последний sendMessage() упал именно с "бот заблокирован пользователем" — читается Фазой 3 (рассылка) перед тем, как проставить users.telegram_bot_blocked. */
    private bool $lastSendWasBlockedByUser = false;

    public function __construct(
        private readonly string $botToken,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> сырые объекты Update как есть от Bot API
     */
    public function getUpdates(int $offset, int $timeoutSeconds = 25): array
    {
        $url = $this->apiUrl('getUpdates') . '?' . http_build_query([
            'offset' => $offset,
            'timeout' => $timeoutSeconds,
            // message — обычные сообщения (команды, текст меню, форма
            // Помощь); callback_query — нажатия инлайн-кнопок (раздел
            // "Выбор эмитентов": поиск/листалка/редактирование списка,
            // см. docs/BOT_UX_SPEC.md).
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ]);

        // cURL-таймаут ЗАВЕДОМО больше long-poll таймаута самого Telegram —
        // иначе соединение оборвётся раньше, чем сервер успеет честно
        // продержать его открытым до $timeoutSeconds.
        $response = $this->request($url, null, $timeoutSeconds + 10);
        if ($response === null || ($response['ok'] ?? false) !== true) {
            return [];
        }

        $result = $response['result'] ?? [];
        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed>|null $replyMarkup сырая структура reply_markup
     *   как в Bot API (reply-клавиатура ИЛИ инлайн-клавиатура — Bot API
     *   различает их по форме массива, тут неважно какая именно), либо
     *   null — сообщение без клавиатуры (клиент оставит ту, что уже была
     *   у пользователя открыта, если это reply-клавиатура — Telegram сам
     *   так себя ведёт, это не наша логика).
     */
    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): bool
    {
        $this->lastSendWasBlockedByUser = false;

        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($replyMarkup !== null) {
            // Bot API поверх x-www-form-urlencoded ждёт reply_markup как
            // JSON-СТРОКУ, а не вложенный массив — http_build_query() сам
            // это не сериализует, значение нужно закодировать заранее.
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }

        $response = $this->request($this->apiUrl('sendMessage'), $params);

        if ($response !== null && ($response['ok'] ?? false) === true) {
            return true;
        }

        $description = is_array($response) ? (string) ($response['description'] ?? '') : '';
        Logger::warn("Telegram: sendMessage chat_id={$chatId} не удалось: " . ($description !== '' ? $description : 'нет ответа от API'));

        // Формулировка подтверждена документацией Bot API ("Forbidden:
        // bot was blocked by the user") — единственный надёжный признак
        // именно блокировки, а не временной сетевой/серверной ошибки.
        if (str_contains($description, 'bot was blocked by the user')) {
            $this->lastSendWasBlockedByUser = true;
        }

        return false;
    }

    public function lastSendWasBlockedByUser(): bool
    {
        return $this->lastSendWasBlockedByUser;
    }

    /**
     * Возвращает id отправленного сообщения (нужно для
     * support_thread_map — по нему находим, кому вернуть ответ
     * администратора, см. docs/BOT_UX_SPEC.md) — null при неудаче.
     *
     * @param array<string, mixed>|null $replyMarkup см. sendMessage()
     */
    public function sendMessageReturningId(int $chatId, string $text, ?array $replyMarkup = null): ?int
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }

        $response = $this->request($this->apiUrl('sendMessage'), $params);
        if ($response === null || ($response['ok'] ?? false) !== true) {
            $description = is_array($response) ? (string) ($response['description'] ?? '') : '';
            Logger::warn("Telegram: sendMessage(returning id) chat_id={$chatId} не удалось: " . ($description !== '' ? $description : 'нет ответа от API'));
            return null;
        }

        $messageId = $response['result']['message_id'] ?? null;
        return is_int($messageId) ? $messageId : null;
    }

    /**
     * Ответ на нажатие инлайн-кнопки — Bot API ОБЯЗЫВАЕТ вызвать это
     * после каждого callback_query, иначе кнопка в клиенте у пользователя
     * бесконечно показывает "часики" (не ошибка, просто зависший
     * индикатор загрузки). $text — необязательный всплывающий тост
     * (например, "Добавлено") либо null — просто снять "часики" без
     * всплывающего текста.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text !== null) {
            $params['text'] = $text;
        }

        $response = $this->request($this->apiUrl('answerCallbackQuery'), $params);
        return $response !== null && ($response['ok'] ?? false) === true;
    }

    /**
     * Редактирование уже отправленного сообщения на месте — основа
     * "листалки" по алфавиту и переключателей ➕/✅ в разделе "Выбор
     * эмитентов" (docs/BOT_UX_SPEC.md): одно и то же сообщение
     * перерисовывается при пагинации/переключении, а не плодятся новые.
     *
     * @param array<string, mixed>|null $replyMarkup null — убрать клавиатуру у сообщения вообще
     */
    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];
        // reply_markup=null тут — осознанный "убрать клавиатуру", а не
        // "оставить как было" (в отличие от sendMessage() выше, где null
        // просто значит "не передавать поле вообще") — поэтому кодируем
        // JSON и для null тоже, Bot API принимает пустую клавиатуру так.
        $params['reply_markup'] = json_encode($replyMarkup ?? ['inline_keyboard' => []], JSON_UNESCAPED_UNICODE);

        $response = $this->request($this->apiUrl('editMessageText'), $params);
        if ($response !== null && ($response['ok'] ?? false) === true) {
            return true;
        }

        $description = is_array($response) ? (string) ($response['description'] ?? '') : '';
        // "message is not modified" — Telegram считает ошибкой попытку
        // отредактировать сообщение тем же текстом/клавиатурой (например,
        // повторный тап на ту же страницу листалки) — для вызывающего
        // кода это не сбой, поэтому не шлём warning на этот конкретный случай.
        if (!str_contains($description, 'message is not modified')) {
            Logger::warn("Telegram: editMessageText chat_id={$chatId} message_id={$messageId} не удалось: " . ($description !== '' ? $description : 'нет ответа от API'));
        }

        return false;
    }

    private function apiUrl(string $method): string
    {
        return self::API_BASE . $this->botToken . '/' . $method;
    }

    /**
     * @param array<string, mixed>|null $postFields null — обычный GET
     * @return array<string, mixed>|null null — сетевая ошибка/невалидный JSON, не "Telegram ответил ok:false" (это остаётся в самом массиве)
     */
    private function request(string $url, ?array $postFields, int $timeoutSeconds = 30): ?array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ];
        if ($postFields !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($postFields);
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlError !== '') {
            Logger::warn("Telegram API: сетевая ошибка ({$url}): {$curlError}");
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
