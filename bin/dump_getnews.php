<?php

declare(strict_types=1);

/**
 * Выгрузка ВСЕГО доступного окна GetNews в файлы — постранично
 * (GetNewsPager). В БД ничего не пишет, клиентам ничего не шлёт.
 *
 * Зачем: bin/debug_getnews.php берёт одну страницу. Выгрузка 05.10.2026
 * (запрошено 18.09–02.10) вернула 1000 самых свежих сообщений — это
 * 28.09 11:42 … 30.09 20:22, два с половиной дня из двух недель. Правила
 * разбора сообщений нужно проверять на всём окне: частичные выплаты и
 * техдефолты редки (в тех 1000 — три и семь сообщений), а вывод о том,
 * когда НРД публикует «получено», сделан по одному дню.
 *
 * Тестовый доступ (письмо НРД, 05.10.2026): доступны новости от Т-17 до
 * Т-3, где Т — текущий день; на 05.10 это 18.09–02.10. В первой выгрузке
 * сообщений за 01.10 и 02.10 нет, хотя запрос был по 02.10, — поэтому
 * скрипт в конце печатает фактические границы полученного и отдельным
 * запросом проверяет, включает ли условие «по дату» сам этот день.
 *
 * Запуск:
 *   php bin/dump_getnews.php --from=2026-09-18                  (без верхней границы — что отдаст доступ)
 *   php bin/dump_getnews.php --from=2026-09-18 --to=2026-10-02  (как в первой выгрузке, но все страницы)
 *   php bin/dump_getnews.php --from=2026-09-18 --keep-english   (не выбрасывать английские поля; файлы в полтора раза больше)
 *   php bin/dump_getnews.php --from=2026-09-18 --page-size=500 --max-pages=40
 *   php bin/dump_getnews.php --replay=var/getnews_debug_2026-09-18_2026-10-02.json --page-size=300
 *                                                               (проверка самого скрипта на сохранённом файле, без обращения к НРД)
 *
 * Без --from берётся сегодня минус 17 дней. Файлы — var/getnews_dump_ОТ_ДО_partNN.json,
 * по одному на страницу (около 10 МБ без английских полей). Между
 * запросами пауза 2 секунды; обычное окно — 4–6 запросов.
 * Собрать в один архив для передачи:
 *   tar -czf var/getnews_dump.tar.gz var/getnews_dump_*_part*.json
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Payments\GetNewsClient;
use BondKeeper\Payments\GetNewsClientInterface;
use BondKeeper\Payments\GetNewsConfig;
use BondKeeper\Payments\GetNewsPager;

$from = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->modify('-17 days')->format('Y-m-d');
$to = null;
$pageSize = GetNewsPager::MAX_PAGE_SIZE;
$maxPages = 30;
$keepEnglish = in_array('--keep-english', $argv, true);
$replayFile = null;
foreach ($argv as $arg) {
    if (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $from = $m[1];
    }
    if (preg_match('/^--to=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $to = $m[1];
    }
    if (preg_match('/^--page-size=(\d+)$/', $arg, $m)) {
        $pageSize = (int) $m[1];
    }
    if (preg_match('/^--max-pages=(\d+)$/', $arg, $m)) {
        $maxPages = (int) $m[1];
    }
    if (preg_match('/^--replay=(.+)$/', $arg, $m)) {
        $replayFile = $m[1];
    }
}

if ($replayFile !== null) {
    if (!is_file($replayFile)) {
        fwrite(STDERR, "Файл не найден: {$replayFile}\n");
        exit(1);
    }
    /** @var array<int, array<string, mixed>> $replayItems */
    $replayItems = json_decode((string) file_get_contents($replayFile), true, 512, JSON_THROW_ON_ERROR);
    // Отдаёт сохранённый файл страницами, как это делал бы НРД; фильтр не применяется.
    $client = new class ($replayItems) implements GetNewsClientInterface {
        /** @param array<int, array<string, mixed>> $items */
        public function __construct(private readonly array $items)
        {
        }

        public function fetchNews(array $filter = [], int $limit = 10, int $skip = 0): array
        {
            return array_slice($this->items, $skip, $limit);
        }
    };
    echo "ПОВТОР ИЗ ФАЙЛА {$replayFile} — к НРД не обращаюсь.\n";
} else {
    // Страница в тысячу сообщений — около 18 МБ; 20 секунд клиента по умолчанию может не хватить.
    $client = new GetNewsClient(GetNewsConfig::fromFile(__DIR__ . '/../config/nsd_api.php'), 180);
}

$dateCondition = ['$gte' => $from];
if ($to !== null) {
    $dateCondition['$lte'] = $to;
}
$filter = ['$and' => [['category' => 'CORP_ACTION'], ['pub_date' => $dateCondition]]];

