<?php

declare(strict_types=1);

/**
 * РАЗОВЫЙ скрипт (28.09.2026): уже сохранённые рейтинги с кириллическими
 * буквами ("ruВВВ+", "ruАA-", "eAАА.ru") → латиница. Новые записи
 * нормализуются сами (RatingsNormalizer::normalizeGrade()), этот скрипт
 * выравнивает то, что было записано раньше. "отозван" не трогается.
 *
 *   php bin/fix_grade_lookalikes.php            # посмотреть, что поменяется
 *   php bin/fix_grade_lookalikes.php --apply    # применить
 *
 * Повторный запуск безопасен: после применения менять будет нечего.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\GradeLookalikeFixer;
use BondKeeper\Support\Logger;

$apply = in_array('--apply', $argv, true);

$changes = (new GradeLookalikeFixer(Database::connection()))->run($apply);

Logger::info($apply ? '=== ПРИМЕНЕНО ===' : '=== ПРОСМОТР: ничего не меняется, для применения добавьте --apply ===');
$total = 0;
foreach ($changes as $c) {
    $total += $c['rows'];
    Logger::info("{$c['table']}.{$c['column']}: «{$c['from']}» → «{$c['to']}» — строк: {$c['rows']}");
}
Logger::info('Итого строк: ' . $total . ($changes === [] ? ' (менять нечего)' : ''));
