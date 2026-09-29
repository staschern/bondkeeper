<?php

declare(strict_types=1);

/**
 * Офлайн-проверка BondRedemptionCleanup (bin/debug_bond_redemption_ratings.php
 * и bin/fix_bond_redemption_ratings.php), правка 29.09.2026 по сверке
 * Эксперт РА: ложные "отозван" от отзыва рейтинга облигаций из-за
 * погашения удаляются вместе с событиями, а текущий рейтинг
 * пересчитывается, только если он всё ещё этот ложный "отозван" —
 * значение из перезаписи снимком не затирается.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_bond_redemption_cleanup.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\BondRedemptionCleanup;

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

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, short_name TEXT)');
$db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE rating_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, issuer_id INTEGER, agency TEXT, action_date TEXT, rating_to TEXT, outlook_to TEXT, source_title TEXT, source_url TEXT, event_id INTEGER)');
$db->exec('CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, outlook TEXT, last_action_date TEXT, matched_by_root_name INTEGER DEFAULT 0, source TEXT, PRIMARY KEY (issuer_id, agency))');

foreach ([20 => 'СОПФ ДОМ.РФ', 21 => 'ПетроИнжиниринг', 22 => 'Только отзыв', 23 => 'Селектел', 24 => 'Автодор'] as $id => $name) {
    $db->prepare('INSERT INTO issuers (id, short_name) VALUES (:id, :name)')->execute(['id' => $id, 'name' => $name]);
}
$action = static function (int $id, string $date, string $rating, ?string $outlook, string $title, ?int $eventId = null, string $agency = 'expert_ra') use ($db): void {
    if ($eventId !== null) {
        $db->prepare('INSERT INTO events (id) VALUES (:id)')->execute(['id' => $eventId]);
    }
    $db->prepare(
        'INSERT INTO rating_actions (issuer_id, agency, action_date, rating_to, outlook_to, source_title, source_url, event_id)
         VALUES (:id, :agency, :dt, :rating, :outlook, :title, :url, :event)'
    )->execute(['id' => $id, 'agency' => $agency, 'dt' => $date, 'rating' => $rating, 'outlook' => $outlook, 'title' => $title, 'url' => "https://raexpert.ru/{$id}/{$date}", 'event' => $eventId]);
};
$current = static fn (int $id, string $agency, string $rating, ?string $outlook, string $date, string $source) => $db->prepare(
    'INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date, source) VALUES (:id, :agency, :rating, :outlook, :dt, :source)'
)->execute(['id' => $id, 'agency' => $agency, 'rating' => $rating, 'outlook' => $outlook, 'dt' => $date, 'source' => $source]);

// 20 СОПФ ДОМ.РФ: только рейтинги облигаций, последнее — отзыв из-за погашения (с событием).
$action(20, '2026-09-22', 'ruAAA', null, '«Эксперт РА» присвоил кредитный рейтинг облигациям ООО «СОПФ ДОМ.РФ» (RU000A10G643) на уровне ruAAA');
$action(20, '2026-09-25', 'ruAAA(EXP)', null, '«Эксперт РА» присвоил ожидаемый кредитный рейтинг облигациям серии 10, планируемым к выпуску ООО «СОПФ ДОМ.РФ», на уровне ruAAA(EXP)');
$action(20, '2026-09-28', 'отозван', null, '«Эксперт РА» отозвал без подтверждения кредитный рейтинг облигаций ООО «СОПФ ДОМ.РФ» (RU000A109N54) в связи с их полным погашением', 1);
$current(20, 'expert_ra', 'отозван', null, '2026-09-28', 'action');
// 21 ПетроИнжиниринг: ложный отзыв в истории, текущий уже из перезаписи снимком.
$action(21, '2026-09-21', 'отозван', null, '«Эксперт РА» отозвал кредитный рейтинг облигаций ООО «ИСК «Петроинжиниринг» серии 001Р-01 в связи с их полным погашением', 2);
$current(21, 'expert_ra', 'ruA', 'stable', '2026-05-20', 'snapshot');
// 22: кроме ложного отзыва новостей нет.
$action(22, '2026-09-10', 'отозван', null, '«Эксперт РА» отозвал кредитный рейтинг облигаций ООО «Х» серии 01 в связи с их полным погашением');
$current(22, 'expert_ra', 'отозван', null, '2026-09-10', 'action');
// 23 Селектел: отзыв облигаций по договору — настоящий, не трогаем.
$action(23, '2026-09-23', 'отозван', null, '«Эксперт РА» отозвал без подтверждения кредитные рейтинги облигаций АО «Селектел» серий 001P-05R, 001Р-06R, 001Р-07R');
$current(23, 'expert_ra', 'отозван', null, '2026-09-23', 'action');
// 24 Автодор: два ложных отзыва подряд, текущий — последний из них.
$action(24, '2026-04-28', 'ruAA+', 'stable', '«Эксперт РА» подтвердил кредитный рейтинг Государственной компании «Российские автомобильные дороги» на уровне ruAA+');
$action(24, '2026-08-26', 'ruAA+', null, '«Эксперт РА» присвоил кредитный рейтинг облигациям Государственной компании «Российские автомобильные дороги» серии БО-005Р-17 на уровне ruAA+');
$action(24, '2026-09-10', 'отозван', null, '«Эксперт РА» отозвал без подтверждения кредитный рейтинг облигаций Государственной компании «Российские автомобильные дороги» серии БО-004P-01 в связи с их полным погашением');
$action(24, '2026-09-21', 'отозван', null, '«Эксперт РА» отозвал без подтверждения кредитный рейтинг облигаций Государственной компании «Российские автомобильные дороги» серии БО-005P-04 в связи с их полным погашением');
$current(24, 'expert_ra', 'отозван', null, '2026-09-21', 'action');
// Другое агентство у той же компании — не трогаем.
$current(20, 'nkr', 'AAA.ru', 'stable', '2026-07-01', 'snapshot');

$cleanup = new BondRedemptionCleanup($db);
$plan = $cleanup->plan();

echo "--- plan(): что найдено ---\n";
check('ложных строк: СОПФ, ПетроИнжиниринг, «Только отзыв», два у Автодора', 5, count($plan['actions']));
$pairs = [];
foreach ($plan['pairs'] as $p) {
    $pairs[$p['issuer_id']] = $p;
}
check('пары: 20, 21, 22, 24 (Селектел — настоящий отзыв, не в плане)', [20, 21, 22, 24], array_keys($pairs));
check('СОПФ: активное заражение, замена — 22.09 ruAAA (ожидаемый рейтинг 25.09 пропущен)', [true, 'ruAAA', '2026-09-22'], [$pairs[20]['active'], $pairs[20]['replacement']['rating'] ?? null, $pairs[20]['replacement']['action_date'] ?? null]);
check('ПетроИнжиниринг: текущий из снимка — не активное, замены нет', [false, null], [$pairs[21]['active'], $pairs[21]['replacement']]);
check('«Только отзыв»: активное, замены нет', [true, null], [$pairs[22]['active'], $pairs[22]['replacement']]);
check('Автодор: активное, замена — 26.08 ruAA+ (оба ложных отзыва пропущены)', [true, 'ruAA+', '2026-08-26'], [$pairs[24]['active'], $pairs[24]['replacement']['rating'] ?? null, $pairs[24]['replacement']['action_date'] ?? null]);
check('plan() ничего не пишет (в истории по-прежнему 10 строк)', 10, (int) $db->query('SELECT COUNT(*) FROM rating_actions')->fetchColumn());

echo "--- apply() ---\n";
$stats = $cleanup->apply($plan);
check('удалено 5 строк истории и 2 события, ошибок нет', [5, 2, []], [$stats['deleted_actions'], $stats['deleted_events'], $stats['errors']]);
check('пересчитано 2 (СОПФ, Автодор), удалена 1, не тронута 1', [2, 1, 1], [$stats['recomputed'], $stats['removed'], $stats['untouched']]);
$row = static fn (int $id, string $agency = 'expert_ra'): array|false => $db->query("SELECT rating, outlook, last_action_date, source FROM current_ratings WHERE issuer_id = {$id} AND agency = '{$agency}'")->fetch();
check('СОПФ: ruAAA от 22.09, прогноз пустой, источник — новость', ['rating' => 'ruAAA', 'outlook' => null, 'last_action_date' => '2026-09-22', 'source' => 'action'], $row(20));
check('ПетроИнжиниринг: значение из снимка не затёрто', ['rating' => 'ruA', 'outlook' => 'stable', 'last_action_date' => '2026-05-20', 'source' => 'snapshot'], $row(21));
check('«Только отзыв»: строка current_ratings удалена', false, $row(22));
check('Селектел: настоящий отзыв остался', 'отозван', $row(23)['rating']);
check('Автодор: ruAA+ от 26.08', ['ruAA+', '2026-08-26'], [$row(24)['rating'], $row(24)['last_action_date']]);
check('другое агентство (НКР у СОПФ) не тронуто', 'AAA.ru', $row(20, 'nkr')['rating']);
check('события ложных отзывов удалены', 0, (int) $db->query('SELECT COUNT(*) FROM events')->fetchColumn());

echo "--- повторный запуск ---\n";
check('план пустой — идемпотентно', [[], []], array_values($cleanup->plan()));

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
