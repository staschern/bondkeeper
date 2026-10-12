<?php

declare(strict_types=1);

namespace BondKeeper\Telegram;

use BondKeeper\Support\Logger;

/**
 * Служебное сообщение администратору (config/telegram_bot.php:
 * admin_telegram_id) из CLI-скриптов — предложения сопоставления по
 * названию и итог сверки. Ошибка отправки (не настроен конфиг, сеть) не
 * должна ронять сам импорт или сверку — только предупреждение в лог.
 *
 * Адресатов два (решение пользователя, 11.10.2026): всё про данные и
 * выплаты получает администратор (send, sendLines), технические сбои —
 * разработчик (sendTech, sendTechLines; tech_telegram_id). Если
 * разработчик в конфиге не указан, технические сообщения уходят
 * администратору.
 */
final class AdminNotifier
{
    /** @param array<string, mixed>|null $replyMarkup сырая структура reply_markup (напр. NameMatchReviews::proposalKeyboard()) — кнопки под сообщением */
    public static function send(string $text, ?array $replyMarkup = null): bool
    {
        return self::deliver(false, $text, $replyMarkup);
    }

    /** Сообщение о техническом сбое — разработчику, а если он не указан в конфиге — администратору. */
    public static function sendTech(string $text): bool
    {
        return self::deliver(true, $text, null);
    }

    /**
     * Кому уйдёт сообщение: [Telegram ID, кто это]. Отдельным методом —
     * чтобы выбор адресата проверялся без сети (tests/test_admin_notifier.php).
     *
     * @return array{0: int, 1: string}
     */
    public static function recipient(TelegramBotConfig $config, bool $technical): array
    {
        if ($technical && $config->techTelegramId !== 0) {
            return [$config->techTelegramId, 'разработчику'];
        }

        return [$config->adminTelegramId, 'администратору'];
    }

    /** @param array<string, mixed>|null $replyMarkup */
    private static function deliver(bool $technical, string $text, ?array $replyMarkup): bool
    {
        try {
            $config = TelegramBotConfig::fromFile(dirname(__DIR__, 2) . '/config/telegram_bot.php');
            [$chatId] = self::recipient($config, $technical);
            if ($chatId === 0) {
                Logger::warn(($technical ? 'tech_telegram_id и admin_telegram_id не настроены' : 'admin_telegram_id не настроен') . ' — служебное сообщение не отправлено.');
                return false;
            }

            // Лимит Telegram — 4096 символов на сообщение.
            return (new TelegramClient($config->botToken))->sendMessage($chatId, mb_substr($text, 0, 4000), $replyMarkup);
        } catch (\Throwable $e) {
            Logger::warn('Не удалось отправить сообщение ' . ($technical ? 'о сбое' : 'администратору') . ": {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Длинный текст (сводка сверки) — несколькими сообщениями, разрезая
     * только по границам строк, а не обрезая хвост.
     *
     * @param array<int, string> $lines
     */
    public static function sendLines(array $lines): bool
    {
        foreach (self::splitLines($lines) as $message) {
            if (!self::send($message)) {
                return false;
            }
        }

        return true;
    }

    /**
     * То же для технических сообщений (см. sendTech).
     *
     * @param array<int, string> $lines
     */
    public static function sendTechLines(array $lines): bool
    {
        foreach (self::splitLines($lines) as $message) {
            if (!self::sendTech($message)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string> сообщения не длиннее $limit символов (строка длиннее лимита — отдельным сообщением, обрезается в send())
     */
    public static function splitLines(array $lines, int $limit = 3800): array
    {
        $messages = [];
        $current = '';
        foreach ($lines as $line) {
            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($line) > $limit) {
                $messages[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n") . $line;
        }
        if ($current !== '') {
            $messages[] = $current;
        }

        return $messages;
    }
}
