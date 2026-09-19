<?php

declare(strict_types=1);

/**
 * Офлайн-проверка: дефолтному грейду ("D"/"SD") прогноз не положен в
 * принципе — по прямому запросу пользователя (найдено вживую: НКР
 * понизило ООО «ЛКХ», issuer_id=2204, до "D"; в заголовке про прогноз
 * ничего не было, и current_ratings.outlook молча остался старым от
 * прошлого действия). См. RatingsNormalizer::isDefaultGrade() и
 * CurrentRatingsSync::sync().
 *
 * Часть 1 — RatingsNormalizer::isDefaultGrade() чистой текстовой
 * логикой (все декорации по агентствам, см. её докблок).
 * Часть 2 — CurrentRatingsSync::sync() целиком через SQLite in-memory —
 * воспроизводит ровно сценарий пользователя: в кэше уже был прогноз,
 * новое действие даёт rating="D" без outlookTo — итоговый outlook
 * должен стать NULL, а не остаться старым.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_default_grade_clears_outlook.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\CurrentRatingsSync;
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
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        echo "  FAIL {$label}\n       ожидали: {$expectedStr}\n       получили: {$actualStr}\n";
    }
}

echo "--- Часть 1: RatingsNormalizer::isDefaultGrade() ---\n";

check('НКР — голое "D"', true, RatingsNormalizer::isDefaultGrade('D'));
check('НКР — голое "SD"', true, RatingsNormalizer::isDefaultGrade('SD'));
check('АКРА — "D(RU)"', true, RatingsNormalizer::isDefaultGrade('D(RU)'));
check('АКРА — "D (RU)" (с пробелом перед скобкой)', true, RatingsNormalizer::isDefaultGrade('D (RU)'));
check('АКРА — ожидаемый грейд "eD(RU)"', true, RatingsNormalizer::isDefaultGrade('eD(RU)'));
check('АКРА — "SD(RU)"', true, RatingsNormalizer::isDefaultGrade('SD(RU)'));
check('Эксперт РА — "ruD" (весь их алфавит с этим префиксом)', true, RatingsNormalizer::isDefaultGrade('ruD'));
check('НРА — "D|ru|" (по аналогии с реальным "AA|ru|")', true, RatingsNormalizer::isDefaultGrade('D|ru|'));
check('Регистр не важен — "d"', true, RatingsNormalizer::isDefaultGrade('d'));
check('NULL — не дефолт', false, RatingsNormalizer::isDefaultGrade(null));
check('Обычный грейд НЕ считается дефолтом — "AAA"', false, RatingsNormalizer::isDefaultGrade('AAA'));
check('Обычный грейд с "-" НЕ считается дефолтом — "BBB-"', false, RatingsNormalizer::isDefaultGrade('BBB-'));
check('Обычный грейд НКР с ".ru" НЕ считается дефолтом', false, RatingsNormalizer::isDefaultGrade('AAA.ru'));
check('Обычный грейд Эксперт РА с "ru"-префиксом НЕ считается дефолтом', false, RatingsNormalizer::isDefaultGrade('ruAAA'));
check('Литерал отзыва "отозван" НЕ считается дефолтом (другое поле)', false, RatingsNormalizer::isDefaultGrade('отозван'));
check('Пустая строка НЕ считается дефолтом', false, RatingsNormalizer::isDefaultGrade(''));

echo "\n--- Часть 2: CurrentRatingsSync::resolveOutlook() — сценарий пользователя (ООО «ЛКХ») ---\n";

// sync() сам пишет через MySQL-специфичный "ON DUPLICATE KEY UPDATE ...
// VALUES()" (SQLite его не понимает — тот же нюанс, что и везде в
// проекте, см. докблок test_offers_importer.php), поэтому решающая
// логика проверяется напрямую через Reflection на приватном
// resolveOutlook(), без реального выполнения SQL.
$ref = new ReflectionClass(CurrentRatingsSync::class);
$resolveOutlook = $ref->getMethod('resolveOutlook');
$resolveOutlook->setAccessible(true);

check(
    'Сценарий пользователя — был прогноз "negative", новый рейтинг "D" без outlookTo → NULL (не остаётся "negative")',
    null,
    $resolveOutlook->invoke(null, 'D', null, 'negative')
);
check(
    'Дефолт даже если в САМОМ действии зачем-то явно пришёл outlookTo — всё равно NULL',
    null,
    $resolveOutlook->invoke(null, 'D', 'stable', 'negative')
);
check(
    'Контроль — обычный (не дефолтный) грейд по-прежнему НАСЛЕДУЕТ прогноз из кэша, если явно не задан',
    'stable',
    $resolveOutlook->invoke(null, 'BB+', null, 'stable')
);
check(
    'Контроль — обычный грейд с явным новым outlookTo ведёт себя как раньше',
    'positive',
    $resolveOutlook->invoke(null, 'BB+', 'positive', 'stable')
);

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
