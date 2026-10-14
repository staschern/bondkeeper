<?php

declare(strict_types=1);

namespace BondKeeper\Telegram;

use RuntimeException;

/**
 * Токен Telegram-бота — вынесен из кода в отдельный конфиг-файл
 * (config/telegram_bot.php): секрет отдельно от логики, реальный файл
 * не коммитится в git (.gitignore), в репозитории — только
 * config/telegram_bot.example.php (шаблон без настоящего значения).
 */
final class TelegramBotConfig
{
    public function __construct(
        public readonly string $botToken,
        /**
         * Telegram ID администратора — получатель обращений "Помощь"
         * (docs/BOT_UX_SPEC.md, чат-релей). 0 — не настроено, раздел
         * "Помощь" тогда работать не будет (BotCommandHandler должен сам
         * решить, что делать в этом случае — не задача конфига).
         */
        public readonly int $adminTelegramId,
        /**
         * Telegram ID разработчика — получатель сообщений о технических
         * сбоях (упал скрипт по расписанию, в базе нет нужной структуры,
         * ошибка запроса к НРД — см. AdminNotifier::sendTech()). 0 — не
         * настроено: такие сообщения уходят администратору, чтобы сбой
         * не остался только в логе.
         */
        public readonly int $techTelegramId = 0,
        /**
         * Имя бота без «@» (например, BondKeeperBot) — для ссылки с
         * промокодом, которую бот показывает администратору при назначении
         * партнёра (docs/PARTNER_PROGRAM.md). Пусто — ссылка показывается
         * шаблоном с «ИМЯ_БОТА»; на работу самих ссылок не влияет.
         */
        public readonly string $botUsername = '',
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                "Не найден конфиг Telegram-бота: {$path}. Скопируйте config/telegram_bot.example.php"
                . ' в config/telegram_bot.php и впишите токен от @BotFather (не коммитить в git).'
            );
        }

        /** @var array<string, mixed> $config */
        $config = require $path;
        $token = trim((string) ($config['bot_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException("config/telegram_bot.php не содержит непустой 'bot_token' — см. {$path} рядом (.example.php) для образца.");
        }

        return new self(
            $token,
            (int) ($config['admin_telegram_id'] ?? 0),
            (int) ($config['tech_telegram_id'] ?? 0),
            ltrim(trim((string) ($config['bot_username'] ?? '')), '@'),
        );
    }
}
