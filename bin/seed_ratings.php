<?php

declare(strict_types=1);

/**
 * Этап 3: наполнить current_ratings из выгрузок рейтинговых агентств.
 * Один флаг --agency на запуск — у каждого агентства свой источник
 * (Excel-выгрузка НКР/НРА; Эксперт РА и АКРА пока не реализованы, см.
 * docs/STAGE3_RATINGS.md) и свой формат, общего импортёра на все 4 нет.
 *
 * Запуск:
 *   php bin/seed_ratings.php --agency=nkr
 *   php bin/seed_ratings.php --agency=nra
 *
 * По расписанию — по одному агентству, не одновременно (вежливость к
 * чужим серверам, та же логика, что и у check_fns_blocks.php):
 *   0 5 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nkr >> /var/log/bondkeeper/seed_ratings_nkr.log 2>&1
 *   30 5 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nra >> /var/log/bondkeeper/seed_ratings_nra.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\NkrImporter;
use BondKeeper\Ratings\NraImporter;
use BondKeeper\Support\Logger;

$agency = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--agency=')) {
        $agency = substr($arg, strlen('--agency='));
    }
}

if ($agency === null) {
    fwrite(STDERR, "Использование: php bin/seed_ratings.php --agency=nkr|nra\n");
    exit(1);
}

$db = Database::connection();
$matcher = new IssuerMatcher($db);

$importer = match ($agency) {
    'nkr' => new NkrImporter($db, $matcher),
    'nra' => new NraImporter($db, $matcher),
    default => null,
};

if ($importer === null) {
    fwrite(STDERR, "Неизвестное агентство: {$agency}. Поддерживаются: nkr, nra.\n");
    exit(1);
}

Logger::info("Старт: сидирование current_ratings ({$agency})");
$importer->import();
Logger::info('Готово.');
