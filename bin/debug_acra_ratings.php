<?php

declare(strict_types=1);

/**
 * ШАБЛОН/РАЗВЕДКА, сентябрь 2026 — разведка + сухой прогон (без БД, без
 * записи куда-либо) парсинга ПОЛНОЙ базы current_ratings АКРА напрямую
 * с сайта (https://www.acra-ratings.ru/ratings/issuers/), а не из
 * JSON-файла, который сейчас готовит вручную пользователь (--agency=acra).
 * По образцу bin/debug_acra_news.php — тот же принцип: сначала смотрим,
 * что реально приходит на автоматический запрос, потом пишем импортёр.
 *
 * Присланный пользователем архив (сохранённые вживую страницы браузера)
 * дал структуру:
 *   - список: `.emits-row.search-table-row` — имя+ссылка на карточку
 *     эмитента (`a[data-type="ratePerson"]`), грейд+прогноз
 *     (`[data-type="rate"] p`/`span`), сектор (`[data-type="sector"]`),
 *     дата+ссылка последнего пресс-релиза (`[data-type="pressRelease"] a`).
 *   - карточка эмитента: блоки `div.info.info-bottom` с парой
 *     `<small>Метка</small><p>Значение</p>` — среди них ИНН
 *     (`<small>ИНН</small><p>9714067913</p>`, БЕЗ двоеточия и без слова
 *     "Идентификационный..." — другая формулировка, чем на детальной
 *     странице ПРЕСС-РЕЛИЗА, см. AcraNewsImporter — не путать эти два
 *     разных источника ИНН одного и того же агентства).
 *
 * === Отличие от новостей: список ЗДЕСЬ постраничный ===
 *
 * У /press-releases/ (новости) вся видимая лента влезает на одну
 * страницу без пагинации. У /ratings/issuers/ — обычная пагинация (в
 * присланном примере: 5 страниц по 10 карточек = минимум 50 эмитентов,
 * реально может быть больше — "Показывать по 10/20/50/100" в интерфейсе
 * подсказывает, что бывает и больше 50). Первая страница отдаётся
 * обычным GET (как и у новостей) — но переключение страниц происходит
 * через AJAX, найдено в JS-файлах архива: POST на
 * `/local/ajax/get_rate_person.php` с телом вида
 * `{text: '', page: N, sort: '', count: 10}`. НИ ОДНОГО реального
 * примера ответа этого эндпоинта в архиве нет (файлы backend*.html там
 * оказались просто виджетом капчи Яндекса, не данными) — формат ответа
 * (JSON? HTML-фрагмент?) неизвестен, только предположение по коду.
 *
 * Этот скрипт проверяет ОБЕ вещи одним прогоном:
 *   1. Страница 1 обычным GET — так же, как уже подтверждено для новостей.
 *   2. Попытка получить страницу 2 через предполагаемый AJAX-запрos
 *      (POST, form-urlencoded, та же cookie-сессия, что и у страницы 1,
 *      заголовок X-Requested-With — тот же приём, что уже нужен был для
 *      Эксперт РА) — печатает СЫРОЙ ответ (обрезанный), чтобы понять,
 *      что это: JSON с данными, HTML-фрагмент, ошибка, капча или пустой
 *      ответ. Дальнейшая разработка (пагинация) зависит от того, что
 *      реально придёт — не гадаем заранее.
 *
 * НИЧЕГО не пишет в БД. Запуск:
 *   php bin/debug_acra_ratings.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Ratings\RatingsNormalizer;

const BASE_URL = 'https://www.acra-ratings.ru';
const LIST_URL = BASE_URL . '/ratings/issuers/';
const AJAX_URL = BASE_URL . '/local/ajax/get_rate_person.php';
const DELAY_SECONDS = 2;

echo "========== Страница 1 (обычный GET): " . LIST_URL . " ==========\n";
$cookieJar = tempnam(sys_get_temp_dir(), 'bondkeeper_acra_ratings_debug_');
$html = httpGet(LIST_URL, $cookieJar);
if ($html === null) {
    echo "ОШИБКА — ответа не получено, дальше проверять нечего.\n";
    exit(1);
}
echo 'HTTP OK, размер ответа: ' . strlen($html) . " байт\n";

$rows = parseListPage($html);
echo 'Найдено карточек эмитентов (.emits-row.search-table-row с data-type="ratePerson"): ' . count($rows) . "\n";
if ($rows === []) {
    echo "\n--- реальных карточек НЕ найдено — вероятно, снова SPA-заглушка. ---\n";
    printSpaDiagnostics($html);
} else {
    foreach ($rows as $r) {
        echo "  {$r['name']} — грейд={$r['grade']} прогноз={$r['outlook']} сектор={$r['sector']} дата={$r['pressReleaseDate']}\n";
        echo "    карточка: {$r['url']}\n";
    }
}

$pagesFound = countPaginationPages($html);
echo "\nСтраниц пагинации найдено в HTML (кнопки 1,2,3...): {$pagesFound}\n";

if ($rows !== []) {
    $detailUrl = $rows[0]['url'];
    echo "\n========== Карточка эмитента (первая из списка): {$detailUrl} ==========\n";
    sleep(DELAY_SECONDS);
    $detailHtml = httpGet($detailUrl, $cookieJar);
    if ($detailHtml === null) {
        echo "ОШИБКА при получении карточки.\n";
    } else {
        echo 'HTTP OK, размер ответа: ' . strlen($detailHtml) . " байт\n";
        $info = parseInfoBlocks($detailHtml);
        foreach ($info as $label => $value) {
            echo "  {$label}: {$value}\n";
        }
        echo '  ИНН, извлечённый явно: ' . ($info['ИНН'] ?? '(не найден)') . "\n";
    }
}

if ($pagesFound > 1) {
    echo "\n========== Попытка страницы 2 через AJAX (POST " . AJAX_URL . ') ==========' . "\n";
    sleep(DELAY_SECONDS);
    $ajaxBody = http_build_query(['text' => '', 'page' => 2, 'sort' => '', 'count' => 10]);
    $ajaxResponse = httpPost(AJAX_URL, $ajaxBody, $cookieJar);
    if ($ajaxResponse === null) {
        echo "ОШИБКА при AJAX-запросе (см. предупреждения выше, если были).\n";
    } else {
        echo 'HTTP-ответ получен, размер: ' . strlen($ajaxResponse) . " байт\n";
        echo "Первые 2000 символов сырого ответа (для диагностики формата):\n";
        echo substr($ajaxResponse, 0, 2000) . "\n";
        $decoded = json_decode($ajaxResponse, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "\n--- Это валидный JSON. Верхнеуровневые ключи: " . implode(', ', array_keys((array) $decoded)) . " ---\n";
        } else {
            echo "\n--- Это НЕ валидный JSON (json_decode: " . json_last_error_msg() . ") — либо HTML-фрагмент, либо ошибка/капча. ---\n";
        }
    }
}

@unlink($cookieJar);

/** @return array<int, array{name: string, url: string, grade: string, outlook: string, sector: string, pressReleaseDate: ?string, pressReleaseUrl: ?string}> */
function parseListPage(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $rowClassExact = 'contains(concat(" ", normalize-space(@class), " "), " emits-row ")'
        . ' and contains(concat(" ", normalize-space(@class), " "), " search-table-row ")';

    $rows = [];
    foreach ($xpath->query("//div[{$rowClassExact}]") as $row) {
        $nameLinks = $xpath->query('.//a[@data-type="ratePerson"]', $row);
        if ($nameLinks->length === 0) {
            continue;
        }
        $nameLink = $nameLinks->item(0);
        $name = trim(preg_replace('/\s+/u', ' ', $nameLink->textContent) ?? '');
        $url = $nameLink->getAttribute('href');

        $rateNodes = $xpath->query('.//div[@data-type="rate"]', $row);
        $grade = '';
        $outlook = '';
        if ($rateNodes->length > 0) {
            $pNodes = $xpath->query('.//p', $rateNodes->item(0));
            $spanNodes = $xpath->query('.//span', $rateNodes->item(0));
            $grade = $pNodes->length > 0 ? trim(preg_replace('/\s+/u', ' ', $pNodes->item(0)->textContent) ?? '') : '';
            $outlook = $spanNodes->length > 0 ? trim(preg_replace('/\s+/u', ' ', $spanNodes->item(0)->textContent) ?? '') : '';
        }

        $sectorNodes = $xpath->query('.//div[@data-type="sector"]', $row);
        $sector = $sectorNodes->length > 0 ? trim(preg_replace('/\s+/u', ' ', $sectorNodes->item(0)->textContent) ?? '') : '';

        $pressReleaseDate = null;
        $pressReleaseUrl = null;
        $prLinks = $xpath->query('.//div[@data-type="pressRelease"]//a', $row);
        if ($prLinks->length > 0) {
            $prLink = $prLinks->item(0);
            $pressReleaseUrl = $prLink->getAttribute('href');
            $pressReleaseDate = RatingsNormalizer::parseRussianMonthDate(trim(preg_replace('/\s+/u', ' ', $prLink->textContent) ?? ''));
        }

        if ($name === '' || $url === '') {
            continue;
        }

        $rows[] = [
            'name' => $name,
            'url' => $url,
            'grade' => $grade,
            'outlook' => $outlook,
            'sector' => $sector,
            'pressReleaseDate' => $pressReleaseDate,
            'pressReleaseUrl' => $pressReleaseUrl,
        ];
    }

    return $rows;
}

