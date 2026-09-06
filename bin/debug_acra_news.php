<?php

declare(strict_types=1);

/**
 * ШАБЛОН/РАЗВЕДКА, сентябрь 2026 — разведка + сухой прогон (без БД,
 * без записи куда-либо) парсинга новостей АКРА, по образцу
 * bin/debug_rating_page.php и bin/simulate_nkr_news.php.
 *
 * Зачем отдельный скрипт, а не сразу импортёр: ключевой открытый вопрос —
 * отдаёт ли acra-ratings.ru реальный контент (список пресс-релизов,
 * текст детальной страницы) на ОБЫЧНЫЙ HTTP-запрос (как делают все
 * остальные импортёры этого проекта — RatingsHttp::get(), без cookie-
 * сессии/JS/капчи), или это по-прежнему SPA-заглушка, требующая браузера
 * (см. docs/STAGE3_RATINGS.md, разделы «АКРА — закрыта»/«АКРА —
 * переоткрыта» — там curl с моей стороны получал 0 таблиц и общие
 * Bitrix-классы без данных). Разбор ЗАГОЛОВКОВ (AcraNewsTitleParser)
 * уже проверен на 10 реальных примерах, присланных пользователем как
 * сохранённые вживую страницы браузера — но это не то же самое, что
 * ответ на автоматический запрос без браузера/сессии/капчи.
 *
 * Скрипт делает МИНИМУМ запросов (1 список + 1 деталь, с паузой между
 * ними — не имитация браузера и не попытка обойти защиту, а просто
 * вежливая пауза, как и у всех остальных источников этого проекта) и
 * печатает всё, что нужно для диагностики: HTTP-код, размер ответа,
 * найдены ли реальные карточки списка или SPA-заглушка (та же проверка,
 * что в debug_rating_page.php — тройка/topClasses), и, если данные
 * нашлись, — что распарсил AcraNewsTitleParser на них.
 *
 * НИЧЕГО не пишет в БД — только читает bin/bootstrap.php ради автозагрузки
 * классов (RatingsHttp/RatingsNormalizer/AcraNewsTitleParser/Logger), к
 * самой базе не обращается вообще. Можно запускать даже без настроенного
 * .env — Env::load() внутри bootstrap.php молча ничего не делает, если
 * файла нет.
 *
 * Запуск:
 *   php bin/debug_acra_news.php
 *   php bin/debug_acra_news.php https://www.acra-ratings.ru/press-releases/7241/   (только одна конкретная деталь)
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Ratings\AcraNewsTitleParser;
use BondKeeper\Ratings\RatingsHttp;
use BondKeeper\Ratings\RatingsNormalizer;

const LIST_URL = 'https://www.acra-ratings.ru/press-releases/';
const DELAY_SECONDS = 2;

$explicitDetailUrl = $argv[1] ?? null;

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

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $titleNode = $xpath->query('//h1')->item(0) ?? $xpath->query('//title')->item(0);
    $title = $titleNode !== null ? trim(preg_replace('/\s+/u', ' ', $titleNode->textContent) ?? '') : null;
    echo 'Заголовок (h1 или <title>): ' . ($title ?? '(не найден)') . "\n";

    $timeNodes = $xpath->query('//time[contains(@class,"publication_date")]');
    $rawDate = $timeNodes->length > 0 ? $timeNodes->item(0)->getAttribute('datetime') : null;
    echo 'Дата публикации (time.publication_date[datetime]): ' . ($rawDate ?? '(не найдена)') . "\n";
    if ($rawDate !== null) {
        $parsedDate = RatingsNormalizer::parseDate(substr($rawDate, 0, 10));
        echo '  => разобрано как: ' . ($parsedDate ?? '(не удалось разобрать формат даты)') . "\n";
    }

    $inn = extractInnFromDisclosureTable($xpath);
    echo 'ИНН (таблица "Регуляторное раскрытие"): ' . ($inn ?? '(не найден)') . "\n";

    if ($title !== null) {
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
