<?php

declare(strict_types=1);

/**
 * Офлайн-проверка CurrentRatingsReconciler (17 сентября 2026) — ПОЛНОСТЬЮ
 * тестируемо на SQLite (в отличие от большинства *Importer в этом
 * пакете): вся логика — обычные SELECT + сравнение в PHP, нигде нет
 * MySQL-only ON DUPLICATE KEY UPDATE (класс вообще ничего не пишет,
 * только читает и сравнивает — см. его докблок, это и есть его смысл).
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=pdo_sqlite -d extension=mbstring tests/test_current_ratings_reconciler.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\CurrentRatingsReconciler;

$failures = 0;
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n";
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, short_name TEXT)');
$db->exec('CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, outlook TEXT, last_action_date TEXT, matched_by_root_name INTEGER DEFAULT 0)');
$db->exec('CREATE TABLE issuer_spv_links (spv_inn TEXT PRIMARY KEY, issuer_id INTEGER, spv_name TEXT, note TEXT)');

$db->exec("INSERT INTO issuers (id, short_name) VALUES (1, 'Роснефть')");
$db->exec("INSERT INTO issuers (id, short_name) VALUES (2, 'Газпром')");
$db->exec("INSERT INTO issuers (id, short_name) VALUES (3, 'Лукойл')");
$db->exec("INSERT INTO issuers (id, short_name) VALUES (4, 'Магнит')");
$db->exec("INSERT INTO issuers (id, short_name) VALUES (7, 'Аэрофьюэлз')");
$db->exec("INSERT INTO issuers (id, short_name) VALUES (8, 'ФСК Активы')");

// Эмитент 1: полное совпадение — не должно попасть ни в один список расхождений.
$db->exec("INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date) VALUES (1, 'nkr', 'AAA.ru', 'stable', '2026-07-01')");

// Эмитент 2: расхождение по rating И по outlook одновременно — два разных поля.
$db->exec("INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date) VALUES (2, 'nkr', 'AA.ru', 'stable', '2026-06-15')");

// Эмитент 3: есть только у нас (агентство сняло с публикации / сбой снимка) — не в снимке ниже.
$db->exec("INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date) VALUES (3, 'nkr', 'A.ru', null, '2026-05-01')");

// Эмитент 4: другое агентство — не должен попасть в сверку по 'nkr' вообще.
$db->exec("INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date) VALUES (4, 'acra', 'BBB(RU)', 'positive', '2026-08-01')");

// Эмитент 7 (кейс 5b, реальный случай Аэрофьюэлз): есть только у нас, но
// строка получена ТРЕТЬИМ уровнем сопоставления (matched_by_root_name=1)
// — расхождение "ожидаемое", не сигнал сбоя снимка.
$db->exec("INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date, matched_by_root_name) VALUES (7, 'nkr', 'A.ru', null, '2026-04-01', 1)");

// Эмитент 8 (кейс 5b, реальный случай ФСК Активы/ЕВА): есть только у нас,
// БЕЗ matched_by_root_name, но issuer_id — цель явной ручной связки
// issuer_spv_links — тоже "ожидаемое" расхождение.
$db->exec("INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date) VALUES (8, 'nkr', 'BBB.ru', null, '2026-03-01')");
$db->exec("INSERT INTO issuer_spv_links (spv_inn, issuer_id, spv_name) VALUES ('7708335695', 8, 'ООО «ЕВА»')");

$snapshot = [
    ['issuer_id' => 1, 'issuer_name' => 'Роснефть', 'rating' => 'AAA.ru', 'outlook' => 'stable', 'last_action_date' => '2026-07-01'],
    ['issuer_id' => 2, 'issuer_name' => 'Газпром', 'rating' => 'AAA.ru', 'outlook' => 'positive', 'last_action_date' => '2026-06-15'],
    // Эмитент 5 — у агентства есть, у нас current_ratings для (5, 'nkr') нет вообще.
    ['issuer_id' => 5, 'issuer_name' => 'Новый эмитент', 'rating' => 'A.ru', 'outlook' => null, 'last_action_date' => '2026-09-01'],
];

$reconciler = new CurrentRatingsReconciler($db);
$result = $reconciler->reconcile('nkr', $snapshot);

check('snapshot_count — размер переданного снимка', $result['snapshot_count'] === 3);

// --- field_mismatches ---
check('field_mismatches: ровно 2 расхождения (rating и outlook у Газпрома)', count($result['field_mismatches']) === 2);
$fields = array_map(static fn (array $d): string => $d['field'], $result['field_mismatches']);
sort($fields);
check('field_mismatches: оба поля — rating и outlook', $fields === ['outlook', 'rating']);
foreach ($result['field_mismatches'] as $d) {
    check("field_mismatches: расхождение про issuer_id=2 (Газпром), поле {$d['field']}", $d['issuer_id'] === 2 && $d['issuer_name'] === 'Газпром');
    if ($d['field'] === 'rating') {
        check('field_mismatches: rating — наше AA.ru vs агентство AAA.ru', $d['ours'] === 'AA.ru' && $d['theirs'] === 'AAA.ru');
    } else {
        check('field_mismatches: outlook — наше stable vs агентство positive', $d['ours'] === 'stable' && $d['theirs'] === 'positive');
    }
}

// --- missing_in_ours ---
check('missing_in_ours: ровно 1 (эмитент 5, есть у агентства, нет у нас)', count($result['missing_in_ours']) === 1);
check('missing_in_ours: issuer_id=5, имя корректно', $result['missing_in_ours'][0]['issuer_id'] === 5 && $result['missing_in_ours'][0]['issuer_name'] === 'Новый эмитент');

// --- missing_in_snapshot ---
check('missing_in_snapshot: ровно 3 (эмитенты 3, 7, 8 — есть у нас для nkr, в снимке нет)', count($result['missing_in_snapshot']) === 3);
$byIssuerId = [];
foreach ($result['missing_in_snapshot'] as $d) {
    $byIssuerId[$d['issuer_id']] = $d;
}
check('missing_in_snapshot: issuer_id=3, наше значение сохранено (A.ru)', $byIssuerId[3]['ours'] === 'A.ru');
check('missing_in_snapshot: эмитент 4 (другое агентство, acra) НЕ попал — фильтр по agency работает', !in_array(4, array_column($result['missing_in_snapshot'], 'issuer_id'), true));

// --- Кейс 5b: expected — matched_by_root_name / issuer_spv_links ---
check('missing_in_snapshot: эмитент 3 (обычная строка, ни root, ни spv_link) — expected=false', $byIssuerId[3]['expected'] === false);
check('missing_in_snapshot: эмитент 7 (matched_by_root_name=1, реальный случай Аэрофьюэлз) — expected=true', $byIssuerId[7]['expected'] === true);
check('missing_in_snapshot: эмитент 8 (issuer_spv_links, реальный случай ФСК Активы/ЕВА) — expected=true', $byIssuerId[8]['expected'] === true);

// --- Полное совпадение снимка с нашими данными -> все три списка пусты ---
$exactSnapshot = [
    ['issuer_id' => 1, 'issuer_name' => 'Роснефть', 'rating' => 'AAA.ru', 'outlook' => 'stable', 'last_action_date' => '2026-07-01'],
];
$exactResult = $reconciler->reconcile('nkr', $exactSnapshot);
check('Полное совпадение: field_mismatches пуст', $exactResult['field_mismatches'] === []);
check('Полное совпадение: missing_in_ours пуст', $exactResult['missing_in_ours'] === []);
check(
    'Полное совпадение: missing_in_snapshot содержит остальных наших nkr-эмитентов (2, 3, 7, 8), не 1',
    !in_array(1, array_column($exactResult['missing_in_snapshot'], 'issuer_id'), true)
    && in_array(2, array_column($exactResult['missing_in_snapshot'], 'issuer_id'), true)
    && in_array(3, array_column($exactResult['missing_in_snapshot'], 'issuer_id'), true)
    && in_array(7, array_column($exactResult['missing_in_snapshot'], 'issuer_id'), true)
    && in_array(8, array_column($exactResult['missing_in_snapshot'], 'issuer_id'), true)
);

// --- Кейс 3: applyMissingInOurs() — эмитент 5 из missing_in_ours записывается в БД ---
check('missing_in_ours: перед применением содержит полные данные снимка (rating/outlook/last_action_date)', $result['missing_in_ours'][0]['rating'] === 'A.ru' && $result['missing_in_ours'][0]['outlook'] === null && $result['missing_in_ours'][0]['last_action_date'] === '2026-09-01');
$db->exec("INSERT INTO issuers (id, short_name) VALUES (5, 'Новый эмитент')");
$applied = $reconciler->applyMissingInOurs('nkr', $result['missing_in_ours']);
check('applyMissingInOurs: вернул 1 (записана ровно 1 строка)', $applied === 1);
$afterApply = $reconciler->reconcile('nkr', $snapshot);
check('applyMissingInOurs: после применения эмитент 5 больше не в missing_in_ours', $afterApply['missing_in_ours'] === []);
check('applyMissingInOurs: после применения эмитент 5 не расходится по полям (записан ровно из снимка)', array_values(array_filter($afterApply['field_mismatches'], static fn (array $d): bool => $d['issuer_id'] === 5)) === []);

// --- outlook null у обеих сторон — не расхождение ---
$db->exec("INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date) VALUES (6, 'nkr', 'BB.ru', null, '2026-08-20')");
$db->exec("INSERT INTO issuers (id, short_name) VALUES (6, 'Без прогноза')");
$nullOutlookResult = $reconciler->reconcile('nkr', [
    ['issuer_id' => 6, 'issuer_name' => 'Без прогноза', 'rating' => 'BB.ru', 'outlook' => null, 'last_action_date' => '2026-08-20'],
]);
check('outlook: null у нас и null у агентства — НЕ расхождение', $nullOutlookResult['field_mismatches'] === []);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
