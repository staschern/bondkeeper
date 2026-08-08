<?php

declare(strict_types=1);

// Простой PSR-4-подобный автозагрузчик без composer — намеренно, чтобы
// сидинг-скрипты запускались на обычном shared-хостинге через cron без
// шага `composer install` (см. README.md).
spl_autoload_register(static function (string $class): void {
    $prefix = 'BondKeeper\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use BondKeeper\Support\Env;

Env::load(__DIR__ . '/../.env');