/** @return array<string, string> метка => значение, из блоков div.info.info-bottom */
function parseInfoBlocks(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $infoClassExact = 'contains(concat(" ", normalize-space(@class), " "), " info ")'
        . ' and contains(concat(" ", normalize-space(@class), " "), " info-bottom ")';

    $result = [];
    foreach ($xpath->query("//div[{$infoClassExact}]") as $block) {
        $labelNodes = $xpath->query('.//small', $block);
        $valueNodes = $xpath->query('.//p', $block);
        if ($labelNodes->length === 0 || $valueNodes->length === 0) {
            continue;
        }
        $label = trim(preg_replace('/\s+/u', ' ', $labelNodes->item(0)->textContent) ?? '');
        $value = trim(preg_replace('/\s+/u', ' ', $valueNodes->item(0)->textContent) ?? '');
        if ($label !== '') {
            $result[$label] = $value;
        }
    }

    return $result;
}

function countPaginationPages(string $html): int
{
    preg_match_all('/class="pagination__item num-page[^"]*"\s+data-num="(\d+)"/u', $html, $m);
    if ($m[1] === []) {
        return 1;
    }

    return (int) max(array_map('intval', $m[1]));
}

function httpGet(string $url, string $cookieJar): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BondKeeperBot/1.0; +data seeding, stage 3)',
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        echo "  cURL ошибка: {$error}\n";
        return null;
    }
    if ($httpCode !== 200) {
        echo "  HTTP {$httpCode}\n";
        return null;
    }

    return $body;
}

function httpPost(string $url, string $body, string $cookieJar): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BondKeeperBot/1.0; +data seeding, stage 3)',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'X-Requested-With: XMLHttpRequest',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error !== '') {
        echo "  cURL ошибка: {$error}\n";
        return null;
    }
    echo "  HTTP {$httpCode}\n";

    return $response;
}

function printSpaDiagnostics(string $body): void
{
    echo "\n  --- самые частые классы (кандидаты на контейнер строки списка) ---\n";
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $body);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

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
