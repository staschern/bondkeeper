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
 * Запуск:
 *   nohup php bin/daemon_telegram_bot.php >> /var/log/bondkeeper/daemon_telegram_bot.log 2>&1 &
 * Остановка — обычный kill процесса (PID выводится в лог при старте) или
 * Ctrl+C на переднем плане. Тот же принцип "не демонизируется средствами
 * PHP", что и у остальных bin/daemon_*.php.
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
        }
        $lastDispatchAt = time();
    }
}
