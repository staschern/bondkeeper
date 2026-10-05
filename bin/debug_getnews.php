<?php

declare(strict_types=1);

/**
 * РАЗВЕДКА, 5 октября 2026 — ничего не пишет в БД, только проверяет
 * подключение к API НРД (GetNews, nsddata.ru) и печатает РЕАЛЬНЫЕ
 * значения полей, которых нет ни в презентации, ни в PDF-словаре полей,
 * ни в самой OpenAPI-схеме (data.state.code/data.state.name/ca_type —
 * везде просто "string", без перечня значений). Без этого нельзя
 * написать разбор "получено НРД"/"передано депонентам"/"не исполнено" —
 * см. docs/STAGE5_PAYMENTS.md, раздел про открытые вопросы.
 *
 * Подключение (адрес сервера, /api/auth/login, /api/get/news, Bearer-
 * токен) проверено вживую 05.10.2026 без реальных логина/пароля — см.
 * докблок GetNewsClient. Этот скрипт — первый прогон с настоящими
 * учётными данными.
 *
 * Перед запуском: cp config/nsd_api.example.php config/nsd_api.php и
 * вписать login/password, которые выдал НРД (см. README.md).
 *
 * Запуск:
 *   php bin/debug_getnews.php                       (проверка токена + 10 последних новостей по корп. действиям)
 *   php bin/debug_getnews.php --days=14              (окно по дате публикации, по умолчанию 7)
 *   php bin/debug_getnews.php --category=COMPANY     (CORP_ACTION по умолчанию; также SECURITY/COMPANY)
 *   php bin/debug_getnews.php --limit=50
 *   php bin/debug_getnews.php --raw                  (вдобавок печатает ПОЛНЫЙ JSON каждой новости, не только сводку)
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Payments\GetNewsClient;
use BondKeeper\Payments\GetNewsConfig;

$days = 7;
$category = 'CORP_ACTION';
$limit = 10;
$raw = in_array('--raw', $argv, true);
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = (int) $m[1];
    }
    if (preg_match('/^--category=(CORP_ACTION|SECURITY|COMPANY)$/', $arg, $m)) {
        $category = $m[1];
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$config = GetNewsConfig::fromFile(__DIR__ . '/../config/nsd_api.php');
$client = new GetNewsClient($config);

echo "========== Проверка токена (/api/auth/login + /api/auth/check) ==========\n";
try {
    $valid = $client->checkToken();
    echo $valid ? "OK — авторизация прошла, токен действителен.\n" : "ПРОБЛЕМА — логин прошёл, но /api/auth/check отвечает valid=false.\n";
} catch (\Throwable $e) {
    echo 'ОШИБКА авторизации: ' . $e->getMessage() . "\n";
    echo "\nПроверьте config/nsd_api.php (login/password) — дальше без успешной авторизации смысла нет.\n";
    exit(1);
}

$dateTo = (new DateTimeImmutable('now'))->format('Y-m-d');
$dateFrom = (new DateTimeImmutable('now'))->modify("-{$days} days")->format('Y-m-d');
$filter = [
    '$and' => [
        ['category' => $category],
        ['pub_date' => ['$gte' => $dateFrom, '$lte' => $dateTo]],
    ],
];

echo "\n========== /api/get/news: category={$category}, {$dateFrom}..{$dateTo}, limit={$limit} ==========\n";
try {
    $items = $client->fetchNews($filter, $limit, 0);
} catch (\Throwable $e) {
    echo 'ОШИБКА запроса новостей: ' . $e->getMessage() . "\n";
    exit(1);
}

echo 'Получено новостей: ' . count($items) . "\n";
if ($items === []) {
    echo "Пусто — попробуйте увеличить --days или сменить --category.\n";
    exit(0);
}

// То самое, чего нет в документации: какие реально встречаются типы КД
// (ca_type) и состояния (data.state.code/name) — ради именно этого и
// запускается скрипт.
$seenCaTypes = [];
$seenStates = [];
foreach ($items as $i => $item) {
    $caType = (string) ($item['ca_type'] ?? '?');
    $data = is_array($item['data'] ?? null) ? $item['data'] : [];
    $state = is_array($data['state'] ?? null) ? $data['state'] : [];
    $stateKey = ($state['code'] ?? '?') . ' / ' . ($state['name'] ?? '?');
    $seenCaTypes[$caType] = ($seenCaTypes[$caType] ?? 0) + 1;
    $seenStates[$stateKey] = ($seenStates[$stateKey] ?? 0) + 1;

    $n = $i + 1;
    echo sprintf(
        "  #%d [%s] ca_type=%-10s state=%-30s %s\n",
        $n,
        $item['pub_date'] ?? '?',
        $caType,
        $stateKey,
        mb_substr((string) ($item['title_ru'] ?? ''), 0, 80),
    );

    if ($raw) {
        echo "    --- полный JSON ---\n";
        echo '    ' . str_replace("\n", "\n    ", json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . "\n";
    }
}

echo "\n========== Сводка: встреченные ca_type ==========\n";
foreach ($seenCaTypes as $caType => $count) {
    echo "  {$caType}: {$count}\n";
}
echo "\n========== Сводка: встреченные state.code/name ==========\n";
foreach ($seenStates as $state => $count) {
    echo "  {$state}: {$count}\n";
}

echo "\nПришлите, пожалуйста, вывод этого прогона (целиком, с --raw хотя бы для пары примеров купона/погашения с разными ca_type) — по нему пишется разбор \"получено НРД\"/\"передано депонентам\"/\"не исполнено\" для PaymentProcessor.\n";
