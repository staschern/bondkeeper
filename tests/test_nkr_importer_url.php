<?php

declare(strict_types=1);

/**
 * Офлайн-проверка NkrImporter::normalizeUrl()/describeRow() — чистая
 * текстовая логика, без сети/БД. Живая находка (28 сентября 2026,
 * реальный случай «Группа «ВИС» (АО)»): колонка "Press release" у НКР
 * иногда отдаёт адрес БЕЗ схемы ("ratings.ru/..."), старая проверка
 * `^https?://` не признавала такую строку ссылкой — она терялась в
 * тексте заголовка вместо source_url, а в предложении на подтверждение
 * подставлялся общий список эмитентов вместо настоящего пресс-релиза.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=pdo_sqlite -d extension=mbstring tests/test_nkr_importer_url.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\NkrImporter;

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

function callStatic(string $method, array $args)
{
    $ref = new ReflectionClass(NkrImporter::class);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs(null, $args);
}

echo "--- normalizeUrl() ---\n";

check('normalizeUrl(): пустая строка -> null', null, callStatic('normalizeUrl', ['']));
check(
    'normalizeUrl(): со схемой https:// -> как есть',
    'https://ratings.ru/ratings/press-releases/VIS-RA-160726/',
    callStatic('normalizeUrl', ['https://ratings.ru/ratings/press-releases/VIS-RA-160726/'])
);
check(
    'normalizeUrl(): реальный случай — БЕЗ схемы (Группа «ВИС», 28 сентября 2026) -> https:// подставлена',
    'https://ratings.ru/ratings/press-releases/VIS-RA-160726/',
    callStatic('normalizeUrl', ['ratings.ru/ratings/press-releases/VIS-RA-160726/'])
);
check('normalizeUrl(): голый домен без пути -> https:// подставлена', 'https://ratings.ru', callStatic('normalizeUrl', ['ratings.ru']));
check('normalizeUrl(): не похоже на URL (обычный текст) -> null', null, callStatic('normalizeUrl', ['уточняется']));
check('normalizeUrl(): не похоже на URL (просто слово без точки-домена) -> null', null, callStatic('normalizeUrl', ['нет']));

echo "\n--- describeRow() ---\n";

[$titleWithScheme, $urlWithScheme] = callStatic('describeRow', [
    ['Issuer Name' => 'ПАО «Ростелеком»', 'Outlook' => 'stable', 'Date' => '01.07.2026', 'Press release' => 'https://ratings.ru/ratings/press-releases/RTK-RA-010726/'],
    'AAA.ru',
]);
check('describeRow(): ссылка СО схемой -> идёт в URL, не в заголовок', $urlWithScheme, 'https://ratings.ru/ratings/press-releases/RTK-RA-010726/');
check('describeRow(): ссылка СО схемой -> заголовок БЕЗ дублирующего "; пресс-релиз:"', false, str_contains($titleWithScheme, 'пресс-релиз:'));

[$titleNoScheme, $urlNoScheme] = callStatic('describeRow', [
    ['Issuer Name' => 'Группа «ВИС» (АО)', 'Outlook' => 'стабильный', 'Date' => '16.07.2026', 'Press release' => 'ratings.ru/ratings/press-releases/VIS-RA-160726/'],
    'AA-.ru',
]);
check(
    'describeRow(): реальный случай — ссылка БЕЗ схемы теперь тоже уходит в URL (баг исправлен)',
    'https://ratings.ru/ratings/press-releases/VIS-RA-160726/',
    $urlNoScheme
);
check('describeRow(): ссылка БЕЗ схемы -> заголовок БЕЗ дублирующего "; пресс-релиз:" (раньше туда уезжал весь адрес)', false, str_contains($titleNoScheme, 'пресс-релиз:'));
check('describeRow(): имя эмитента и рейтинг всё равно в заголовке', true, str_contains($titleNoScheme, 'Группа «ВИС» (АО)') && str_contains($titleNoScheme, 'AA-.ru'));

[$titleNoUrl, $urlNoUrl] = callStatic('describeRow', [
    ['Issuer Name' => 'ООО «Ромашка»', 'Outlook' => '', 'Date' => '', 'Press release' => 'уточняется'],
    'BBB.ru',
]);
check('describeRow(): в колонке НЕ похожее на ссылку значение -> падает в заголовок как раньше', true, str_contains($titleNoUrl, 'пресс-релиз: уточняется'));
check('describeRow(): нет распознанной ссылки -> общий список эмитентов НКР', 'https://ratings.ru/ratings/issuers/', $urlNoUrl);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
