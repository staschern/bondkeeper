<?php

declare(strict_types=1);

/**
 * Офлайн-проверка правок по сверке АКРА от 03.10.2026:
 *   1. AcraNewsTitleParser::extractGrade() — рейтинг только с "(RU)" (или
 *      сразу после "на уровне"/"до уровня"), а не первый похожий токен:
 *      предлог "С" давал рейтинг "C", класс облигаций «А1» — рейтинг "A";
 *   2. RatingsNormalizer::isNonStandardRating() — новости про ипотечные
 *      ценные бумаги не пишутся вообще (решение пользователя);
 *   3. AcraNewsImporter::collectCandidates() — лента листается дальше
 *      первой страницы, пока есть ещё не виденные карточки (пропущенное
 *      понижение ООО «ПКФ» до D(RU) от 23.09.2026);
 *   4. AcraNewsRecheck — разовое исправление уже записанных новостей.
 * Заголовки — реальные, с acra-ratings.ru/press-releases/ (сентябрь–
 * октябрь 2026).
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_acra_news.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Events\EventPublisher;
use BondKeeper\Ratings\AcraNewsImporter;
use BondKeeper\Ratings\AcraNewsRecheck;
use BondKeeper\Ratings\AcraNewsTitleParser;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\NameMatchResolver;
use BondKeeper\Ratings\NameMatchReviews;
use BondKeeper\Ratings\RatingActionsWriter;
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

echo "--- extractGrade(): рейтинг из заголовка ---\n";

$gradeCases = [
    ['предлог «С» перед настоящим рейтингом (СФО ВСМ Инвест, было "C")', 'AAA(RU)', 'АКРА ПОДТВЕРДИЛО КРЕДИТНЫЙ РЕЙТИНГ ВЫПУСКА ОБЛИГАЦИЙ С ЗАЛОГОВЫМ ОБЕСПЕЧЕНИЕМ ООО «СФО ВСМ ИНВЕСТ — ПЕРВЫЙ» (RU000A109MB8) НА УРОВНЕ ААA(RU)'],
    ['классы «А1» и «А2», рейтинга в заголовке нет (СФО ТБ-11, было "A")', null, 'АКРА ПРИСВОИЛО КРЕДИТНЫЙ РЕЙТИНГ ОБЛИГАЦИЯМ КЛАССОВ «А1» (RU000A10FZF3) И «А2» (RU000A10FZG1), ПЛАНИРУЕМЫМ К ВЫПУСКУ ООО «СФО ТБ-11»'],
    ['международная и национальная шкалы — берём национальную (было "BB-")', 'BBB(RU)', 'АКРА ПОДТВЕРДИЛО КРЕДИТНЫЕ РЕЙТИНГИ ОАО «БЕЛИНВЕСТБАНК» ПО МЕЖДУНАРОДНОЙ ШКАЛЕ НА УРОВНЕ BB-, ПО НАЦИОНАЛЬНОЙ ШКАЛЕ — НА УРОВНЕ BBB(RU)'],
    ['только международная шкала — рейтинг после «на уровне»', 'BB-', 'АКРА ПОДТВЕРДИЛО КРЕДИТНЫЙ РЕЙТИНГ ТОО «Х» ПО МЕЖДУНАРОДНОЙ ШКАЛЕ НА УРОВНЕ BB-, ПРОГНОЗ «СТАБИЛЬНЫЙ»'],
    ['после «до уровня»', 'D(RU)', 'АКРА ПОНИЗИЛО КРЕДИТНЫЙ РЕЙТИНГ ООО «ПКФ» ДО УРОВНЯ D(RU), ОТОЗВАЛО РАНЕЕ ДЕЙСТВОВАВШИЙ ПРОГНОЗ'],
    ['сразу после слов «кредитный рейтинг»', 'A(RU)', 'АКРА ПРИСВОИЛО ВЫПУСКУ ОБЛИГАЦИЙ ПАО «ГРУППА ЛСР» СЕРИИ 002P-02 (RU000A10G924) КРЕДИТНЫЙ РЕЙТИНГ A(RU)'],
    ['после названия компании (статус наблюдения)', 'A+(RU)', 'АКРА ПРИСВОИЛО СТАТУС «ПОД НАБЛЮДЕНИЕМ» КРЕДИТНОМУ РЕЙТИНГУ АО «СЕЛЕКТЕЛ» A+(RU), ПРОГНОЗ «СТАБИЛЬНЫЙ»'],
    ['кириллица вперемешку с латиницей', 'BBB+(RU)', 'АКРА ПОДТВЕРДИЛО КРЕДИТНЫЙ РЕЙТИНГ ООО «СТРОЙДОРСЕРВИС» НА УРОВНЕ BBВ+(RU), ПРОГНОЗ «СТАБИЛЬНЫЙ»'],
    ['кириллическая «е» ожидаемого рейтинга', 'eAAA(RU)', 'АКРА ПРИСВОИЛО ВЫПУСКУ ОБЛИГАЦИЙ ПАО «ФОСАГРО» СЕРИИ БО-03-01 ОЖИДАЕМЫЙ КРЕДИТНЫЙ РЕЙТИНГ еAAA(RU)'],
    ['два рейтинга с (RU) — первый (компании)', 'AA(RU)', 'АКРА ПОДТВЕРДИЛО КРЕДИТНЫЙ РЕЙТИНГ АО «АВТО ФИНАНС БАНК» НА УРОВНЕ АA(RU), ПРОГНОЗ «ПОЗИТИВНЫЙ», И ЕГО ОБЛИГАЦИЙ — НА УРОВНЕ АA(RU)'],
    ['город без кавычек', 'A(RU)', 'АКРА ПОДТВЕРДИЛО КРЕДИТНЫЙ РЕЙТИНГ НИЖНЕГО НОВГОРОДА НА УРОВНЕ А(RU), ПРОГНОЗ «СТАБИЛЬНЫЙ»'],
    ['аббревиатуры в названии (АО, ПАО, КБ, СФО) — не рейтинг', null, 'АКРА ПРИСВОИЛО КРЕДИТНЫЙ РЕЙТИНГ ОБЛИГАЦИЯМ АО КБ «В» И ПАО «СФО А»'],
];
foreach ($gradeCases as [$label, $expected, $title]) {
    check($label, $expected, AcraNewsTitleParser::extractGrade($title, (string) AcraNewsTitleParser::matchVerb($title)));
}
check('отзыв — литерал «отозван»', 'отозван', AcraNewsTitleParser::extractGrade('АКРА ОТОЗВАЛО КРЕДИТНЫЙ РЕЙТИНГ ООО «ПИК-ИНВЕСТПРОЕКТ» В СВЯЗИ С ОКОНЧАНИЕМ СРОКА', 'отозвало'));

echo "\n--- isNonStandardRating(): ипотечные ценные бумаги исключаются целиком ---\n";

$mortgage = 'АКРА ОТОЗВАЛО КРЕДИТНЫЙ РЕЙТИНГ ВЫПУСКА ИПОТЕЧНЫХ ЦЕННЫХ БУМАГ ООО «ДОМ.РФ ИПОТЕЧНЫЙ АГЕНТ» (RU000A0JX3M0) В СВЯЗИ С ПОГАШЕНИЕМ ВЫПУСКА';
check('отзыв рейтинга выпуска ипотечных ценных бумаг', true, RatingsNormalizer::isNonStandardRating($mortgage));
check('присвоение рейтинга ипотечным ценным бумагам', true, RatingsNormalizer::isNonStandardRating('АКРА ПРИСВОИЛО ИПОТЕЧНЫМ ЦЕННЫМ БУМАГАМ ООО «Х» КРЕДИТНЫЙ РЕЙТИНГ AAA(RU)'));
check('название компании «Ипотечный агент» само по себе — не исключается', false, RatingsNormalizer::isNonStandardRating('АКРА ПОДТВЕРДИЛО КРЕДИТНЫЙ РЕЙТИНГ ООО «ДОМ.РФ ИПОТЕЧНЫЙ АГЕНТ» НА УРОВНЕ AAA(RU)'));
check('обычные облигации — не исключаются', false, RatingsNormalizer::isNonStandardRating('АКРА ПРИСВОИЛО ВЫПУСКУ ОБЛИГАЦИЙ ООО «СОПФ ДОМ.РФ» (RU000A10G643) КРЕДИТНЫЙ РЕЙТИНГ AAA(RU)'));
check('шкала .sf — как раньше', true, RatingsNormalizer::isNonStandardRating('«Эксперт РА» присвоил рейтинг на уровне ruAAA.sf'));
check('для сверки: значение из такой новости — «новость теперь не пишется»', 'skipped_non_standard', RatingsNormalizer::bondNewsSkipStatusForStoredTitle($mortgage));

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, full_name TEXT, short_name TEXT, inn TEXT)');
$db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE rating_news_log (agency TEXT, source_url TEXT, action_date TEXT, status TEXT, PRIMARY KEY (agency, source_url))');
$db->exec('CREATE TABLE rating_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, issuer_id INTEGER, agency TEXT, action_date TEXT, rating_to TEXT, outlook_to TEXT, source_title TEXT, source_url TEXT, event_id INTEGER)');
$db->exec('CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, outlook TEXT, last_action_date TEXT, matched_by_root_name INTEGER DEFAULT 0, source TEXT, PRIMARY KEY (issuer_id, agency))');

echo "\n--- collectCandidates(): лента листается, пока есть не виденные карточки ---\n";

/** Страница ленты в разметке сайта: карточки [номер пресс-релиза, дата "2 окт 2026"]. */
$pageHtml = static function (array $cards): string {
    $html = '<html><body>';
    foreach ($cards as [$id, $date]) {
        $html .= '<div class="documents-row search-table-row"><a class="item__title" href="/press-releases/' . $id . '/">АКРА ПОДТВЕРДИЛО КРЕДИТНЫЙ РЕЙТИНГ ООО «Х' . $id . '» НА УРОВНЕ A(RU)</a>'
            . '<div class="documents-row__item" data-type="date">' . $date . '</div></div>';
    }

    return $html . '</body></html>';
};
$pages = [
    1 => [[7363, '2 окт 2026'], [7360, '2 окт 2026'], [7355, '1 окт 2026']],
    2 => [[7354, '30 сен 2026'], [7355, '1 окт 2026'], [7344, '28 сен 2026']], // 7355 повторяется: лента сдвинулась
    3 => [[7337, '25 сен 2026'], [7329, '23 сен 2026'], [7322, '22 сен 2026']],
    4 => [[7310, '18 сен 2026'], [7304, '17 сен 2026']],
    5 => [[7290, '15 сен 2026']],
];
$known = static function (array $ids) use ($db): void {
    $db->exec('DELETE FROM rating_news_log');
    foreach ($ids as $id) {
        $db->prepare("INSERT INTO rating_news_log VALUES ('acra', :url, '2026-01-01', 'skipped_unmatched')")->execute(['url' => "https://www.acra-ratings.ru/press-releases/{$id}/"]);
    }
};

