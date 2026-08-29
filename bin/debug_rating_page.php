<?php

declare(strict_types=1);

/**
 * Диагностика этапа 3 (рейтинги): печатает структуру реальной страницы
 * рейтингового агентства, прежде чем писать под неё парсер — тот же
 * принцип, что bin/debug_iss_security.php сыграл для ISS API и
 * bin/check_fns_blocks.php для service.nalog.ru: сначала смотрим на
 * реальный ответ, потом пишем код под него, а не гадаем по докам.
 *
 * У каждого из 4 агентств («Эксперт РА», АКРА, НРА, НКР) свой движок
 * сайта — общего парсера не будет, нужен разбор под каждый. Этот скрипт
 * не знает заранее ни про одно агентство: просто скачивает URL и печатает
 * то, что даёт зацепиться за структуру:
 *   - все <table> на странице (сколько строк/колонок, первые 3 строки текстом);
 *   - ссылки на .xlsx/.xls (у НРА/НКР должна быть кнопка выгрузки в Excel);
 *   - все вхождения слова "ИНН" с окружающим контекстом (карточки компаний
 *     показывают ИНН не у всех, надо увидеть формат там, где он есть);
 *   - если ответ не HTML (например, сама выгрузка .xlsx) — сохраняет сырые
 *     байты в файл рядом и печатает только размер/сигнатуру, дальше смотреть
 *     локально (unzip -l / открыть в Excel).
 *
 * Запуск:
 *   php bin/debug_rating_page.php https://raexpert.ru/ratings/
 *   php bin/debug_rating_page.php https://raexpert.ru/database/companies/raiffaizenbank_avstriya/
 *   php bin/debug_rating_page.php https://www.acra-ratings.ru/ratings/issuers/
 *   php bin/debug_rating_page.php https://www.ra-national.ru/ratings/
 *   php bin/debug_rating_page.php https://ratings.ru/ratings/issuers/
 *
 * Можно сразу несколько URL за один запуск — раздел за разделом.
 */

$urls = array_slice($argv, 1);
if ($urls === []) {
    fwrite(STDERR, "Использование: php bin/debug_rating_page.php URL [URL ...]\n");
    exit(1);
}

foreach ($urls as $url) {
    echo "\n========== {$url} ==========\n";
    debugOneUrl($url);
}

