<?php

declare(strict_types=1);

/**
 * Токен Telegram-бота (Bot API) — используется TelegramBotConfig /
 * TelegramClient / bin/daemon_telegram_bot.php.
 *
 * СКОПИРУЙТЕ этот файл в telegram_bot.php (без ".example") в этой же
 * папке и впишите реальный токен — сам telegram_bot.php НЕ должен
 * коммититься в git (см. .gitignore).
 *
 * Получение токена (шаг на стороне пользователя, автоматизировать
 * нельзя — BotFather сам защищён той же логикой Bot API, которую он
 * выдаёт): в Telegram написать @BotFather -> /newbot -> задать
 * отображаемое имя и уникальный @username, заканчивающийся на "bot"
 * (например, BondKeeperBot). BotFather пришлёт токен вида
 * "123456789:AA...", вставить его сюда целиком.
 */

return [
    'bot_token' => '', // токен от @BotFather, например "123456789:AAExampleTokenValue"

    // Telegram ID администратора, куда попадают обращения из раздела
    // "Помощь" (docs/BOT_UX_SPEC.md) — числовой id, не @username. Узнать
    // свой id можно, написав что угодно боту и посмотрев
    // update.message.from.id в getUpdates(), либо через @userinfobot.
    'admin_telegram_id' => 0,
];
