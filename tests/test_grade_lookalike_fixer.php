<?php

declare(strict_types=1);

/**
 * Офлайн-проверка замены кириллических двойников в рейтингах (28.09.2026):
 * RatingsNormalizer::normalizeGrade() и разовый GradeLookalikeFixer
 * (bin/fix_grade_lookalikes.php) на SQLite.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_grade_lookalike_fixer.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\GradeLookalikeFixer;
use BondKeeper\Ratings\RatingsNormalizer;

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

echo "\n--- GradeLookalikeFixer: разовое выравнивание сохранённых данных ---\n";

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, PRIMARY KEY (issuer_id, agency))');
$db->exec('CREATE TABLE rating_actions (id INTEGER PRIMARY KEY, issuer_id INTEGER, agency TEXT, rating_from TEXT, rating_to TEXT)');
$db->exec("INSERT INTO current_ratings VALUES (1, 'expert_ra', 'ruВВВ+'), (2, 'expert_ra', 'ruAA'), (3, 'nkr', 'отозван'), (4, 'expert_ra', 'ruВВВ+')");
$db->exec("INSERT INTO rating_actions VALUES (1, 1, 'expert_ra', 'ruВВ+', 'ruВВВ+'), (2, 2, 'expert_ra', NULL, 'ruAA'), (3, 3, 'nkr', 'AA.ru', 'отозван')");

$fixer = new GradeLookalikeFixer($db);
$preview = $fixer->run(false);
check('просмотр: найдено 3 разных значения к замене', 3, count($preview));
check('просмотр: данные не изменены', 'ruВВВ+', $db->query('SELECT rating FROM current_ratings WHERE issuer_id = 1')->fetchColumn());
check('просмотр: «ruВВВ+» в current_ratings — 2 строки', 2, $preview[0]['rows']);

$fixer->run(true);
check('применено: current_ratings', ['ruBBB+', 'ruAA', 'отозван', 'ruBBB+'], $db->query('SELECT rating FROM current_ratings ORDER BY issuer_id')->fetchAll(PDO::FETCH_COLUMN));
check('применено: rating_actions.rating_from', ['ruBB+', null, 'AA.ru'], $db->query('SELECT rating_from FROM rating_actions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
check('применено: rating_actions.rating_to', ['ruBBB+', 'ruAA', 'отозван'], $db->query('SELECT rating_to FROM rating_actions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
check('повторный запуск — менять нечего', [], $fixer->run(true));

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
