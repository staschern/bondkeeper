<?php

declare(strict_types=1);

/**
 * ШАБЛОН/РАЗВЕДКА, сентябрь 2026 — разведка + сухой прогон (без БД,
 * без записи куда-либо) парсинга новостей АКРА, по образцу
 * bin/debug_rating_page.php и bin/simulate_nkr_news.php.
 *
 * Первый прогон на сервере пользователя (сентябрь 2026) уже подтвердил:
 * список пресс-релизов И одна детальная страница отдают РЕАЛЬНЫЙ
 * контент на обычный HTTP-запрос (RatingsHttp::get(), без cookie-
 * сессии/JS/капчи) — тот же вопрос, что был открытым для current_ratings
 * (см. docs/STAGE3_RATINGS.md, разделы «АКРА — закрыта»/«АКРА —
 * переоткрыта», там curl получал 0 таблиц и SPA-заглушку), для этого
 * раздела сайта, похоже, не встаёт так же остро. Разбор ЗАГОЛОВКОВ
 * (AcraNewsTitleParser) подтверждён на 10 реальных примерах архива +
 * 2 живых детальных страницах.
 *
 * Оставшийся открытый вопрос — НЕ "отдаёт ли сайт данные вообще" (уже
 * подтверждено), а "не заблокирует ли сайт после НЕСКОЛЬКИХ запросов
 * подряд" (WAF документирован как блокирующий именно ПОСЛЕ 2-3 попыток
 * для других разделов сайта — 1-2 запроса это ещё не проверяет). Полный
 * AcraNewsImporter на каждый прогон будет делать 1 запрос к списку + до
 * 10 запросов к деталям (по числу новых карточек в окне) — это и есть
 * $argv[1] === '--all-details': прогоняет ВСЕ карточки списка с паузой
 * между каждой, а не только первую, воспроизводя более тяжёлый реальный
 * сценарий одним запуском.
 *
 * НИЧЕГО не пишет в БД — только читает bin/bootstrap.php ради автозагрузки
 * классов (RatingsHttp/RatingsNormalizer/AcraNewsTitleParser/Logger), к
 * самой базе не обращается вообще. Можно запускать даже без настроенного
 * .env — Env::load() внутри bootstrap.php молча ничего не делает, если
 * файла нет.
 *
 * Запуск:
 *   php bin/debug_acra_news.php                     (список + первая деталь, подробно)
 *   php bin/debug_acra_news.php URL                  (список + конкретная деталь, подробно)
 *   php bin/debug_acra_news.php --all-details        (список + ВСЕ детали из списка, кратко на каждую)
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Ratings\AcraNewsTitleParser;
use BondKeeper\Ratings\RatingsHttp;
use BondKeeper\Ratings\RatingsNormalizer;

const BASE_URL = 'https://www.acra-ratings.ru';
const LIST_URL = BASE_URL . '/press-releases/';
const DELAY_SECONDS = 2;

$allDetails = in_array('--all-details', $argv, true);
$explicitDetailUrl = ($argv[1] ?? null) !== '--all-details' ? ($argv[1] ?? null) : null;

echo "========== Список: " . LIST_URL . " ==========\n";
$listCandidates = [];
try {
    $html = RatingsHttp::get(LIST_URL, 30);
    echo 'HTTP OK, размер ответа: ' . strlen($html) . " байт\n";
    $listCandidates = parseListPage($html);
    echo 'Найдено карточек пресс-релизов (.documents-row.search-table-row с a.item__title): ' . count($listCandidates) . "\n";

    if ($listCandidates === []) {
        echo "\n--- реальных карточек НЕ найдено — вероятно, SPA-заглушка (данные подгружаются JS-ом). ---\n";
        echo "--- Диагностика на всякий случай (как в debug_rating_page.php): ---\n";
        printSpaDiagnostics($html);
    } else {
        foreach (array_slice($listCandidates, 0, 15) as $c) {
            echo "  [{$c['date']}] {$c['title']}\n    => {$c['url']}\n";
        }
    }
} catch (\Throwable $e) {
    echo 'ОШИБКА при получении списка: ' . $e->getMessage() . "\n";
}

if ($allDetails) {
    if ($listCandidates === []) {
        echo "\nСписок пуст — нечего прогонять через --all-details.\n";
        exit(0);
    }

    echo "\n========== --all-details: " . count($listCandidates) . " детальных страниц подряд (пауза " . DELAY_SECONDS . " сек между запросами) ==========\n";
    $okCount = 0;
    $failCount = 0;
    foreach ($listCandidates as $i => $c) {
        sleep(DELAY_SECONDS);
        $n = $i + 1;
        try {
            $html = RatingsHttp::get($c['url'], 30);
            $parsed = parseDetailPage($html);
            $verb = $parsed['title'] !== null ? AcraNewsTitleParser::matchVerb($parsed['title']) : null;
            $grade = $verb !== null ? AcraNewsTitleParser::extractGrade($parsed['title'], $verb) : null;
            $outlook = $parsed['title'] !== null ? AcraNewsTitleParser::extractOutlook($parsed['title']) : null;
            echo sprintf(
                "  #%d OK  размер=%d байт  дата=%s  ИНН=%s  глагол=%s  грейд=%s  прогноз=%s\n",
                $n,
                strlen($html),
                $parsed['date'] ?? '?',
                $parsed['inn'] ?? '(нет)',
                $verb ?? '?',
                $grade ?? '?',
                $outlook ?? '(нет)',
            );
            $okCount++;
        } catch (\Throwable $e) {
            echo "  #{$n} ОШИБКА: {$c['url']} — {$e->getMessage()}\n";
            $failCount++;
        }
    }
    echo "\nИтого: {$okCount} успешно, {$failCount} с ошибкой из " . count($listCandidates) . ".\n";
    echo "Если хотя бы одна из последних (более поздних по порядку запроса) страниц провалилась с признаками капчи/403/пустого ответа, а первые прошли нормально — это и есть WAF, включившийся после нескольких запросов подряд.\n";
    exit(0);
}

$detailUrl = $explicitDetailUrl ?? ($listCandidates[0]['url'] ?? null);
if ($detailUrl === null) {
    echo "\nНет URL детальной страницы для проверки (ни явно передан, ни найден в списке) — на этом всё.\n";
    exit(0);
}

echo "\n========== Деталь: {$detailUrl} ==========\n";
sleep(DELAY_SECONDS);
try {
    $html = RatingsHttp::get($detailUrl, 30);
    echo 'HTTP OK, размер ответа: ' . strlen($html) . " байт\n";

    $parsed = parseDetailPage($html);
    echo 'Заголовок (h1 или <title>): ' . ($parsed['title'] ?? '(не найден)') . "\n";
    echo 'Дата публикации (time.publication_date[datetime]): ' . ($parsed['rawDate'] ?? '(не найдена)') . "\n";
    if ($parsed['date'] !== null) {
        echo '  => разобрано как: ' . $parsed['date'] . "\n";
    }
    echo 'ИНН (таблица "Регуляторное раскрытие"): ' . ($parsed['inn'] ?? '(не найден)') . "\n";

    if ($parsed['title'] !== null) {
        $title = $parsed['title'];
        echo "\n--- разбор заголовка через AcraNewsTitleParser ---\n";
        $verb = AcraNewsTitleParser::matchVerb($title);
        echo '  глагол: ' . ($verb ?? '(не распознан — заголовок не начинается с "АКРА <глагол>")') . "\n";
        echo '  похоже на кредитное рейтинговое действие: ' . (AcraNewsTitleParser::isCreditRatingAction($title) ? 'да' : 'нет') . "\n";
        if ($verb !== null) {
            echo '  грейд: ' . (AcraNewsTitleParser::extractGrade($title, $verb) ?? '(не распознан)') . "\n";
        }
        echo '  прогноз: ' . (AcraNewsTitleParser::extractOutlook($title) ?? '(не распознан/не указан)') . "\n";
        echo '  статус "под наблюдением" в заголовке: ' . (AcraNewsTitleParser::detectWatchPhrase($title) ? 'да' : 'нет') . "\n";
        echo '  кандидаты на название эмитента (из кавычек): ' . implode(', ', AcraNewsTitleParser::extractQuotedNames($title)) . "\n";
    }
} catch (\Throwable $e) {
    echo 'ОШИБКА при получении детальной страницы: ' . $e->getMessage() . "\n";
}

/** @return array<int, array{title: string, url: string, date: string}> */
function parseListPage(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    // Точное совпадение ОБОИХ классов-токенов (не подстрокой) — тот же
    // приём, что уже нужен был для Эксперт РА (contains(@class, "X")
    // иначе ловит и "X__suffix").
    $rowClassExact = 'contains(concat(" ", normalize-space(@class), " "), " documents-row ")'
        . ' and contains(concat(" ", normalize-space(@class), " "), " search-table-row ")';

    $rows = [];
    foreach ($xpath->query("//div[{$rowClassExact}]") as $row) {
        $links = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " item__title ")]', $row);
        if ($links->length === 0) {
            continue;
        }
        $link = $links->item(0);
        $title = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');
        $href = $link->getAttribute('href');
        // Живой сайт отдаёт ОТНОСИТЕЛЬНЫЕ ссылки ("/press-releases/7242/")
        // — в отличие от сохранённых браузером страниц, где браузер сам
        // подставляет полный адрес при сохранении. Достраиваем домен,
        // если его нет (найдено вживую при первом прогоне на сервере
        // пользователя — без этой правки RatingsHttp::get() падал с
        // "No host part in the URL").
        if ($href !== '' && !str_starts_with($href, 'http')) {
            $href = BASE_URL . $href;
        }

        $dateNodes = $xpath->query('.//div[@data-type="date"]', $row);
        $dateRaw = $dateNodes->length > 0 ? trim(preg_replace('/\s+/u', ' ', $dateNodes->item(0)->textContent) ?? '') : '';
        $date = RatingsNormalizer::parseRussianMonthDate($dateRaw);

        if ($title === '' || $href === '' || $date === null) {
            continue;
        }

        $rows[] = ['title' => $title, 'url' => $href, 'date' => $date];
    }

    return $rows;
}

