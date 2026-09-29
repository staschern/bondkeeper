<?php

declare(strict_types=1);

/**
 * РАЗОВЫЙ скрипт (28.09.2026): уже сохранённые рейтинги — к правилам,
 * которые теперь действуют при каждой записи (см. StoredRatingsNormalizer):
 *   1. кириллические буквы в рейтинге → латиница ("ruВВВ+" → "ruBBB+");
 *   2. отзыв НРА, записанный прочерком "—", → "отозван";
 *   3. у отозванного рейтинга прогноз пустой.
 *
 *   php bin/normalize_stored_ratings.php            # посмотреть, что поменяется
 *   php bin/normalize_stored_ratings.php --apply    # применить
 *
 * Повторный запуск безопасен: после применения менять будет нечего.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\StoredRatingsNormalizer;
use BondKeeper\Support\Logger;

$apply = in_array('--apply', $argv, true);

$report = (new StoredRatingsNormalizer(Database::connection()))->run($apply);

Logger::info($apply ? '=== ПРИМЕНЕНО ===' : '=== ПРОСМОТР: ничего не меняется, для применения добавьте --apply ===');

Logger::info('--- 1. Кириллица в рейтингах → латиница ---');
foreach ($report['grades'] as $c) {
    Logger::info("{$c['table']}.{$c['column']}: «{$c['from']}» → «{$c['to']}» — строк: {$c['rows']}");
}
Logger::info('Разных значений: ' . count($report['grades']));

Logger::info('--- 2. Отзыв НРА прочерком → «отозван» ---');
foreach ($report['nra_withdrawn'] as $c) {
    Logger::info("{$c['table']}.{$c['column']}: строк: {$c['rows']}");
}
if ($report['nra_withdrawn'] === []) {
    Logger::info('Нечего менять.');
}

Logger::info('--- 3. Отозванный рейтинг — прогноз пустой (current_ratings) ---');
foreach ($report['withdrawn_outlook'] as $r) {
    Logger::info("issuer_id={$r['issuer_id']} / {$r['agency']}: прогноз «{$r['outlook']}» → пусто");
}
Logger::info('Строк: ' . count($report['withdrawn_outlook']));
