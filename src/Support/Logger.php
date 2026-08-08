<?php

declare(strict_types=1);

namespace BondKeeper\Support;

/**
 * Простой лог в stdout/stderr для cron-джобов — этого достаточно для этапа 1,
 * без внешних зависимостей (PSR-логгер можно подключить позже, когда
 * появится событийный движок и понадобится единый формат логов).
 */
final class Logger
{
    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function warn(string $message): void
    {
        self::write('WARN', $message);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', $message, STDERR);
    }

    /** @param resource|null $stream */
    private static function write(string $level, string $message, $stream = STDOUT): void
    {
        $ts = date('Y-m-d H:i:s');
        fwrite($stream, "[{$ts}] {$level}: {$message}" . PHP_EOL);
    }
}