/** @return array{title: ?string, rawDate: ?string, date: ?string, inn: ?string} */
function parseDetailPage(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $titleNode = $xpath->query('//h1')->item(0) ?? $xpath->query('//title')->item(0);
    $title = $titleNode !== null ? trim(preg_replace('/\s+/u', ' ', $titleNode->textContent) ?? '') : null;

    $timeNodes = $xpath->query('//time[contains(@class,"publication_date")]');
    $rawDate = $timeNodes->length > 0 ? $timeNodes->item(0)->getAttribute('datetime') : null;
    $date = $rawDate !== null ? RatingsNormalizer::parseDate(substr($rawDate, 0, 10)) : null;

    $inn = extractInnFromDisclosureTable($xpath);

    return ['title' => $title, 'rawDate' => $rawDate, 'date' => $date, 'inn' => $inn];
}

function extractInnFromDisclosureTable(DOMXPath $xpath): ?string
{
    foreach ($xpath->query('//table//tr[td]') as $row) {
        $cells = $xpath->query('./td', $row);
        if ($cells->length < 2) {
            continue;
        }
        $label = trim(preg_replace('/\s+/u', ' ', $cells->item(0)->textContent) ?? '');
        if (stripos($label, 'Идентификационный номер налогоплательщика') === false) {
            continue;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $cells->item(1)->textContent) ?? '');
        if (preg_match('/(\d{10,12})/', $value, $m)) {
            return $m[1];
        }
    }

    return null;
}

function printSpaDiagnostics(string $body): void
{
    echo "\n  --- <script src=...> на странице ---\n";
    preg_match_all('/<script[^>]*\bsrc="([^"]*)"/', $body, $scriptMatches);
    foreach (array_slice($scriptMatches[1], 0, 15) as $src) {
        echo "    {$src}\n";
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $body);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    echo "\n  --- самые частые классы (кандидаты на контейнер строки списка) ---\n";
    $classCounts = [];
    foreach ($xpath->query('//*[@class]') as $el) {
        foreach (preg_split('/\s+/', trim($el->getAttribute('class'))) as $class) {
            if ($class === '') {
                continue;
            }
            $classCounts[$class] = ($classCounts[$class] ?? 0) + 1;
        }
    }
    arsort($classCounts);
    foreach (array_slice($classCounts, 0, 15, true) as $class => $count) {
        echo "    .{$class}: {$count}\n";
    }

    if (stripos($body, 'captcha') !== false || stripos($body, 'SmartCaptcha') !== false) {
        echo "\n  --- на странице упоминается капча (captcha/SmartCaptcha) ---\n";
    }
}
