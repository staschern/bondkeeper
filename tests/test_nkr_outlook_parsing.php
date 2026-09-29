<?php

declare(strict_types=1);

/**
 * Офлайн-проверка чтения прогноза НКР (сентябрь 2026):
 *   - NkrTitleParser::extractOutlook() — заголовок или вводный абзац;
 *   - NkrTitleParser::extractLeadFromDetailHtml() — вводный абзац
 *     пресс-релиза;
 *   - «неопределённый» → indefinite, «развивающийся» → developing
 *     (разные коды, миграция 026);
 *   - ссылка на пресс-релиз из колонки "Press release" выгрузки НКР
 *     (адрес без https://) — RatingsNormalizer::absoluteUrl().
 *
 * Все фразы — дословно из реальных пресс-релизов ratings.ru (прогон по 120
 * релизам июня–сентября 2026). Живой случай, из-за которого это
 * понадобилось: АО «ГИДРОМАШСЕРВИС», 24.09.2026 — заголовок без прогноза,
 * смена прогноза со стабильного на негативный только в тексте.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring tests/test_nkr_outlook_parsing.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаг -d не нужен.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\NkrImporter;
use BondKeeper\Ratings\NkrTitleParser;
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

echo "--- extractOutlook(): вводные предложения пресс-релизов ---\n";

$cases = [
    'ГИДРОМАШСЕРВИС: "со стабильного на негативный" → то, что после "на"' => [
        'negative',
        'Рейтинговое агентство НКР снизило кредитный рейтинг АО «ГИДРОМАШСЕРВИС» (далее также «компания») с A+.ru до A.ru и изменило прогноз по кредитному рейтингу со стабильного на негативный.',
    ],
    'МТС-Банк: "с позитивного на стабильный" → stable' => [
        'stable',
        'Рейтинговое агентство НКР повысило кредитный рейтинг ПАО «МТС-Банк» (далее — «МТС-Банк», «банк») с A.ru до A+.ru и изменило прогноз по кредитному рейтингу с позитивного на стабильный.',
    ],
    'АЗ «НАЗ»: "с негативного на неопределённый" → indefinite (не negative)' => [
        'indefinite',
        'Рейтинговое агентство НКР снизило кредитный рейтинг ООО «АЗ «НАЗ» (далее — «НАЗ», «компания») с A.ru до A-.ru и изменило прогноз по кредитному рейтингу с негативного на неопределённый.',
    ],
    'ВИС: "прогноз … остался стабильным" (точка с запятой внутри предложения)' => [
        'stable',
        'Рейтинговое агентство НКР подтвердило кредитный рейтинг Группа «ВИС» (АО) (далее также «компания») на уровне AA-.ru; прогноз по кредитному рейтингу остался стабильным.',
    ],
    'Селектел: "со стабильным прогнозом"' => [
        'stable',
        'Рейтинговое агентство НКР присвоило АО «Селектел» (далее также «компания») кредитный рейтинг на уровне A+.ru со стабильным прогнозом.',
    ],
    'АВТОБАН-Финанс: отзыв "и прогноз по нему" — значения прогноза нет' => [
        null,
        'Рейтинговое агентство НКР отзывает кредитный рейтинг АО «АВТОБАН-Финанс» и прогноз по нему без подтверждения в связи с расторжением договора по инициативе рейтингуемого лица.',
    ],
    'Инвест КЦ: прогноз во втором предложении, которое начинается с "Прогноз"' => [
        'stable',
        'Рейтинговое агентство НКР снизило кредитные рейтинги ООО «Инвест КЦ» и выпуска его биржевых облигаций серии 001P-01 (RU000A10BQV8) с A.ru до BBB+.ru. Прогноз по кредитному рейтингу ООО «Инвест КЦ» остался стабильным.',
    ],
    'Изменение на статус пересмотра → under_review_negative' => [
        'under_review_negative',
        'Рейтинговое агентство НКР изменило прогноз по кредитному рейтингу ООО «Х» на «рейтинг на пересмотре с возможностью понижения».',
    ],
    'Выход из пересмотра: "с «рейтинг на пересмотре — неопределённый прогноз» на стабильный" → stable' => [
        'stable',
        'Рейтинговое агентство НКР подтвердило кредитный рейтинг АО «Х» на уровне A+.ru и изменило прогноз с «рейтинг на пересмотре — неопределённый прогноз» на стабильный.',
    ],
    'Сохранение статуса «рейтинг на пересмотре — неопределённый прогноз» → under_review_indefinite' => [
        'under_review_indefinite',
        'Рейтинговое агентство НКР сохранило прогноз по кредитному рейтингу АО «Х» в статусе «рейтинг на пересмотре — неопределённый прогноз».',
    ],
    'Две компании с разными прогнозами — не угадываем, NULL' => [
        null,
        'Рейтинговое агентство НКР подтвердило кредитный рейтинг АО «Х» на уровне A.ru со стабильным прогнозом и кредитный рейтинг ООО «Y» на уровне BBB.ru с позитивным прогнозом.',
    ],
    'Название с точкой (ВЭБ.РФ) не рвёт разбор изменения' => [
        'positive',
        'Рейтинговое агентство НКР подтвердило кредитный рейтинг ВЭБ.РФ на уровне AAA.ru и изменило прогноз с негативного на позитивный.',
    ],
];
foreach ($cases as $label => [$expected, $text]) {
    check($label, $expected, NkrTitleParser::extractOutlook($text));
}

echo "\n--- extractOutlook(): заголовки (результат как раньше, кроме \"с X на Y\") ---\n";

$titles = [
    'ГИДРОМАШСЕРВИС: в заголовке прогноза нет → NULL (берём из текста)' => [null, 'НКР снизило кредитные рейтинги АО «ГИДРОМАШСЕРВИС» и его облигаций с A+.ru до A.ru'],
    'Технотранс: "прогноз — стабильный"' => ['stable', 'НКР подтвердило кредитный рейтинг ООО «Технотранс» на уровне BBB.ru, прогноз — стабильный'],
    'СДМ-Банк: "прогноз изменён на неопределённый" → indefinite' => ['indefinite', 'НКР подтвердило кредитный рейтинг СДМ-Банка на уровне A-.ru, прогноз изменён на неопределённый'],
    'ИСК «АВТОБАН»: "изменён на позитивный"' => ['positive', 'НКР подтвердило кредитные рейтинги АО «ИСК „АВТОБАН“» и облигаций АО «АВТОБАН-Финанс» на уровне A+.ru, прогноз по кредитному рейтингу АО «ИСК „АВТОБАН“» изменён на позитивный'],
    'Рейтинг выпуска облигаций → NULL' => [null, 'НКР присвоило выпуску биржевых облигаций ПАО «Ростелеком» серии 001Р-28R кредитный рейтинг AAA.ru'],
    '"с негативного на стабильный" → stable (раньше читалось как negative)' => ['stable', 'НКР подтвердило кредитный рейтинг ПАО «Х» на уровне A.ru, прогноз изменён с негативного на стабильный'],
];
foreach ($titles as $label => [$expected, $title]) {
    check($label, $expected, NkrTitleParser::extractOutlook($title));
}

echo "\n--- «Неопределённый» (НКР) и «развивающийся» — разные коды (миграция 026) ---\n";

check('Колонка Outlook выгрузки НКР "неопределённый" → indefinite', 'indefinite', RatingsNormalizer::mapOutlook('неопределённый'));
check('НРА "Развивающийся" → developing', 'developing', RatingsNormalizer::mapOutlook('Развивающийся'));
check('Текст "изменён на развивающийся" (Эксперт РА) → developing', 'developing', RatingsNormalizer::mapOutlookFromProse('прогноз изменён на развивающийся'));
check('Заголовок НКР "…в статусе «рейтинг на пересмотре — неопределённый прогноз»" → under_review_indefinite', 'under_review_indefinite', NkrTitleParser::extractOutlook('НКР сохранило прогноз по кредитному рейтингу АО «Х» в статусе «рейтинг на пересмотре — неопределённый прогноз»'));
check('То же с коротким тире "–" → under_review_indefinite', 'under_review_indefinite', NkrTitleParser::extractOutlook('НКР изменило прогноз по кредитному рейтингу ООО «О’КЕЙ» на «рейтинг на пересмотре – неопределённый прогноз»'));
check('Колонка Outlook выгрузки НКР "рейтинг на пересмотре — неопределённый прогноз" → under_review_indefinite', 'under_review_indefinite', RatingsNormalizer::extractReviewStatusFromProse('рейтинг на пересмотре — неопределённый прогноз'));
check('"с возможностью понижения" по-прежнему → under_review_negative', 'under_review_negative', RatingsNormalizer::extractReviewStatusFromProse('рейтинг на пересмотре с возможностью понижения'));
check('Голый статус без направления (Эксперт РА/НРА-стиль) → under_review', 'under_review', RatingsNormalizer::extractReviewStatusFromProse('рейтинг под наблюдением'));
check('НРА: "неопределённый" + под наблюдением → under_review_indefinite (та же схема, что _developing)', 'under_review_indefinite', RatingsNormalizer::combineWithWatchStatus('indefinite', 'Под наблюдением'));

echo "\n--- extractLeadFromDetailHtml(): вводный абзац страницы ---\n";

$page = static fn (string $body): string => '<html><head><title>НКР</title><style>.a{color:red}</style></head><body>'
    . '<nav>Рейтинги Пресс-релизы Методологии</nav><h1>НКР снизило кредитные рейтинги АО &laquo;ГИДРОМАШСЕРВИС&raquo;</h1>'
    . '<div class="text">' . $body . '</div><footer>© НКР</footer></body></html>';

$hms = $page('<p>Рейтинговое агентство НКР снизило кредитный рейтинг АО &laquo;ГИДРОМАШСЕРВИС&raquo; (далее также &laquo;компания&raquo;) с&nbsp;A+.ru до A.ru и изменило прогноз по кредитному рейтингу со стабильного на негативный. Одновременно с этим кредитные рейтинги шести выпусков биржевых облигаций компании были снижены с A+.ru до A.ru.</p><h2>Резюме</h2><p>История: рейтинг A+.ru стабильный.</p>');
check(
    'ГИДРОМАШСЕРВИС: только первое предложение, сущности (&laquo;, &nbsp;) раскрыты',
    'Рейтинговое агентство НКР снизило кредитный рейтинг АО «ГИДРОМАШСЕРВИС» (далее также «компания») с A+.ru до A.ru и изменило прогноз по кредитному рейтингу со стабильного на негативный.',
    NkrTitleParser::extractLeadFromDetailHtml($hms)
);
check('ГИДРОМАШСЕРВИС: прогноз из абзаца — negative', 'negative', NkrTitleParser::extractOutlook((string) NkrTitleParser::extractLeadFromDetailHtml($hms)));

$avtoban = $page('<p>Рейтинговое агентство НКР отзывает кредитный рейтинг АО «АВТОБАН-Финанс» и прогноз по нему без подтверждения в связи с расторжением договора по инициативе рейтингуемого лица.</p><p>До момента отзыва действовал кредитный рейтинг АО «АВТОБАН-Финанс» на уровне A+.ru со стабильным прогнозом.</p>'
    . '<p>Регуляторное раскрытие. Идентификационный номер налогоплательщика (ИНН) рейтингуемого лица 7708813750.</p>');
$avtobanLead = NkrTitleParser::extractLeadFromDetailHtml($avtoban);
check('АВТОБАН-Финанс: "До момента отзыва… со стабильным прогнозом" в абзац не попадает', false, str_contains((string) $avtobanLead, 'До момента'));
check('АВТОБАН-Финанс: прогноз из абзаца — NULL', null, NkrTitleParser::extractOutlook((string) $avtobanLead));
check('ИНН по-прежнему находится на той же странице', '7708813750', NkrTitleParser::extractInnFromDetailHtml($avtoban));

$invest = $page('<p>Рейтинговое агентство НКР снизило кредитные рейтинги ООО «Инвест КЦ» и выпуска его биржевых облигаций серии 001P-01 (RU000A10BQV8) с A.ru до BBB+.ru. Прогноз по кредитному рейтингу ООО «Инвест КЦ» остался стабильным.</p><h2>Резюме</h2><p>Снижение рейтинга обусловлено…</p>');
check(
    'Инвест КЦ: второе предложение со слова "Прогноз" добавляется',
    true,
    str_ends_with((string) NkrTitleParser::extractLeadFromDetailHtml($invest), 'Прогноз по кредитному рейтингу ООО «Инвест КЦ» остался стабильным.')
);
check('Инвест КЦ: прогноз из абзаца — stable', 'stable', NkrTitleParser::extractOutlook((string) NkrTitleParser::extractLeadFromDetailHtml($invest)));
check('Страница без вводного абзаца — NULL', null, NkrTitleParser::extractLeadFromDetailHtml($page('<p>Технические работы.</p>')));

echo "\n--- NkrImporter: ссылка на пресс-релиз в предложении сопоставления ---\n";

check('absoluteUrl(): адрес без схемы (живой случай ВИС) → https://', 'https://ratings.ru/ratings/press-releases/VIS-RA-160726/', RatingsNormalizer::absoluteUrl('ratings.ru/ratings/press-releases/VIS-RA-160726/', 'ratings.ru'));
check('absoluteUrl(): полный адрес — как есть', 'https://ratings.ru/ratings/press-releases/X/', RatingsNormalizer::absoluteUrl('https://ratings.ru/ratings/press-releases/X/', 'ratings.ru'));
check('absoluteUrl(): путь от корня → сайт агентства', 'https://raexpert.ru/releases/2026/jul14e', RatingsNormalizer::absoluteUrl('/releases/2026/jul14e', 'raexpert.ru'));
check('absoluteUrl(): "//host/…" → https', 'https://www.acra-ratings.ru/press-releases/1/', RatingsNormalizer::absoluteUrl('//www.acra-ratings.ru/press-releases/1/', 'www.acra-ratings.ru'));
check('absoluteUrl(): не адрес — NULL', null, RatingsNormalizer::absoluteUrl('Пресс-релиз', 'ratings.ru'));

$describe = new ReflectionMethod(NkrImporter::class, 'describeRow');
$describe->setAccessible(true);
[$title, $url] = $describe->invoke(null, [
    'Issuer Name' => 'Группа «ВИС» (АО)', 'Outlook' => 'стабильный', 'Date' => '16.07.2026',
    'Press release' => 'ratings.ru/ratings/press-releases/VIS-RA-160726/',
], 'AA-.ru');
check('describeRow(): ссылка ведёт на пресс-релиз', 'https://ratings.ru/ratings/press-releases/VIS-RA-160726/', $url);
check('describeRow(): адрес не дублируется в заголовке', false, str_contains($title, 'press-releases'));

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
