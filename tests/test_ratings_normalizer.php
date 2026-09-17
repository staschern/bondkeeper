<?php

declare(strict_types=1);

/**
 * Офлайн-проверка RatingsNormalizer::isBondIssueRedemptionWithdrawal()
 * (17 сентября 2026) — чистая текстовая логика, не касается БД/сети,
 * полностью тестируема без SQLite/MySQL.
 *
 * Живая находка пользователя: заголовок "АКРА ОТОЗВАЛО КРЕДИТНЫЙ РЕЙТИНГ
 * ВЫПУСКА ОБЛИГАЦИЙ ПАО «ФОСАГРО» СЕРИИ БО-П02 (RU000A109K40) В СВЯЗИ С
 * ПОГАШЕНИЕМ ВЫПУСКА" раньше писал ложное "рейтинг отозван" в
 * current_ratings ЭМИТЕНТА (ФосАгро), хотя это техническое снятие
 * рейтинга с одной погашенной серии облигаций, а не отзыв кредитного
 * рейтинга самой компании. Метод и его использование в
 * AcraNewsImporter/NkrNewsImporter/ExpertRaNewsImporter теперь полностью
 * исключают такие строки из БД.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring tests/test_ratings_normalizer.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаг -d не нужен.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\RatingsNormalizer;

$failures = 0;
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n";
    }
}

// --- Реальный живой пример, ровно из отчёта пользователя ---
check(
    'ФосАгро (реальный заголовок АКРА, 17 сентября 2026): выпуск + погашение -> true',
    RatingsNormalizer::isBondIssueRedemptionWithdrawal(
        'АКРА ОТОЗВАЛО КРЕДИТНЫЙ РЕЙТИНГ ВЫПУСКА ОБЛИГАЦИЙ ПАО «ФОСАГРО» СЕРИИ БО-П02 (RU000A109K40) В СВЯЗИ С ПОГАШЕНИЕМ ВЫПУСКА'
    ) === true
);

// --- Оба падежных/словопорядковых варианта фразы про выпуск ---
check(
    '"рейтинг выпуска облигаций" + "погашением" -> true',
    RatingsNormalizer::isBondIssueRedemptionWithdrawal(
        'АКРА отозвало кредитный рейтинг выпуска облигаций АО «Россельхозбанк» (RU000A103N84) в связи с погашением'
    ) === true
);
check(
    '"рейтинг облигационного выпуска" (обратный порядок слов) + "погашения" -> true',
    RatingsNormalizer::isBondIssueRedemptionWithdrawal(
        '«Эксперт РА» отозвал кредитный рейтинг облигационного выпуска ПАО «Х» по причине его полного погашения'
    ) === true
);

// --- Настоящий отзыв рейтинга ЭМИТЕНТА — НЕ должен считаться шумом ---
check(
    'Отзыв рейтинга эмитента (не выпуска, не из-за погашения) -> false',
    RatingsNormalizer::isBondIssueRedemptionWithdrawal(
        'НКР отозвало кредитный рейтинг ООО «Ромашка» в связи с отзывом лицензии'
    ) === false
);
check(
    'Отзыв рейтинга эмитента, "погашение" упомянуто про другое (задолженность) -> false',
    RatingsNormalizer::isBondIssueRedemptionWithdrawal(
        'АКРА отозвало кредитный рейтинг ПАО «Х» в связи с погашением задолженности перед кредиторами'
    ) === false
);

// --- Есть "выпуска облигаций", но НЕ отзыв (подтверждение) -> тут проверяем только текстовый признак ---
check(
    '"рейтинг выпуска облигаций" БЕЗ слова "погашен" -> false (нет признака погашения)',
    RatingsNormalizer::isBondIssueRedemptionWithdrawal(
        'НКР отозвало кредитный рейтинг выпуска облигаций ООО «Y»'
    ) === false
);
check(
    '"рейтинг выпуска облигаций" + подтверждение (не про погашение вообще) -> false',
    RatingsNormalizer::isBondIssueRedemptionWithdrawal(
        'АКРА подтвердило кредитный рейтинг выпуска облигаций ПАО «Х» на уровне AAA(RU)'
    ) === false
);

// --- Регистронезависимость ---
check(
    'Регистр не влияет — тот же заголовок строчными буквами -> true',
    RatingsNormalizer::isBondIssueRedemptionWithdrawal(
        'акра отозвало кредитный рейтинг выпуска облигаций пао «фосагро» в связи с погашением выпуска'
    ) === true
);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
