<?php

declare(strict_types=1);

namespace BondKeeper\Support;

/**
 * Простой лог в stdout для cron-джобов — этого достаточно для этапа 1,
 * без внешних зависимостей (PSR-логгер можно подключить позже, когда
 * появится событийный движок и понадобится единый формат логов).
 *
 * Раньше писал через fwrite(STDOUT/STDERR, ...) — на боевом сервере
 * bondkeeper.ru это молча не давало никакого вывода (скрипт отрабатывал
 * до конца с exit code 0, без единой строки лога), хотя обычный echo в
 * том же php-cli в том же окружении выводил как положено (проверено на
 * bin/debug_iss_security.php). Причину константного поведения STDOUT/
 * STDERR в этой конкретной сборке PHP не выясняли — echo надёжнее и
 * ничем не хуже для простого cron-логгера.
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
        self::write('ERROR', $message);
    }

    private static function write(string $level, string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        echo "[{$ts}] {$level}: {$message}" . PHP_EOL;
    }
}
