<?php

declare(strict_types=1);

namespace BondKeeper\Telegram;

use BondKeeper\Support\Logger;

/**
 * Служебное сообщение администратору (config/telegram_bot.php:
 * admin_telegram_id) из CLI-скриптов — предложения сопоставления по
 * названию и итог сверки. Ошибка отправки (не настроен конфиг, сеть) не
 * должна ронять сам импорт или сверку — только предупреждение в лог.
 */
final class AdminNotifier
{
    /** @param array<string, mixed>|null $replyMarkup сырая структура reply_markup (напр. NameMatchReviews::proposalKeyboard()) — кнопки под сообщением */
    public static function send(string $text, ?array $replyMarkup = null): bool
    {
        try {
            $config = TelegramBotConfig::fromFile(dirname(__DIR__, 2) . '/config/telegram_bot.php');
            if ($config->adminTelegramId === 0) {
                Logger::warn('admin_telegram_id не настроен — сообщение администратору не отправлено.');
                return false;
            }

            // Лимит Telegram — 4096 символов на сообщение.
            return (new TelegramClient($config->botToken))->sendMessage($config->adminTelegramId, mb_substr($text, 0, 4000), $replyMarkup);
        } catch (\Throwable $e) {
            Logger::warn("Не удалось отправить сообщение администратору: {$e->getMessage()}");
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
