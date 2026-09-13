<?php

declare(strict_types=1);

namespace BondKeeper\Telegram;

/**
 * Только то, что реально нужно BotCommandHandler/NotificationDispatcher
 * (тот же принцип, что BondKeeper\Fns\NalogBiClientInterface у
 * FnsBlocksImporter) — позволяет подставить фейковую реализацию для
 * офлайн-теста БД-логики без обращения к реальному Bot API.
 * getUpdates() сюда намеренно не входит — им пользуется только
 * bin/daemon_telegram_bot.php напрямую через TelegramClient, подменять
 * его в тестах не требуется.
 */
interface TelegramClientInterface
{
    /**
     * @param array<string, mixed>|null $replyMarkup
     * @param string|null $parseMode 'HTML' — разбирать <b>/<i>/... в $text
     *   (Bot API parse_mode); null — как есть, без разметки (по умолчанию,
     *   не менять поведение существующих вызовов).
     */
    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): bool;

    /** @param array<string, mixed>|null $replyMarkup */
    public function sendMessageReturningId(int $chatId, string $text, ?array $replyMarkup = null): ?int;

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool;

    /** @param array<string, mixed>|null $replyMarkup */
    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool;

    /**
     * true — последний вызов sendMessage()/sendMessageReturningId() упал
     * именно с "Forbidden: bot was blocked by the user" (см.
     * TelegramClient) — читается NotificationDispatcher'ом (Этап 4,
     * Фаза 3) перед тем, как проставить users.telegram_bot_blocked.
     */
    public function lastSendWasBlockedByUser(): bool;
}
