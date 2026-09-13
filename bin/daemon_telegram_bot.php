<?php

declare(strict_types=1);

/**
 * Этап 4, Фазы 2+3 (см. docs/STAGE4_EVENT_ENGINE.md) — Telegram-бот:
 * приём команд (long polling) И рассылка уведомлений подписчикам,
 * в одном процессе, одним циклом.
 *
 * Почему один процесс, а не два демона: getUpdates() и так блокируется
 * на сервере Telegram до LONG_POLL_TIMEOUT_SECONDS (long polling) —
 * это уже естественный "тик" цикла, к которому дёшево подвесить второй,
 * более редкий проход (рассылка), без отдельного демона/крона.
 * Рассылка не блокирует ответы на команды дольше, чем сама заняла бы
 * место одного тика long-polling.
 *
 * Офсет getUpdates() держится ТОЛЬКО в памяти процесса, без сохранения на
 * диск/в БД — при перезапуске Telegram может повторно прислать последние
 * ещё не подтверждённые апдейты ("at least once" у Bot API, обычное
 * поведение). См. докблок BotCommandHandler — обработчики команд это
 * переживают безопасно. Рассылка (NotificationDispatcher) идемпотентна
 * независимо — UNIQUE KEY (user_id, event_id, channel) в notifications.
 *
 * Предварительное условие: реальный токен в config/telegram_bot.php (см.
 * config/telegram_bot.example.php — как получить у @BotFather).
 *
 * === Переподключение к MySQL (найдено вживую, 13 сентября 2026) ===
 *
 * В отличие от bin/seed_*.php/bin/check_fns_blocks.php (короткоживущие
 * cron-процессы — подключение открывается и умирает вместе с ними), этот
 * демон живёт часами/сутками одним процессом. MySQL сама закрывает
 * простаивающее соединение по wait_timeout — PDO-синглтон
 * (BondKeeper\Database) об этом заранее не знает, следующий же запрос
 * падает с "MySQL server has gone away". Без переподключения ЛЮБОЙ такой
 * разрыв означал бы, что рассылка (и приём команд) молча умирают навсегда
 * до ручного перезапуска процесса — что и произошло на бою (несколько
 * часов подряд одна и та же ошибка на каждом проходе рассылки). См.
 * reconnectDependents() ниже и BondKeeper\Database::isConnectionLost()/
 * reconnect().
 *
 * Запуск в бою — через systemd (переживает падение процесса и
 * перезагрузку сервера, чего `nohup ... &` не даёт сам по себе — найдено
 * вживую 13 сентября 2026, юнит `bondkeeper-telegram-bot.service`,
 * `ExecStart=/usr/bin/php bin/daemon_telegram_bot.php`,
 * `WorkingDirectory` — корень репозитория, `Restart=always`, лог — в
 * `var/log/daemon_telegram_bot.log` внутри репозитория, тот же каталог,
 * что уже используют cron-задачи `seed_ratings.php`). Ручной разовый
 * запуск для отладки — как у остальных bin/daemon_*.php:
 *   nohup php bin/daemon_telegram_bot.php >> var/log/daemon_telegram_bot.log 2>&1 &
 * Остановка — `systemctl stop bondkeeper-telegram-bot` (или обычный kill
 * процесса при ручном запуске). Само по себе не демонизируется средствами
 * PHP — это делает systemd/nohup, тот же принцип, что и у остальных
 * bin/daemon_*.php.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\BotCommandHandler;
use BondKeeper\Telegram\NotificationDispatcher;
use BondKeeper\Telegram\TelegramBotConfig;
use BondKeeper\Telegram\TelegramClient;

const LONG_POLL_TIMEOUT_SECONDS = 25;
const NETWORK_ERROR_COOLDOWN_SECONDS = 5;
const DISPATCH_INTERVAL_SECONDS = 60; // раз в минуту достаточно — события создаются импортёрами не чаще, чем раз в несколько минут

$configFile = dirname(__DIR__) . '/config/telegram_bot.php';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--config=')) {
        $configFile = substr($arg, 9);
    }
}

$config = TelegramBotConfig::fromFile($configFile);
$telegram = new TelegramClient($config->botToken);
$db = Database::connection();
$handler = new BotCommandHandler($db, $telegram, new IssuerMatcher($db), $config->adminTelegramId);
$dispatcher = new NotificationDispatcher($db, $telegram);

/**
 * $db передан в $handler/$dispatcher конструктором как readonly-свойство
 * — простая замена Database::$connection (через Database::reconnect())
 * на них саму по себе не подействует, оба объекта нужно пересоздать
 * заново поверх свежего PDO. Вызывается ТОЛЬКО когда
 * Database::isConnectionLost() подтвердил, что это именно "соединение
 * умерло само" — не на каждую ошибку подряд.
 */
function reconnectDependents(TelegramClient $telegram, TelegramBotConfig $config): array
{
    Logger::warn('Telegram-бот: MySQL-соединение протухло (server has gone away) — переподключаюсь');
    $db = Database::reconnect();

    return [
        $db,
        new BotCommandHandler($db, $telegram, new IssuerMatcher($db), $config->adminTelegramId),
        new NotificationDispatcher($db, $telegram),
    ];
}

Logger::info('Telegram-бот запущен, PID=' . getmypid() . ' (long polling команд + рассылка раз в ' . DISPATCH_INTERVAL_SECONDS . ' с)');

$offset = 0;
$lastDispatchAt = 0; // 0 — первый же проход цикла сразу выполнит рассылку (безопаснее, чем ждать минуту после рестарта)
while (true) {
    try {
        $updates = $telegram->getUpdates($offset, LONG_POLL_TIMEOUT_SECONDS);
    } catch (\Throwable $e) {
        Logger::warn('Telegram-бот: ошибка long-polling запроса: ' . $e->getMessage());
        sleep(NETWORK_ERROR_COOLDOWN_SECONDS); // не долбить API сразу же после сетевой ошибки
        continue;
    }

    foreach ($updates as $update) {
        $offset = max($offset, ((int) ($update['update_id'] ?? 0)) + 1);

        try {
            $handler->handleUpdate($update);
        } catch (\Throwable $e) {
            // Ошибка в ОДНОМ апдейте не должна убивать весь процесс — тот
            // же принцип, что и в остальных bin/daemon_*.php: офсет уже
            // продвинут выше, так что сломанный апдейт не зациклит бота
            // повторными попытками, просто будет потерян с явной записью
            // в лог.
            Logger::warn('Telegram-бот: ошибка обработки апдейта #' . ($update['update_id'] ?? '?') . ': ' . $e->getMessage());
            if (Database::isConnectionLost($e)) {
                [$db, $handler, $dispatcher] = reconnectDependents($telegram, $config);
            }
        }
    }

    if (time() - $lastDispatchAt >= DISPATCH_INTERVAL_SECONDS) {
        try {
            $sent = $dispatcher->dispatchPending();
            if ($sent > 0) {
                Logger::info("Telegram-бот: разослано уведомлений — {$sent}");
            }
        } catch (\Throwable $e) {
            // Сбой рассылки НЕ должен останавливать приём команд —
            // следующий проход (через DISPATCH_INTERVAL_SECONDS) попробует
            // снова, ничего вручную перезапускать не нужно.
            Logger::warn('Telegram-бот: ошибка рассылки уведомлений: ' . $e->getMessage());
            if (Database::isConnectionLost($e)) {
                [$db, $handler, $dispatcher] = reconnectDependents($telegram, $config);
            }
        }
        $lastDispatchAt = time();
    }
}
