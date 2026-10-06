<?php

declare(strict_types=1);

/**
 * Офлайн-проверка листания графика выплат в BondizationImporter. Найдено
 * 5 октября 2026: bondization отдаёт купоны и амортизации страницами по
 * 20 строк, а запрос был один — в базе у 1551 бумаги график обрывался на
 * двадцатом купоне. Здесь вместо биржи — клиент, который отдаёт заранее
 * заданный график страницами с блоком *.cursor, как это делает ISS API
 * (проверено на живом ответе по RU000A105RF6: 60 купонов, 24 амортизации).
 * Запись в БД не проверяется (в ней MySQL-синтаксис) — только сбор строк.
 *
 * Запуск (из корня репозитория):
 *   php -d extension=pdo_sqlite tests/test_bondization_paging.php
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Iss\BondizationImporter;
use BondKeeper\Iss\IssClient;

final class PagedIssClient extends IssClient
{
    /** @var array<int, array<string, scalar>> */
    public array $queries = [];

    public function __construct(private readonly int $coupons, private readonly int $amortizations, private readonly bool $withCursor = true)
    {
    }

    public function getJson(string $path, array $query = []): array
    {
        $this->queries[] = $query;
        $limit = (int) ($query['limit'] ?? 20); // без limit биржа отдаёт 20
        $start = (int) ($query['start'] ?? 0);

        $response = [
            'coupons' => ['columns' => ['coupondate', 'value'], 'data' => self::rows($this->coupons, $start, $limit, 'coupon')],
            'amortizations' => ['columns' => ['amortdate', 'value'], 'data' => self::rows($this->amortizations, $start, $limit, 'amort')],
        ];
        if ($this->withCursor) {
            $response['coupons.cursor'] = ['columns' => ['INDEX', 'TOTAL', 'PAGESIZE'], 'data' => [[$start, $this->coupons, $limit]]];
            $response['amortizations.cursor'] = ['columns' => ['INDEX', 'TOTAL', 'PAGESIZE'], 'data' => [[$start, $this->amortizations, $limit]]];
        }

        return $response;
    }

    /** @return array<int, array{0: string, 1: float}> */
    private static function rows(int $total, int $start, int $limit, string $prefix): array
    {
        $rows = [];
        for ($i = $start; $i < min($total, $start + $limit); $i++) {
            $rows[] = ["{$prefix}-" . ($i + 1), 1.0];
        }

        return $rows;
    }
}

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

/** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} */
function fetchSchedule(IssClient $client): array
{
    $importer = new BondizationImporter($client, new PDO('sqlite::memory:'));
    $method = new ReflectionMethod($importer, 'fetchSchedule');
    $method->setAccessible(true);

    return $method->invoke($importer, 'RU000A105RF6');
}

echo "--- выпуск с ежемесячным купоном: 60 купонов, 24 амортизации (как у RU000A105RF6) ---\n";
$client = new PagedIssClient(60, 24);
[$coupons, $amortizations] = fetchSchedule($client);
check('получены все 60 купонов, а не первые 20', [60, 'coupon-1', 'coupon-60'], [count($coupons), $coupons[0]['coupondate'], $coupons[59]['coupondate']]);
check('получены все 24 амортизации', 24, count($amortizations));
check('хватило одного запроса с limit=100', [1, 100, 0], [count($client->queries), $client->queries[0]['limit'], $client->queries[0]['start']]);
check('в запросе просим и блоки курсора', true, str_contains((string) $client->queries[0]['iss.only'], 'coupons.cursor'));

echo "\n--- длинный график: 250 купонов, 130 амортизаций ---\n";
$client = new PagedIssClient(250, 130);
[$coupons, $amortizations] = fetchSchedule($client);
check('три страницы: start = 0, 100, 200', [0, 100, 200], array_column($client->queries, 'start'));
check('все 250 купонов по порядку, без повторов', [250, 250, 'coupon-250'], [count($coupons), count(array_unique(array_column($coupons, 'coupondate'))), $coupons[249]['coupondate']]);
check('все 130 амортизаций (блок закончился на второй странице)', [130, 130], [count($amortizations), count(array_unique(array_column($amortizations, 'amortdate')))]);

echo "\n--- граничные случаи ---\n";
$client = new PagedIssClient(100, 0);
[$coupons, $amortizations] = fetchSchedule($client);
check('ровно 100 купонов — один запрос, курсор говорит, что строк больше нет', [100, 0, 1], [count($coupons), count($amortizations), count($client->queries)]);

$client = new PagedIssClient(0, 0);
[$coupons, $amortizations] = fetchSchedule($client);
check('пустой график (бескупонная бумага) — один запрос, пустые списки', [[], [], 1], [$coupons, $amortizations, count($client->queries)]);

$client = new PagedIssClient(230, 5, false);
[$coupons, $amortizations] = fetchSchedule($client);
check('ответ без блока курсора — листаем, пока страница приходит полной', [230, 5, 3], [count($coupons), count($amortizations), count($client->queries)]);

$client = new PagedIssClient(100, 0, false);
[$coupons] = fetchSchedule($client);
check('без курсора и ровно 100 строк — лишний пустой запрос, данные те же', [100, 2], [count($coupons), count($client->queries)]);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
