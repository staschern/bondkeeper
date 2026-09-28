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
    public static function send(string $text): bool
    {
        try {
            $config = TelegramBotConfig::fromFile(dirname(__DIR__, 2) . '/config/telegram_bot.php');
            if ($config->adminTelegramId === 0) {
                Logger::warn('admin_telegram_id не настроен — сообщение администратору не отправлено.');
                return false;
            }

            // Лимит Telegram — 4096 символов на сообщение.
            return (new TelegramClient($config->botToken))->sendMessage($config->adminTelegramId, mb_substr($text, 0, 4000));
        } catch (\Throwable $e) {
            Logger::warn("Не удалось отправить сообщение администратору: {$e->getMessage()}");
            return false;
        }
    }
}
