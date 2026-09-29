<?php

declare(strict_types=1);

/**
 * Офлайн-проверка правил рейтинга, введённых 28.09.2026, и разового
 * выравнивания уже сохранённых данных (bin/normalize_stored_ratings.php):
 *   - RatingsNormalizer::normalizeGrade() — кириллица в рейтинге → латиница;
 *   - RatingsNormalizer::ratingFromNraColumn() — прочерк НРА → 'отозван';
 *   - RatingsNormalizer::outlookForRating() — у отозванного рейтинга (и у
 *     дефолтного грейда) прогноз пустой, для любого источника;
 *   - StoredRatingsNormalizer на SQLite.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_stored_ratings_normalizer.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\RatingsNormalizer;
use BondKeeper\Ratings\SnapshotRows;
use BondKeeper\Ratings\StoredRatingsNormalizer;

$failures = 0;
$checks = 0;

function check(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected === $actual) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n       ожидали: " . var_export($expected, true) . "\n       получили: " . var_export($actual, true) . "\n";
    }
}

echo "--- normalizeGrade(): реальные рейтинги с кириллицей ---\n";

foreach ([
    'Эксперт РА "ruВВВ+" (АК БАРС Лизинг)' => ['ruВВВ+', 'ruBBB+'],
    'Эксперт РА "ruАA-" (смешанный)' => ['ruАA-', 'ruAA-'],
    'Эксперт РА "ruАА" (Европлан)' => ['ruАА', 'ruAA'],
    'НКР "eAАА.ru" (Полюс)' => ['eAАА.ru', 'eAAA.ru'],
    'АКРА "еA(RU)" (кириллическая е)' => ['еA(RU)', 'eA(RU)'],
    'НРА "А|ru|"' => ['А|ru|', 'A|ru|'],
    'Латиница не меняется' => ['AA+.ru', 'AA+.ru'],
    '"отозван" не трогается' => ['отозван', 'отозван'],
    'Любой другой текст с кириллицей не трогается' => ['Приостановлен', 'Приостановлен'],
] as $label => [$raw, $expected]) {
    check($label, $expected, RatingsNormalizer::normalizeGrade($raw));
}

echo "\n--- ratingFromNraColumn(): отзыв НРА прочерком ---\n";

check('"—" → отозван', 'отозван', RatingsNormalizer::ratingFromNraColumn('—'));
check('"–" (короткое тире) → отозван', 'отозван', RatingsNormalizer::ratingFromNraColumn(' – '));
check('обычный рейтинг НРА — как есть, латиницей', 'A|ru|', RatingsNormalizer::ratingFromNraColumn('А|ru|'));

echo "\n--- outlookForRating(): отозван → прогноз пустой, для любого источника ---\n";

check('отозван + стабильный → пусто', null, RatingsNormalizer::outlookForRating('отозван', 'stable'));
check('отозван + review_concluded → пусто', null, RatingsNormalizer::outlookForRating('отозван', 'review_concluded'));
check('"Рейтинг отозван" (сырой текст) → пусто', null, RatingsNormalizer::outlookForRating('Рейтинг отозван', 'stable'));
check('дефолт D → пусто (как раньше)', null, RatingsNormalizer::outlookForRating('D', 'negative'));
check('обычный рейтинг — прогноз сохраняется', 'stable', RatingsNormalizer::outlookForRating('ruAA', 'stable'));

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, outlook TEXT, last_action_date TEXT, matched_by_root_name INTEGER DEFAULT 0, source TEXT, PRIMARY KEY (issuer_id, agency))');
SnapshotRows::apply($db, 'expert_ra', [
    ['issuer_id' => 1772, 'issuer_name' => 'ПКБ', 'rating' => 'отозван', 'outlook' => 'stable', 'last_action_date' => '2021-07-21', 'source_url' => null],
]);
check('перезапись снимком: отозван + стабильный из списка агентства → прогноз пустой', null, $db->query('SELECT outlook FROM current_ratings WHERE issuer_id = 1772')->fetchColumn());

echo "\n--- StoredRatingsNormalizer: разовое выравнивание сохранённых данных ---\n";

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, outlook TEXT, PRIMARY KEY (issuer_id, agency))');
$db->exec('CREATE TABLE rating_actions (id INTEGER PRIMARY KEY, issuer_id INTEGER, agency TEXT, rating_from TEXT, rating_to TEXT, outlook_to TEXT)');
$db->exec("INSERT INTO current_ratings VALUES
    (1, 'expert_ra', 'ruВВВ+', 'stable'),
    (2, 'expert_ra', 'ruAA', 'stable'),
    (3, 'nkr', 'отозван', 'positive'),
    (4, 'nra', '—', 'review_concluded'),
    (5, 'nra', '—', NULL),
    (6, 'expert_ra', 'ruВВВ+', NULL),
    (7, 'nra', 'D|ru|', 'review_concluded'),
    (8, 'acra', 'D(RU)', 'developing'),
    (9, 'nkr', 'D', 'under_review_negative'),
    (10, 'nkr', 'C.ru', 'negative'),
    (11, 'expert_ra', 'ruD', NULL)");
$db->exec("INSERT INTO rating_actions VALUES
    (1, 1, 'expert_ra', 'ruВВ+', 'ruВВВ+', 'stable'),
    (2, 3, 'nkr', 'AA.ru', 'отозван', NULL),
    (3, 4, 'nra', 'BB|ru|', '—', 'review_concluded')");

$fixer = new StoredRatingsNormalizer($db);
$preview = $fixer->run(false);
check('просмотр: 3 разных значения с кириллицей', 3, count($preview['grades']));
check('просмотр: прочерки НРА в current_ratings и rating_actions.rating_to', [['current_ratings', 'rating', 2], ['rating_actions', 'rating_to', 1]],
    array_map(static fn (array $c): array => [$c['table'], $c['column'], $c['rows']], $preview['nra_withdrawn']));
check('просмотр: прогноз обнулится у 3 (НКР отозван) и 4 (НРА прочерк)', [[3, 'nkr'], [4, 'nra']],
    array_map(static fn (array $r): array => [$r['issuer_id'], $r['agency']], $preview['withdrawn_outlook']));
check('просмотр: дефолтный рейтинг — прогноз обнулится у 7 (НРА D|ru|, «Антерра»), 8 (АКРА D(RU)), 9 (НКР D); C.ru и ruD без прогноза не в списке',
    [[8, 'acra', 'D(RU)'], [9, 'nkr', 'D'], [7, 'nra', 'D|ru|']],
    array_map(static fn (array $r): array => [$r['issuer_id'], $r['agency'], $r['rating']], $preview['default_outlook']));
check('просмотр: данные не изменены', 'ruВВВ+', $db->query('SELECT rating FROM current_ratings WHERE issuer_id = 1')->fetchColumn());

$fixer->run(true);
check('применено: current_ratings.rating', ['ruBBB+', 'ruAA', 'отозван', 'отозван', 'отозван', 'ruBBB+', 'D|ru|', 'D(RU)', 'D', 'C.ru', 'ruD'],
    $db->query('SELECT rating FROM current_ratings ORDER BY issuer_id')->fetchAll(PDO::FETCH_COLUMN));
check('применено: current_ratings.outlook (у отозванных и дефолтных пусто, у остальных — как было)', ['stable', 'stable', null, null, null, null, null, null, null, 'negative', null],
    $db->query('SELECT outlook FROM current_ratings ORDER BY issuer_id')->fetchAll(PDO::FETCH_COLUMN));
check('применено: rating_actions.rating_to', ['ruBBB+', 'отозван', 'отозван'], $db->query('SELECT rating_to FROM rating_actions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
check('история прогноза в rating_actions не трогается', ['stable', null, 'review_concluded'], $db->query('SELECT outlook_to FROM rating_actions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
check('повторный запуск — менять нечего', ['grades' => [], 'nra_withdrawn' => [], 'withdrawn_outlook' => [], 'default_outlook' => []], $fixer->run(true));

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
