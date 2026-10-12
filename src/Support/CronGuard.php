<?php

declare(strict_types=1);

namespace BondKeeper\Support;

use BondKeeper\Telegram\AdminNotifier;

/**
 * Страховка для скриптов, которые запускает крон (11.10.2026).
 *
 * Повод: bin/payment_reminders.php четыре дня подряд (07–10.10.2026) падал
 * с ошибкой базы, и об этом никто не знал — ошибка оставалась только в
 * логе крона. После install() необработанная ошибка уходит ещё и
 * разработчику в Telegram (AdminNotifier::sendTech; если разработчик в
 * конфиге не указан — администратору).
 *
 * Одно и то же сообщение не повторяется чаще раза в REPEAT_AFTER_MIN
 * минут: скрипт, который крон запускает каждую минуту, иначе засыпал бы
 * чат. Отметка об отправленном — var/cron_guard_<скрипт>.json; она ставится
 * только после удачной отправки, а если её не удалось записать, сообщение
 * будет повторяться (лучше повтор, чем тишина).
 */
final class CronGuard
{
    private const REPEAT_AFTER_MIN = 60;

    /** @var (callable(string): bool)|null подмена отправки — для тестов */
    public static $sender = null;

    /** Папка для отметок; null — var/ в корне проекта. */
    public static ?string $stateDir = null;

    /**
     * Поставить перехват необработанных ошибок. $notify = false — только
     * лог (для --dry-run: человек и так смотрит на вывод).
     */
    public static function install(string $script, bool $notify = true): void
    {
        set_exception_handler(static function (\Throwable $e) use ($script, $notify): void {
            self::report($script, $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine(), $notify);
            exit(1);
        });
        // Фатальные ошибки PHP исключением не являются — ловим их на выходе.
        register_shutdown_function(static function () use ($script, $notify): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::report($script, (string) $error['message'], basename((string) $error['file']) . ':' . $error['line'], $notify);
            }
        });
    }

    /**
     * Сообщить о сбое: в лог — всегда; разработчику — если разрешено и
     * такое же сообщение не уходило недавно.
     *
     * @return bool ушло ли сообщение в Telegram
     */
    public static function report(string $script, string $message, string $where = '', bool $notify = true): bool
    {
        Logger::error("{$script}: {$message}" . ($where !== '' ? " ({$where})" : ''));
        $file = (self::$stateDir ?? dirname(__DIR__, 2) . '/var') . '/cron_guard_' . preg_replace('/[^a-z0-9_]+/i', '_', $script) . '.json';
        if (!$notify || self::sentRecently($file, $message)) {
            return false;
        }

        $text = "🛠 Сбой: {$script}\n{$message}" . ($where !== '' ? "\n{$where}" : '');
        $sent = (bool) (self::$sender ?? [AdminNotifier::class, 'sendTech'])($text);
        if ($sent) {
            // Отметка — только после удачной отправки: если Telegram сообщение не принял, следующий запуск попробует снова.
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            @file_put_contents($file, json_encode(['hash' => md5($message), 'sent_at' => time(), 'message' => $message], JSON_UNESCAPED_UNICODE));
        }

        return $sent;
    }

    private static function sentRecently(string $file, string $message): bool
    {
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($state) && ($state['hash'] ?? '') === md5($message) && time() - (int) ($state['sent_at'] ?? 0) < self::REPEAT_AFTER_MIN * 60;
    }
}