$varDir = __DIR__ . '/../var';
if (!is_dir($varDir) && !@mkdir($varDir, 0775, true) && !is_dir($varDir)) {
    fwrite(STDERR, "Не удалось создать {$varDir}\n");
    exit(1);
}
$filePrefix = "{$varDir}/getnews_dump_{$from}_" . ($to ?? 'open');

echo "GetNews, корпоративные действия: с {$from} " . ($to !== null ? "по {$to}" : 'без верхней границы')
    . ", страница — {$pageSize}, не больше {$maxPages} страниц\n";

$pager = new GetNewsPager($client, $pageSize, $maxPages, $replayFile !== null ? 0 : 2);
$total = 0;
$newest = null;
$oldest = null;
/** @var array<string, int> $byDay */
$byDay = [];
/** @var array<string, int> $byType */
$byType = [];
$files = [];

try {
    foreach ($pager->pages($filter) as $pageNumber => $items) {
        foreach ($items as &$item) {
            $pubDate = (string) ($item['pub_date'] ?? '');
            if ($pubDate !== '') {
                $newest = $newest === null || $pubDate > $newest ? $pubDate : $newest;
                $oldest = $oldest === null || $pubDate < $oldest ? $pubDate : $oldest;
                $day = substr($pubDate, 0, 10);
                $byDay[$day] = ($byDay[$day] ?? 0) + 1;
            }
            $caType = (string) ($item['ca_type'] ?? '?');
            $byType[$caType] = ($byType[$caType] ?? 0) + 1;
            if (!$keepEnglish) {
                unset($item['body_en'], $item['title_en'], $item['announce_en']);
            }
        }
        unset($item);

        $file = sprintf('%s_part%02d.json', $filePrefix, $pageNumber);
        file_put_contents($file, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $files[] = $file;
        $total += count($items);
        echo sprintf(
            "  страница %d: сообщений %d, от %s до %s -> %s (%.1f МБ)\n",
            $pageNumber,
            count($items),
            (string) ($items[count($items) - 1]['pub_date'] ?? '?'),
            (string) ($items[0]['pub_date'] ?? '?'),
            basename($file),
            filesize($file) / 1048576,
        );
    }
} catch (\Throwable $e) {
    echo 'ОШИБКА на запросе №' . ($pager->requestsMade() + 1) . ': ' . $e->getMessage() . "\n";
    echo "Уже сохранено сообщений: {$total}. Пришлите этот вывод — по тексту ошибки будет видно, что поправить.\n";
    exit(1);
}

echo "\n========== Итог ==========\n";
echo "Сообщений: {$total}; запросов: {$pager->requestsMade()}; повторов отброшено: {$pager->duplicatesSkipped()}\n";
if (!$pager->isComplete()) {
    echo "ВНИМАНИЕ: остановлено на предохранителе --max-pages={$maxPages}, окно получено НЕ целиком. Запустите с большим --max-pages.\n";
}
if ($total === 0) {
    echo "Пусто. Проверьте даты: тестовый доступ отдаёт только окно от Т-17 до Т-3.\n";
    exit(0);
}
echo "Самое раннее сообщение: {$oldest}\nСамое позднее сообщение: {$newest}\n";

ksort($byDay);
echo "\nПо дням публикации:\n";
foreach ($byDay as $day => $count) {
    echo "  {$day}: {$count}\n";
}
arsort($byType);
echo "\nПо типам (ca_type):\n";
foreach ($byType as $caType => $count) {
    echo "  {$caType}: {$count}\n";
}

// Включает ли условие «по дату» сам этот день? В первой выгрузке запрос
// был «по 02.10», а самое позднее сообщение — от 30.09. Спрашиваем «по
// день самого позднего сообщения» и смотрим, вернётся ли оно.
if ($replayFile === null) {
    $lastDay = substr((string) $newest, 0, 10);
    echo "\n========== Проверка верхней границы ==========\n";
    try {
        $probe = $client->fetchNews(['$and' => [['category' => 'CORP_ACTION'], ['pub_date' => ['$gte' => $from, '$lte' => $lastDay]]]], 1, 0);
        $probeDate = (string) ($probe[0]['pub_date'] ?? '');
        echo "Запрос «по {$lastDay}» вернул самое позднее сообщение от: " . ($probeDate !== '' ? $probeDate : '— (пусто)') . "\n";
        echo str_starts_with($probeDate, $lastDay)
            ? "Вывод: условие «по дату» включает сам этот день.\n"
            : "Вывод: условие «по дату» сам этот день НЕ включает — в опросе верхнюю границу ставить нельзя (или ставить завтрашним днём).\n";
    } catch (\Throwable $e) {
        echo 'Проверка не удалась: ' . $e->getMessage() . "\n";
    }
}

echo "\nФайлов: " . count($files) . ". Собрать в один архив:\n  tar -czf var/getnews_dump.tar.gz var/" . basename($filePrefix) . "_part*.json\n";
echo "Пришлите, пожалуйста, архив и этот вывод целиком.\n";