$matcher = new IssuerMatcher($db);
$importer = new AcraNewsImporter($db, $matcher, new RatingActionsWriter($db, new EventPublisher($db)), new NameMatchResolver($matcher, new NameMatchReviews($db)), 0);
$collect = new ReflectionMethod(AcraNewsImporter::class, 'collectCandidates');
$run = static function (?string $cutoff, bool $full = false, ?int $failingPage = null) use ($collect, $importer, $pages, $pageHtml): array {
    $fetched = [];
    $rows = $collect->invoke($importer, static function (int $page) use (&$fetched, $pages, $pageHtml, $failingPage): string {
        $fetched[] = $page;
        if ($page === $failingPage) {
            throw new RuntimeException('сайт не ответил');
        }

        return $pageHtml($pages[$page] ?? []);
    }, $cutoff, $full);

    return [$fetched, array_map(static fn (array $r): int => (int) basename($r['url']), $rows)];
};

$known([7363, 7360, 7355]);
check('обычный прогон: на первой странице всё уже видено — один запрос, карточки первой страницы', [[1], [7355, 7360, 7363]], $run('2026-09-18'));

$known([7355, 7354, 7344]);
check('новые карточки на первой странице — читается вторая, на ней всё видено — стоп; повтор 7355 один раз', [[1, 2], [7344, 7354, 7355, 7360, 7363]], $run('2026-09-18'));