function debugOneUrl(string $url): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,*/*'],
        // Обычный браузерный UA: это публичные страницы со списком
        // рейтингов/пресс-релизов, не защищённый сервис вроде ФНС — но
        // некоторые сайты всё равно блокируют явно нестандартные UA.
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BondKeeperBot/1.0; +data seeding, stage 3)',
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $curlError !== '') {
        echo "  ошибка cURL: {$curlError}\n";
        return;
    }

    echo "  HTTP {$httpCode}, Content-Type: {$contentType}\n";

    $body = substr($raw, $headerSize);
    $isHtml = strpos($contentType, 'html') !== false || strpos(ltrim($body), '<') !== false;

    if (!$isHtml) {
        $path = sys_get_temp_dir() . '/bondkeeper_rating_' . md5($url) . guessExtension($contentType);
        file_put_contents($path, $body);
        echo '  Не похоже на HTML — сохранено как есть: ' . $path . ' (' . strlen($body) . " байт)\n";
        echo '  Первые байты (сигнатура): ' . bin2hex(substr($body, 0, 8)) . "\n";
        echo "  Дальше смотреть локально: unzip -l '{$path}' (если это .xlsx — это ZIP-архив с XML внутри)\n";
        return;
    }

    echo '  Размер HTML: ' . strlen($body) . " байт\n";

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $body);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    // 1) Таблицы — самый вероятный контейнер для списка "компания/рейтинг/прогноз/дата"
    $tables = $xpath->query('//table');
    echo "\n  --- таблиц на странице: {$tables->length} ---\n";
    foreach ($tables as $i => $table) {
        $rows = $xpath->query('.//tr', $table);
        echo "  Таблица #{$i}: строк {$rows->length}\n";
        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex >= 3) {
                break;
            }
            $cells = $xpath->query('.//th|.//td', $row);
            $cellTexts = [];
            foreach ($cells as $cell) {
                $cellTexts[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent) ?? '');
            }
            echo '    строка ' . $rowIndex . ': ' . implode(' | ', $cellTexts) . "\n";
        }
    }

    // 2) Ссылки на Excel-выгрузку
    $excelLinks = $xpath->query('//a[contains(@href, ".xlsx") or contains(@href, ".xls") or contains(@href, "export")]');
    echo "\n  --- ссылок, похожих на Excel-выгрузку: {$excelLinks->length} ---\n";
    foreach ($excelLinks as $link) {
        $href = $link->getAttribute('href');
        $text = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');
        echo "    [{$text}] {$href}\n";
    }

    // 3) Вхождения "ИНН" с контекстом — карточки компаний показывают его не у всех
    $plainText = preg_replace('/\s+/u', ' ', $doc->textContent) ?? '';
    $offset = 0;
    $found = 0;
    while (($pos = mb_stripos($plainText, 'инн', $offset)) !== false) {
        $found++;
        $context = mb_substr($plainText, max(0, $pos - 40), 100);
        echo "\n  --- \"ИНН\" найдено (#{$found}), контекст ---\n  ...{$context}...\n";
        $offset = $pos + 3;
        if ($found >= 5) {
            echo "  (дальнейшие вхождения не печатаю, чтобы не раздувать вывод)\n";
            break;
        }
    }
    if ($found === 0) {
        echo "\n  (\"ИНН\" на странице не встретилось ни разу)\n";
    }

    // 3.5) Если реальных данных на странице почти нет (мало таблиц, ИНН
    // только в форме поиска/тексте валидации, а не в списке компаний) —
    // вероятно, список подгружается JS-ом после загрузки страницы (SPA),
    // а не отдаётся сразу в HTML. Ищем зацепки, откуда SPA берёт данные:
    // подключаемые скрипты (framework виден по путям чанков) и типичные
    // переменные, в которые фреймворки кладут начальное состояние.
    echo "\n  --- <script src=...> на странице (для определения фреймворка/сборки) ---\n";
    preg_match_all('/<script[^>]*\bsrc="([^"]*)"/', $body, $scriptMatches);
    foreach (array_slice($scriptMatches[1], 0, 25) as $src) {
        echo "    {$src}\n";
    }

    echo "\n  --- признаки SPA-состояния/API в HTML ---\n";
    $spaMarkers = ['__NEXT_DATA__', '__NUXT__', '__INITIAL_STATE__', '__APOLLO_STATE__', 'window.__'];
    foreach ($spaMarkers as $marker) {
        if (strpos($body, $marker) !== false) {
            echo "    найден маркер: {$marker}\n";
        }
    }
    preg_match_all('/"(?:apiUrl|api_url|baseURL|baseUrl|endpoint)"\s*:\s*"([^"]*)"/', $body, $apiMatches);
    foreach (array_unique($apiMatches[1]) as $apiUrl) {
        echo "    похоже на API-адрес в коде страницы: {$apiUrl}\n";
    }
    preg_match_all('#"(/[a-zA-Z0-9_/-]*api[a-zA-Z0-9_/-]*)"#i', $body, $apiPathMatches);
    foreach (array_slice(array_unique($apiPathMatches[1]), 0, 20) as $apiPath) {
        echo "    похожий на API путь в коде страницы: {$apiPath}\n";
    }

    // 4) Если явных таблиц нет — вероятно, карточки/список через div/li.
    // Печатаем самые часто повторяющиеся значения class у потомков <body>,
    // чтобы было видно, какой класс — вероятный контейнер одной строки списка.
    if ($tables->length === 0) {
        echo "\n  --- таблиц нет, ищем повторяющиеся классы контейнеров (кандидаты на \"одна строка списка\") ---\n";
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
        $top = array_slice($classCounts, 0, 15, true);
        foreach ($top as $class => $count) {
            echo "    .{$class}: {$count}\n";
        }
    }
}

function guessExtension(string $contentType): string
{
    if (strpos($contentType, 'spreadsheetml') !== false) {
        return '.xlsx';
    }
    if (strpos($contentType, 'ms-excel') !== false) {
        return '.xls';
    }
    if (strpos($contentType, 'zip') !== false) {
        return '.zip';
    }

    return '.bin';
}
