<?php

declare(strict_types=1);

/**
 * Офлайн-проверка GetNewsPager — постраничной загрузки GetNews (этап 5,
 * docs/STAGE5_PAYMENTS.md). Сеть и БД не нужны: вместо НРД — клиент,
 * который отдаёт заранее заданный список страницами по limit/skip, как
 * это делает API (от новых сообщений к старым).
 *
 * Запуск (из корня репозитория):
 *   php tests/test_getnews_pager.php
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Payments\GetNewsClientInterface;
use BondKeeper\Payments\GetNewsPager;

final class ListGetNewsClient implements GetNewsClientInterface
{
    /** @var array<int, array{filter: array<string, mixed>, limit: int, skip: int}> */
    public array $calls = [];

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $arriveBeforeRequest номер запроса (с 1) => сообщение, которое появится в начале ленты перед этим запросом
     */
    public function __construct(private array $items, private readonly array $arriveBeforeRequest = [])
    {
    }

    public function fetchNews(array $filter = [], int $limit = 10, int $skip = 0): array
    {
        $this->calls[] = ['filter' => $filter, 'limit' => $limit, 'skip' => $skip];
        $request = count($this->calls);
        if (isset($this->arriveBeforeRequest[$request])) {
            array_unshift($this->items, $this->arriveBeforeRequest[$request]);
        }

        return array_slice($this->items, $skip, $limit);
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

/** @return array<int, array<string, mixed>> */
function messages(int $count): array
{
    $items = [];
    for ($i = $count; $i >= 1; $i--) { // от новых к старым, как отдаёт НРД
        $items[] = ['content_id_out' => 16000000000 + $i, 'id' => $i, 'ca_type' => 'INTR'];
    }

    return $items;
}

/**
 * @param array<string, mixed> $filter
 * @return array{sizes: array<int, int>, ids: array<int, int>}
 */
function drain(GetNewsPager $pager, array $filter = []): array
{
    $sizes = [];
    $ids = [];
    foreach ($pager->pages($filter) as $pageNumber => $items) {
        $sizes[$pageNumber] = count($items);
        foreach ($items as $item) {
            $ids[] = (int) $item['id'];
        }
    }

    return ['sizes' => $sizes, 'ids' => $ids];
}

echo "--- окно больше одной страницы ---\n";
$client = new ListGetNewsClient(messages(2500));
$pager = new GetNewsPager($client, 1000, 30, 0);
$result = drain($pager, ['category' => 'CORP_ACTION']);
check('2500 сообщений — три страницы: 1000, 1000, 500', [1 => 1000, 2 => 1000, 3 => 500], $result['sizes']);
check('получены все 2500, без пропусков и повторов', [2500, 2500], [count($result['ids']), count(array_unique($result['ids']))]);
check('порядок сохранён: от новых к старым', [2500, 1], [$result['ids'][0], $result['ids'][2499]]);
check('три запроса, skip = 0, 1000, 2000', [0, 1000, 2000], array_column($client->calls, 'skip'));
check('фильтр уходит в каждый запрос без изменений', [['category' => 'CORP_ACTION']], array_values(array_unique(array_column($client->calls, 'filter'), SORT_REGULAR)));
check('окно получено целиком', true, $pager->isComplete());

echo "\n--- граничные случаи ---\n";
$client = new ListGetNewsClient(messages(2000));
$pager = new GetNewsPager($client, 1000, 30, 0);
$result = drain($pager);
check('ровно 2000 — две полные страницы и третий, пустой запрос', [[1 => 1000, 2 => 1000], 3, true], [$result['sizes'], $pager->requestsMade(), $pager->isComplete()]);

$client = new ListGetNewsClient(messages(1000));
$pager = new GetNewsPager($client, 1000, 30, 0);
$result = drain($pager);
check('ровно 1000 (как в первой выгрузке) — делается второй запрос, а не «наверное, это всё»', [1000, 2], [count($result['ids']), $pager->requestsMade()]);

$pager = new GetNewsPager(new ListGetNewsClient([]), 1000, 30, 0);
check('пустое окно — страниц нет, один запрос, считается полным', [[], 1, true], [drain($pager)['sizes'], $pager->requestsMade(), $pager->isComplete()]);

$client = new ListGetNewsClient(messages(700));
$pager = new GetNewsPager($client, 1000, 30, 0);
check('меньше страницы — один запрос', [700, 1], [count(drain($pager)['ids']), $pager->requestsMade()]);

echo "\n--- предохранитель и размер страницы ---\n";
$client = new ListGetNewsClient(messages(2500));
$pager = new GetNewsPager($client, 1000, 2, 0);
$result = drain($pager);
check('--max-pages=2 при 2500 сообщениях: получено 2000 и явный признак «не целиком»', [2000, false, 2], [count($result['ids']), $pager->isComplete(), $pager->requestsMade()]);

$client = new ListGetNewsClient(messages(1200));
$pager = new GetNewsPager($client, 5000, 30, 0);
drain($pager);
check('страница больше 1000 не запрашивается — API столько не отдаёт', [1000, 1000], array_column($client->calls, 'limit'));

$client = new ListGetNewsClient(messages(750));
$pager = new GetNewsPager($client, 300, 30, 0);
check('страница по 300: 300, 300, 150', [1 => 300, 2 => 300, 3 => 150], drain($pager)['sizes']);

echo "\n--- новое сообщение появилось между запросами ---\n";
$client = new ListGetNewsClient(messages(2500), [2 => ['content_id_out' => 16000009999, 'id' => 9999, 'ca_type' => 'INTR']]);
$pager = new GetNewsPager($client, 1000, 30, 0);
$result = drain($pager);
check('лента сдвинулась на одно сообщение — повтор на стыке страниц отброшен', [1, 2500], [$pager->duplicatesSkipped(), count(array_unique($result['ids']))]);
check('ни одно старое сообщение не потеряно', [], array_values(array_diff(range(1, 2500), $result['ids'])));
check('страницы: 1000, 999 (один повтор), 501', [1 => 1000, 2 => 999, 3 => 501], $result['sizes']);

echo "\n--- повторное использование ---\n";
$client = new ListGetNewsClient(messages(1500));
$pager = new GetNewsPager($client, 1000, 30, 0);
drain($pager);
$second = drain($pager);
check('второй проход тем же объектом начинает с чистого листа', [1500, 2, 0], [count($second['ids']), $pager->requestsMade(), $pager->duplicatesSkipped()]);

$client = new ListGetNewsClient([['id' => 5], ['id' => 4], ['title_ru' => 'без идентификаторов'], ['title_ru' => 'без идентификаторов']]);
$pager = new GetNewsPager($client, 1000, 30, 0);
$count = 0;
foreach ($pager->pages([]) as $items) {
    $count += count($items);
}
check('сообщение без content_id_out берётся по id, совсем без идентификаторов — не отбрасывается', [4, 0], [$count, $pager->duplicatesSkipped()]);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