$known([7310, 7304]);
[$fetched, $ids] = $run('2026-09-18');
check('после простоя: читаются страницы до первой полностью виденной', [1, 2, 3, 4], $fetched);
check('пропущенная новость от 23.09 (7329, ООО «ПКФ») попала в обработку', true, in_array(7329, $ids, true));
check('порядок — от старых к новым; карточка 17.09 вне окна отброшена', [7310, 7322, 7329, 7337, 7344, 7354, 7355, 7360, 7363], $ids);

$known([]);
check('окно: страница без карточек в окне останавливает листание', [[1, 2, 3], [7329, 7337, 7344, 7354, 7355, 7360, 7363]], $run('2026-09-23'));
check('страниц не больше MAX_PAGES, пустая страница — стоп', [1, 2, 3, 4, 5, 6], $run(null)[0]);
check('вторая страница не открылась — обрабатывается первая', [[1, 2], [7355, 7360, 7363]], $run('2026-09-18', false, 2));
$threw = false;
try {
    $run('2026-09-18', false, 1);
} catch (RuntimeException) {
    $threw = true;
}
check('первая страница не открылась — ошибка прогона, как раньше', true, $threw);
$known([7363, 7360, 7355, 7354, 7344]);
check('--full: листает дальше, даже если всё видено', [1, 2, 3, 4, 5, 6], $run(null, true)[0]);

