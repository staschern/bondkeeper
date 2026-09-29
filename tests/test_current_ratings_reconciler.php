<?php

declare(strict_types=1);

/**
 * Офлайн-проверка CurrentRatingsReconciler — ПОЛНОСТЬЮ тестируемо на
 * SQLite: вся логика — обычные SELECT + сравнение в PHP; единственная
 * запись (applyMissingInOurs()) — простой INSERT.
 *
 * Сентябрь 2026 (разбор сверки от 20.09):
 *   - П3: "нет в снимке" делится на ожидаемые (с причиной) и требующие
 *     внимания — по source (миграция 025), рейтингу 'отозван', связке
 *     issuer_spv_links, подтверждённому названию и заголовку последнего
 *     действия (рейтинг выпуска облигаций);
 *   - старый флаг matched_by_root_name больше НЕ делает строку ожидаемой
 *     (ложные совпадения Озон/Прогресс), только пометка;
 *   - П14: в расхождениях по полям — оба названия (наше и агентства),
 *     источник нашего значения и, для новостей, заголовок и ссылка;
 *   - П8: --apply-missing пишет source='reconcile'.
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
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, short_name TEXT)');
// PRIMARY KEY (issuer_id, agency) — как в боевой схеме (database/001_schema.sql)
// — нужен, чтобы тест устойчивости applyMissingInOurs() ниже (дубль
// issuer_id в missing_in_ours) реально воспроизводил конфликт SQLSTATE 23000.
$db->exec('CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, outlook TEXT, last_action_date TEXT, matched_by_root_name INTEGER DEFAULT 0, source TEXT, PRIMARY KEY (issuer_id, agency))');
$db->exec('CREATE TABLE issuer_spv_links (spv_inn TEXT PRIMARY KEY, issuer_id INTEGER, spv_name TEXT, note TEXT)');
$db->exec('CREATE TABLE issuer_name_match_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, source_key_type TEXT, source_key TEXT, issuer_id INTEGER, status TEXT, match_type TEXT, agency TEXT, source_name TEXT)');
$db->exec('CREATE TABLE rating_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, issuer_id INTEGER, agency TEXT, action_date TEXT, rating_to TEXT, source_title TEXT, source_url TEXT)');

$issuers = [
    1 => 'Роснефть', 2 => 'Газпром', 3 => 'Лукойл', 4 => 'Магнит', 7 => 'Озон', 8 => 'ФСК Активы',
    10 => 'Борец Капитал', 11 => 'реСтор', 12 => 'ВСК', 13 => 'Мэйл.Ру Финанс', 14 => 'Составная вторая компания',
];
foreach ($issuers as $id => $name) {
    $db->prepare('INSERT INTO issuers (id, short_name) VALUES (:id, :name)')->execute(['id' => $id, 'name' => $name]);
}

$cr = $db->prepare('INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date, matched_by_root_name, source) VALUES (:id, :agency, :rating, :outlook, :dt, :root, :source)');
$addCr = static fn (int $id, string $agency, string $rating, ?string $outlook, string $dt, int $root = 0, ?string $source = null) => $cr->execute(
    ['id' => $id, 'agency' => $agency, 'rating' => $rating, 'outlook' => $outlook, 'dt' => $dt, 'root' => $root, 'source' => $source]
);

$addCr(1, 'nkr', 'AAA.ru', 'stable', '2026-07-01', 0, 'snapshot');          // полное совпадение
$addCr(2, 'nkr', 'AA.ru', 'stable', '2026-06-15', 0, 'action');             // расхождение rating+outlook, из новости
$db->exec("INSERT INTO rating_actions (issuer_id, agency, action_date, rating_to, source_title, source_url) VALUES (2, 'nkr', '2026-06-15', 'AA.ru', 'НКР понизило рейтинг ПАО «Газпром»', 'https://ratings.ru/news/2')");
$addCr(3, 'nkr', 'A.ru', null, '2026-05-01');                                // нет в снимке, объяснения нет
$addCr(4, 'acra', 'BBB(RU)', 'positive', '2026-08-01');                      // другое агентство
$addCr(7, 'nkr', 'A+.ru', null, '2026-04-01', 1);                             // старый root-флаг (Озон) — требует внимания
$addCr(8, 'nkr', 'BBB.ru', null, '2026-03-01');                               // связка issuer_spv_links
$db->exec("INSERT INTO issuer_spv_links (spv_inn, issuer_id, spv_name) VALUES ('7708335695', 8, 'ООО «ЕВА»')");
$addCr(10, 'nkr', 'A-.ru', null, '2026-09-02', 0, 'manual');                  // ручной xlsx
$addCr(11, 'nkr', 'отозван', null, '2024-03-14', 0, 'manual');                // отозван (и ручной — первая причина)
$addCr(12, 'nkr', 'ruAA-', null, '2026-09-10', 0, 'action');                  // рейтинг выпуска облигаций
$db->exec("INSERT INTO rating_actions (issuer_id, agency, action_date, rating_to, source_title, source_url) VALUES (12, 'nkr', '2026-09-10', 'ruAA-', '«Эксперт РА» присвоил рейтинги облигациям САО «ВСК» серий 001Р-02R', 'https://raexpert.ru/releases/vsk')");
$addCr(13, 'nkr', 'отозван', null, '2026-01-10', 0, 'action');               // отозван из новости
$addCr(14, 'nkr', 'A.ru', null, '2026-02-02', 0, 'action');                   // подтверждённое название
$db->exec("INSERT INTO issuer_name_match_reviews (source_key_type, source_key, issuer_id, status, match_type, agency, source_name) VALUES ('name', 'СОСТАВНАЯ', 14, 'approved', 'exact_name', 'nkr', 'Составная вторая компания')");

$snapshot = [
    ['issuer_id' => 1, 'issuer_name' => 'ПАО «НК «Роснефть»', 'rating' => 'AAA.ru', 'outlook' => 'stable', 'last_action_date' => '2026-07-01'],
    ['issuer_id' => 2, 'issuer_name' => 'ПАО «Газпром» (у агентства)', 'rating' => 'AAA.ru', 'outlook' => 'positive', 'last_action_date' => '2026-06-15'],
    // Эмитент 5 — у агентства есть, у нас current_ratings для (5, 'nkr') нет вообще.
    ['issuer_id' => 5, 'issuer_name' => 'Новый эмитент (у агентства)', 'rating' => 'A.ru', 'outlook' => null, 'last_action_date' => '2026-09-01'],
];

$reconciler = new CurrentRatingsReconciler($db);
$result = $reconciler->reconcile('nkr', $snapshot);

check('snapshot_count — размер переданного снимка', $result['snapshot_count'] === 3);

echo "--- field_mismatches: оба названия, источник, новость (П14) ---\n";
check('ровно 2 расхождения (rating и outlook у Газпрома)', count($result['field_mismatches']) === 2);
$fields = array_column($result['field_mismatches'], 'field');
sort($fields);
check('оба поля — rating и outlook', $fields === ['outlook', 'rating']);
$d = $result['field_mismatches'][0];
check('наше название — из issuers', $d['our_name'] === 'Газпром');
check('название агентства — из снимка', $d['agency_name'] === 'ПАО «Газпром» (у агентства)');
check('источник нашего значения — action', $d['our_source'] === 'action');
check('заголовок и ссылка нашей новости', $d['our_action_title'] === 'НКР понизило рейтинг ПАО «Газпром»' && $d['our_action_url'] === 'https://ratings.ru/news/2');
foreach ($result['field_mismatches'] as $m) {
    if ($m['field'] === 'rating') {
        check('rating — наше AA.ru vs агентство AAA.ru', $m['ours'] === 'AA.ru' && $m['theirs'] === 'AAA.ru');
    }
}

echo "--- missing_in_ours ---\n";
check('ровно 1 (эмитент 5)', count($result['missing_in_ours']) === 1);
check('issuer_id=5, название агентства и полные данные снимка', $result['missing_in_ours'][0]['issuer_id'] === 5
    && $result['missing_in_ours'][0]['agency_name'] === 'Новый эмитент (у агентства)'
    && $result['missing_in_ours'][0]['rating'] === 'A.ru' && $result['missing_in_ours'][0]['last_action_date'] === '2026-09-01');

echo "--- missing_in_snapshot: ожидаемые с причиной / требуют внимания (П3) ---\n";
$byId = [];
foreach ($result['missing_in_snapshot'] as $m) {
    $byId[$m['issuer_id']] = $m;
}
check('в списке 3, 7, 8, 10, 11, 12, 13, 14; нет 1, 2 (в снимке) и 4 (другое агентство)', array_keys($byId) === [3, 7, 8, 10, 11, 12, 13, 14]);
check('3: без объяснения — требует внимания', $byId[3]['expected'] === false && $byId[3]['reason'] === null && $byId[3]['note'] === null);
check('7 (старый root-флаг, случай Озон): требует внимания + пометка', $byId[7]['expected'] === false && str_contains((string) $byId[7]['note'], 'по корню'));
check('8: связка issuer_spv_links — ожидаемо, в причине ИНН связанной компании', $byId[8]['expected'] === true && str_contains((string) $byId[8]['reason'], '7708335695'));
check('10: source=manual — ожидаемо (ручной xlsx)', $byId[10]['expected'] === true && str_contains((string) $byId[10]['reason'], 'вручную'));
check('11: ручной + отозван — ожидаемо', $byId[11]['expected'] === true);
check('12: последнее действие — рейтинг облигаций — ожидаемо, в причине заголовок', $byId[12]['expected'] === true && str_contains((string) $byId[12]['reason'], 'облигациям САО «ВСК»'));
check('13: отозван (из новости) — ожидаемо', $byId[13]['expected'] === true && str_contains((string) $byId[13]['reason'], 'отозван'));
check('14: подтверждённое название — ожидаемо', $byId[14]['expected'] === true && str_contains((string) $byId[14]['reason'], 'подтверждённое'));
check('наше название и источник в строке', $byId[10]['our_name'] === 'Борец Капитал' && $byId[10]['source'] === 'manual');

echo "--- applyMissingInOurs(): source='reconcile' (П8), устойчивость к дублю ---\n";
$db->exec("INSERT INTO issuers (id, short_name) VALUES (5, 'Новый эмитент')");
check('записана ровно 1 строка', $reconciler->applyMissingInOurs('nkr', $result['missing_in_ours']) === 1);
$row = $db->query("SELECT rating, source, matched_by_root_name FROM current_ratings WHERE issuer_id = 5 AND agency = 'nkr'")->fetch();
check('source=reconcile, matched_by_root_name=0', $row['source'] === 'reconcile' && (int) $row['matched_by_root_name'] === 0);
$after = $reconciler->reconcile('nkr', $snapshot);
check('после применения эмитент 5 больше не в missing_in_ours и не расходится по полям', $after['missing_in_ours'] === []
    && array_filter($after['field_mismatches'], static fn (array $m): bool => $m['issuer_id'] === 5) === []);

$db->exec("INSERT INTO issuers (id, short_name) VALUES (9, 'Дубль')");
$duplicate = [
    ['issuer_id' => 9, 'rating' => 'ruA', 'outlook' => 'stable', 'last_action_date' => '2026-06-01'],
    ['issuer_id' => 9, 'rating' => 'ruA', 'outlook' => 'stable', 'last_action_date' => '2026-06-01'],
];
check('дубль issuer_id — не падает, записывает только первую', $reconciler->applyMissingInOurs('nkr', $duplicate) === 1);

echo "--- outlook null у обеих сторон — не расхождение ---\n";
$addCr(6, 'nkr', 'BB.ru', null, '2026-08-20');
$db->exec("INSERT INTO issuers (id, short_name) VALUES (6, 'Без прогноза')");
$nullOutlook = $reconciler->reconcile('nkr', [
    ['issuer_id' => 6, 'issuer_name' => 'Без прогноза', 'rating' => 'BB.ru', 'outlook' => null, 'last_action_date' => '2026-08-20'],
]);
check('null = null — не расхождение', $nullOutlook['field_mismatches'] === []);

echo "--- Сверка Эксперт РА 29.09.2026: отозван из новости о погашении, статус наблюдения ---\n";
foreach ([20 => 'СОПФ ДОМ.РФ', 21 => 'Селектел', 22 => 'ТрансКонтейнер', 23 => 'ДелоПортс', 24 => 'УК Дело', 25 => 'Без новостей'] as $id => $name) {
    $db->prepare('INSERT INTO issuers (id, short_name) VALUES (:id, :name)')->execute(['id' => $id, 'name' => $name]);
}
$addAction = static fn (int $id, string $date, string $rating, string $title, string $url) => $db->prepare(
    "INSERT INTO rating_actions (issuer_id, agency, action_date, rating_to, source_title, source_url) VALUES (:id, 'expert_ra', :dt, :rating, :title, :url)"
)->execute(['id' => $id, 'dt' => $date, 'rating' => $rating, 'title' => $title, 'url' => $url]);

// 20: только рейтинги облигаций; последнее — отзыв из-за погашения (записан до обновления правил).
$addCr(20, 'expert_ra', 'отозван', null, '2026-09-28', 0, 'action');
$addAction(20, '2026-09-22', 'ruAAA', '«Эксперт РА» присвоил кредитный рейтинг облигациям ООО «СОПФ ДОМ.РФ» (RU000A10G643) на уровне ruAAA', 'https://raexpert.ru/releases/2026/sep22b');
$addAction(20, '2026-09-28', 'отозван', '«Эксперт РА» отозвал без подтверждения кредитный рейтинг облигаций ООО «СОПФ ДОМ.РФ» (RU000A109N54) в связи с их полным погашением', 'https://raexpert.ru/releases/2026/sep28');
// 21: отзыв облигаций по договору — настоящий отзыв, ожидаемо.
$addCr(21, 'expert_ra', 'отозван', null, '2026-09-23', 0, 'action');
$addAction(21, '2026-09-23', 'отозван', '«Эксперт РА» отозвал без подтверждения кредитные рейтинги облигаций АО «Селектел» серий 001P-05R, 001Р-06R, 001Р-07R', 'https://raexpert.ru/releases/2026/sep23a');
// 22–24: наш статус наблюдения против списка без статуса.
$addCr(22, 'expert_ra', 'ruAA-', 'under_review_stable', '2026-09-16', 0, 'action');
$addCr(23, 'expert_ra', 'ruAA-', 'under_review_stable', '2026-06-18', 0, 'action');
$addCr(24, 'expert_ra', 'ruAA-', 'under_review_stable', '2026-09-16', 0, 'action');
$addCr(25, 'expert_ra', 'отозван', null, '2025-01-10', 0, 'action');

$eraResult = $reconciler->reconcile('expert_ra', [
    ['issuer_id' => 22, 'issuer_name' => 'ПАО "ТРАНСКОНТЕЙНЕР"', 'rating' => 'ruAA-', 'outlook' => 'stable', 'last_action_date' => '2026-09-16'],
    ['issuer_id' => 23, 'issuer_name' => 'ООО "ДЕЛОПОРТС"', 'rating' => 'ruAA-', 'outlook' => 'stable', 'last_action_date' => '2026-09-16'],
    ['issuer_id' => 24, 'issuer_name' => 'ООО "УК "ДЕЛО"', 'rating' => 'ruAA-', 'outlook' => 'developing', 'last_action_date' => '2026-09-16'],
]);
$eraMissing = [];
foreach ($eraResult['missing_in_snapshot'] as $m) {
    $eraMissing[$m['issuer_id']] = $m;
}
check('20 СОПФ ДОМ.РФ (отозван из новости о погашении) — требует внимания, не ожидаемое', $eraMissing[20]['expected'] === false && $eraMissing[20]['reason'] === null);
check('…пометка: заголовок, ссылка, причина и чем исправить', str_contains((string) $eraMissing[20]['note'], 'sep28')
    && str_contains((string) $eraMissing[20]['note'], 'из-за погашения') && str_contains((string) $eraMissing[20]['note'], 'fix_bond_redemption_ratings'));
check('21 Селектел (отзыв облигаций по договору) — ожидаемо, в причине последнее действие', $eraMissing[21]['expected'] === true
    && str_contains((string) $eraMissing[21]['reason'], 'рейтинг отозван, последнее действие: ««Эксперт РА» отозвал без подтверждения кредитные рейтинги облигаций АО «Селектел»'));
check('25 отозван без новостей в истории — ожидаемо, причина просто «рейтинг отозван»', $eraMissing[25]['expected'] === true && $eraMissing[25]['reason'] === 'рейтинг отозван');
$eraByIssuer = [];
foreach ($eraResult['field_mismatches'] as $m) {
    $eraByIssuer[$m['issuer_id']][$m['field']] = $m;
}
check('22 ТрансКонтейнер: та же дата, прогноз совпадает — watch_kept', ($eraByIssuer[22]['outlook']['watch_kept'] ?? null) === true && !isset($eraByIssuer[22]['last_action_date']));
check('23 ДелоПортс: дата у агентства новее — обычное расхождение', ($eraByIssuer[23]['outlook']['watch_kept'] ?? null) === false);
check('24 УК Дело: у агентства другой прогноз — обычное расхождение', ($eraByIssuer[24]['outlook']['watch_kept'] ?? null) === false);
check('в расхождениях НКР флаг watch_kept всегда false', array_filter($result['field_mismatches'], static fn (array $m): bool => $m['watch_kept']) === []);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
