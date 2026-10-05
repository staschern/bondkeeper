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
 * ТЕСТОВЫЙ ДОСТУП (письмо НРД, 05.10.2026): данные доступны ТОЛЬКО за
 * фиксированный период 18.09–02.10.2026 — не "последние N дней от
 * сегодня". По умолчанию скрипт считает окно от сегодняшней даты
 * (--days), что для тестового доступа НЕ подойдёт — используйте
 * --from=/--to= с датами из письма:
 *   php bin/debug_getnews.php --from=2026-09-18 --to=2026-10-02 --limit=1000
 *
 * Первый прогон (05.10.2026, --limit=200) вернул РОВНО 200 записей —
 * подозрительно похоже на обрезанный лимит, а не на всё окно; сервис
 * по документации принимает --limit до 1000, сюда и подняли по
 * умолчанию. Если 1000 снова придёт впритык — нужна пагинация через
 * --skip= (параметр есть в API, в этом скрипте пока не реализован,
 * пока хватало одного запроса).
 *
 * ВЫВОД: полный JSON всех новостей всегда сохраняется в файл
 * (var/getnews_debug_ОТ_ДО.json) — слишком много для консоли. В
 * консоль печатается только сводка (все ca_type/state целиком) и,
 * чтобы увидеть реальные примеры без сотен повторов одного и того же,
 * — ОДИН полный пример на каждую уникальную комбинацию (ca_type,
 * state) — именно это нужно для разбора "получено"/"передано"/"не
 * исполнено" в PaymentProcessor.
 *
 * Запуск:
 *   php bin/debug_getnews.php                             (проверка токена + 10 последних новостей по корп. действиям, --days=7 от сегодня)
 *   php bin/debug_getnews.php --from=2026-09-18 --to=2026-10-02 --limit=1000  (тестовый доступ — фиксированное окно)
 *   php bin/debug_getnews.php --days=14                    (альтернатива --from/--to: окно от сегодня, по умолчанию 7)
 *   php bin/debug_getnews.php --category=COMPANY           (CORP_ACTION по умолчанию; также SECURITY/COMPANY)
 *   php bin/debug_getnews.php --limit=1000                 (по умолчанию 10; максимум по документации — 1000)
 *   php bin/debug_getnews.php --raw-all                    (вдобавок печатает в консоль ПОЛНЫЙ JSON КАЖДОЙ новости, не только по одной на комбинацию — обычно не нужно, всё и так в файле)
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Payments\GetNewsClient;
use BondKeeper\Payments\GetNewsConfig;

$days = 7;
$category = 'CORP_ACTION';
$limit = 10;
$explicitFrom = null;
$explicitTo = null;
$rawAll = in_array('--raw-all', $argv, true) || in_array('--raw', $argv, true);
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
    if (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $explicitFrom = $m[1];
    }
    if (preg_match('/^--to=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $explicitTo = $m[1];
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

$dateTo = $explicitTo ?? (new DateTimeImmutable('now'))->format('Y-m-d');
$dateFrom = $explicitFrom ?? (new DateTimeImmutable('now'))->modify("-{$days} days")->format('Y-m-d');
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
if (count($items) === $limit) {
    echo "ВНИМАНИЕ: получено ровно --limit={$limit} — возможно, это не всё окно, а обрезанная страница. Увеличьте --limit (максимум 1000).\n";
}
if ($items === []) {
    echo "Пусто — попробуйте увеличить --days или сменить --category.\n";
    exit(0);
}

$varDir = __DIR__ . '/../var';
if (!is_dir($varDir) && !@mkdir($varDir, 0775, true) && !is_dir($varDir)) {
    throw new RuntimeException("Не удалось создать {$varDir}");
}
$dumpFile = "{$varDir}/getnews_debug_{$dateFrom}_{$dateTo}.json";
file_put_contents($dumpFile, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Полный JSON всех {$dumpFile}\n";

// То самое, чего нет в документации: какие реально встречаются типы КД
// (ca_type) и состояния (data.state.code/name) — ради именно этого и
// запускается скрипт.
$seenCaTypes = [];
$seenStates = [];
/** @var array<string, array<string, mixed>> $firstByCombo */
$firstByCombo = [];
foreach ($items as $i => $item) {
    $caType = (string) ($item['ca_type'] ?? '?');
    $data = is_array($item['data'] ?? null) ? $item['data'] : [];
    $state = is_array($data['state'] ?? null) ? $data['state'] : [];
    $stateKey = ($state['code'] ?? '?') . ' / ' . ($state['name'] ?? '?');
    $seenCaTypes[$caType] = ($seenCaTypes[$caType] ?? 0) + 1;
    $seenStates[$stateKey] = ($seenStates[$stateKey] ?? 0) + 1;

    $combo = "{$caType} | {$stateKey}";
    if (!isset($firstByCombo[$combo])) {
        $firstByCombo[$combo] = $item;
    }

    $n = $i + 1;
    echo sprintf(
        "  #%d [%s] ca_type=%-10s state=%-30s %s\n",
        $n,
        $item['pub_date'] ?? '?',
        $caType,
        $stateKey,
        mb_substr((string) ($item['title_ru'] ?? ''), 0, 80),
    );

    if ($rawAll) {
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

echo "\n========== По одному полному примеру на каждую комбинацию (ca_type, state) — " . count($firstByCombo) . " комбинаций ==========\n";
foreach ($firstByCombo as $combo => $item) {
    echo "\n--- {$combo} ---\n";
    echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

echo "\nПришлите, пожалуйста, вывод этого прогона (сводки + примеры по комбинациям — этого обычно достаточно; полный JSON уже лежит в {$dumpFile} на случай, если понадобится что-то ещё) — по нему пишется разбор \"получено НРД\"/\"передано депонентам\"/\"не исполнено\" для PaymentProcessor.\n";