echo "\n--- AcraNewsRecheck: разовое исправление записанных новостей ---\n";

foreach ([1988 => 'СФО ВСМ Инвест - Первый', 187535 => 'СФО ТБ-11', 93 => 'ДОМ.РФ Ипотечный агент', 2657 => 'СФО Столичные дороги', 70 => 'ТБанк', 1440 => 'Селектел', 500 => 'СФО Пример'] as $id => $name) {
    $db->prepare('INSERT INTO issuers (id, short_name) VALUES (:id, :name)')->execute(['id' => $id, 'name' => $name]);
}
$action = static function (int $issuerId, string $date, string $rating, ?string $outlook, string $title, ?int $eventId = null) use ($db): void {
    if ($eventId !== null) {
        $db->prepare('INSERT INTO events (id) VALUES (:id)')->execute(['id' => $eventId]);
    }
    $db->prepare("INSERT INTO rating_actions (issuer_id, agency, action_date, rating_to, outlook_to, source_title, source_url, event_id) VALUES (:i, 'acra', :d, :r, :o, :t, :u, :e)")
        ->execute(['i' => $issuerId, 'd' => $date, 'r' => $rating, 'o' => $outlook, 't' => $title, 'u' => "https://www.acra-ratings.ru/press-releases/{$issuerId}{$date}/", 'e' => $eventId]);
};
$current = static fn (int $id, string $rating, ?string $outlook, string $date, ?string $source) => $db->prepare(
    "INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date, source) VALUES (:id, 'acra', :r, :o, :d, :s)"
)->execute(['id' => $id, 'r' => $rating, 'o' => $outlook, 'd' => $date, 's' => $source]);

$action(1988, '2026-09-23', 'C', null, $gradeCases[0][2], 11);
$current(1988, 'C', null, '2026-09-23', 'action');
$action(187535, '2026-09-28', 'A', null, $gradeCases[1][2], 12);
$current(187535, 'A', null, '2026-09-28', 'action');
$action(93, '2026-09-30', 'отозван', null, $mortgage, 13);
$current(93, 'отозван', null, '2026-09-30', 'action');
// Неверный рейтинг в истории, но текущий уже из перезаписи снимком — текущий не трогаем.
$action(2657, '2026-10-01', 'C', null, 'АКРА ПОДТВЕРДИЛО КРЕДИТНЫЙ РЕЙТИНГ ВЫПУСКА ОБЛИГАЦИЙ С ЗАЛОГОВЫМ ОБЕСПЕЧЕНИЕМ ООО «СФО СТОЛИЧНЫЕ ДОРОГИ МАГИСТРАЛЬНАЯ ИНВЕСТ» (RU000A10CBY2) НА УРОВНЕ ААA(RU)');
$current(2657, 'AAA(RU)', 'stable', '2026-10-02', 'snapshot');
// Ожидаемый рейтинг субординированных облигаций (до правил 28–29.09) — историю не трогаем (решение по ТБанку).
$action(70, '2026-09-04', 'eA(RU)', null, 'АКРА ПРИСВОИЛО ВЫПУСКУ СУБОРДИНИРОВАННЫХ ОБЛИГАЦИЙ АО «ТБАНК» СЕРИИ 001SUB-T2-01 ОЖИДАЕМЫЙ КРЕДИТНЫЙ РЕЙТИНГ еA(RU)');
$current(70, 'eA(RU)', null, '2026-09-04', 'action');
// Правильно разобранная новость — не в плане.
$action(1440, '2026-09-29', 'A+(RU)', 'under_review_stable', $gradeCases[6][2]);
$current(1440, 'A+(RU)', 'under_review_stable', '2026-09-29', 'action');
// Удаляемая новость + более ранняя новость с неверным рейтингом: замена — уже исправленный рейтинг.
$action(500, '2026-09-10', 'C', null, 'АКРА ПОДТВЕРДИЛО КРЕДИТНЫЙ РЕЙТИНГ ВЫПУСКА ОБЛИГАЦИЙ С ЗАЛОГОВЫМ ОБЕСПЕЧЕНИЕМ ООО «СФО ПРИМЕР» НА УРОВНЕ AA(RU)');
$action(500, '2026-09-20', 'A', null, 'АКРА ПРИСВОИЛО КРЕДИТНЫЙ РЕЙТИНГ ОБЛИГАЦИЯМ КЛАССА «А1», ПЛАНИРУЕМЫМ К ВЫПУСКУ ООО «СФО ПРИМЕР»');
$current(500, 'A', null, '2026-09-20', 'action');
// Строка не с сайта АКРА (другая грамматика) — не трогаем.
$db->exec("INSERT INTO rating_actions (issuer_id, agency, action_date, rating_to, source_title) VALUES (1440, 'acra', '2026-08-01', 'A+(RU)', 'Селектел — рейтинг подтверждён')");

$recheck = new AcraNewsRecheck($db);
$plan = $recheck->plan();
$byIssuer = [];
foreach ($plan as $item) {
    $byIssuer[$item['issuer_id']][] = $item;
}
check('в плане: 1988, 187535, 93, 2657 и две строки 500; ТБанк, Селектел и строка не с сайта — нет', [93, 500, 1988, 2657, 187535], (static function (array $k): array { sort($k); return $k; })(array_keys($byIssuer)));
check('1988: исправить C → AAA(RU), текущий из этой новости', ['regrade', 'AAA(RU)', true], [$byIssuer[1988][0]['action'], $byIssuer[1988][0]['grade'], $byIssuer[1988][0]['active']]);
check('187535: удалить (рейтинга в заголовке нет)', ['drop', true, null], [$byIssuer[187535][0]['action'], $byIssuer[187535][0]['active'], $byIssuer[187535][0]['replacement']]);
check('93: удалить (ипотечные ценные бумаги)', 'drop', $byIssuer[93][0]['action']);
check('2657: исправить в истории, текущий (из снимка) не трогать', ['regrade', false], [$byIssuer[2657][0]['action'], $byIssuer[2657][0]['active']]);
check('500: замена для удаляемой новости — исправленный рейтинг более ранней', ['AA(RU)', '2026-09-10'], [$byIssuer[500][1]['replacement']['rating'] ?? null, $byIssuer[500][1]['replacement']['action_date'] ?? null]);
check('plan() ничего не пишет', 'C', $db->query("SELECT rating FROM current_ratings WHERE issuer_id = 1988")->fetchColumn());

$stats = $recheck->apply($plan);
check('применено: удалено 3, исправлено 3, событий удалено 3, ошибок нет', [3, 3, 3, []], [$stats['dropped'], $stats['regraded'], $stats['deleted_events'], $stats['errors']]);
check('текущий рейтинг: исправлен у 2 (1988, 500), удалён у 2 (187535, 93), не тронут у 2 (2657 и ранняя строка 500)', [2, 2, 2], [$stats['current_updated'], $stats['current_removed'], $stats['current_untouched']]);
$cur = static fn (int $id): array|false => $db->query("SELECT rating, outlook, last_action_date, source FROM current_ratings WHERE issuer_id = {$id} AND agency = 'acra'")->fetch();
check('1988: AAA(RU), дата та же', ['AAA(RU)', '2026-09-23'], [$cur(1988)['rating'], $cur(1988)['last_action_date']]);
check('187535 и 93: строки текущего рейтинга удалены', [false, false], [$cur(187535), $cur(93)]);
check('2657: значение из снимка не затёрто', ['rating' => 'AAA(RU)', 'outlook' => 'stable', 'last_action_date' => '2026-10-02', 'source' => 'snapshot'], $cur(2657));
check('500: AA(RU) от 10.09', ['AA(RU)', '2026-09-10'], [$cur(500)['rating'], $cur(500)['last_action_date']]);
check('ТБанк и Селектел не тронуты', ['eA(RU)', 'A+(RU)'], [$cur(70)['rating'], $cur(1440)['rating']]);
check('в истории рейтинг исправлен', ['AAA(RU)', 'AAA(RU)', 'AA(RU)'], $db->query("SELECT rating_to FROM rating_actions WHERE issuer_id IN (1988, 2657, 500) ORDER BY issuer_id DESC")->fetchAll(PDO::FETCH_COLUMN));
check('события неверных новостей удалены, ссылка на событие снята', [0, null], [(int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn(), $db->query('SELECT event_id FROM rating_actions WHERE issuer_id = 1988')->fetchColumn()]);
check('повторный запуск — план пуст', [], $recheck->plan());

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
