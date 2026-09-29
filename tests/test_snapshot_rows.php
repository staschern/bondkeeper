<?php

declare(strict_types=1);

/**
 * Офлайн-проверка SnapshotRows (сентябрь 2026):
 *   - П1: одна строка снимка на эмитента — побеждает самая свежая дата
 *     (живые случаи Эксперт РА: АФК Система — архивный "отозван" 2017
 *     года в "нефинансовых" и действующий рейтинг в "холдинговых";
 *     ТРАНСФИН-М — старая дата победила новую из-за порядка категорий);
 *   - П2: снимок сохраняется в файл на сверке и применяется перезаписью
 *     без повторного скачивания;
 *   - П3: перезапись пишет source='snapshot' и сбрасывает старый флаг
 *     matched_by_root_name.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_snapshot_rows.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\SnapshotRows;

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

function row(int $issuerId, string $rating, string $date, string $name = 'X'): array
{
    return ['issuer_id' => $issuerId, 'issuer_name' => $name, 'rating' => $rating, 'outlook' => null, 'last_action_date' => $date, 'source_url' => null];
}

/** @param array<int, array<string, mixed>> $rows */
function byIssuer(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[$r['issuer_id']] = [$r['rating'], $r['last_action_date']];
    }
    ksort($out);
    return $out;
}

echo "--- П1: latestPerIssuer() ---\n";

$afkArchive = row(78, 'отозван', '2017-07-26', 'АФК Система (нефинансовые, архив)');
$afkActive = row(78, 'ruA+', '2026-06-30', 'АФК Система (холдинговые)');
check('АФК Система: архивный "отозван" раньше действующего — побеждает действующий', [78 => ['ruA+', '2026-06-30']], byIssuer(SnapshotRows::latestPerIssuer([$afkArchive, $afkActive])));
check('АФК Система: обратный порядок категорий — результат тот же', [78 => ['ruA+', '2026-06-30']], byIssuer(SnapshotRows::latestPerIssuer([$afkActive, $afkArchive])));

check(
    'ТРАНСФИН-М: новая дата побеждает старую независимо от порядка',
    [75 => ['ruA', '2026-03-18']],
    byIssuer(SnapshotRows::latestPerIssuer([row(75, 'ruA', '2026-03-18'), row(75, 'ruA', '2025-05-05')]))
);
check(
    'Отзыв — самое последнее событие: остаётся "отозван"',
    [1772 => ['отозван', '2026-08-01']],
    byIssuer(SnapshotRows::latestPerIssuer([row(1772, 'ruA-', '2026-06-30'), row(1772, 'отозван', '2026-08-01')]))
);
check(
    'Равная дата: побеждает строка не "отозван"',
    [5 => ['ruBBB', '2026-01-01']],
    byIssuer(SnapshotRows::latestPerIssuer([row(5, 'ruBBB', '2026-01-01'), row(5, 'отозван', '2026-01-01')]))
);
check(
    'Разные эмитенты не смешиваются',
    [1 => ['A', '2026-01-01'], 2 => ['B', '2026-02-01']],
    byIssuer(SnapshotRows::latestPerIssuer([row(1, 'A', '2026-01-01'), row(2, 'B', '2026-02-01')]))
);
check('Результат — список (0..n-1)', true, array_is_list(SnapshotRows::latestPerIssuer([row(10, 'A', '2026-01-01'), row(3, 'B', '2026-01-01')])));

echo "\n--- П3: apply() — source='snapshot', флаг matched_by_root_name сброшен ---\n";

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec(
    'CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, outlook TEXT, last_action_date TEXT,
        matched_by_root_name INTEGER DEFAULT 0, source TEXT, PRIMARY KEY (issuer_id, agency))'
);
$db->exec("INSERT INTO current_ratings VALUES (1306, 'nkr', 'A-.ru', NULL, '2026-09-02', 0, 'manual')");
$db->exec("INSERT INTO current_ratings VALUES (1561, 'nkr', 'A.ru', 'stable', '2025-11-14', 1, NULL)");
$db->exec("INSERT INTO current_ratings VALUES (1306, 'expert_ra', 'ruA-', NULL, '2026-01-01', 0, 'action')");

$written = SnapshotRows::apply($db, 'nkr', [
    ['issuer_id' => 1306, 'issuer_name' => 'ООО «ПК «Борец»', 'rating' => 'A.ru', 'outlook' => 'stable', 'last_action_date' => '2026-08-20', 'source_url' => null],
    ['issuer_id' => 1561, 'issuer_name' => 'ООО «Аэрофьюэлз Групп»', 'rating' => 'A.ru', 'outlook' => 'stable', 'last_action_date' => '2025-11-14', 'source_url' => null],
    ['issuer_id' => 62, 'issuer_name' => 'ПАО «МТС»', 'rating' => 'AAA.ru', 'outlook' => 'stable', 'last_action_date' => '2026-07-01', 'source_url' => null],
]);
check('записано 3 строки', 3, $written);
$rows = $db->query("SELECT issuer_id, rating, outlook, last_action_date, matched_by_root_name, source FROM current_ratings WHERE agency = 'nkr' ORDER BY issuer_id")->fetchAll();
check('новая строка (62) вставлена с source=snapshot', ['62', 'AAA.ru', 'snapshot'], [(string) $rows[0]['issuer_id'], $rows[0]['rating'], $rows[0]['source']]);
check('ручная строка (1306) перезаписана снимком', ['A.ru', 'stable', '2026-08-20', 'snapshot'], [$rows[1]['rating'], $rows[1]['outlook'], $rows[1]['last_action_date'], $rows[1]['source']]);
check('старый флаг matched_by_root_name у 1561 сброшен', ['0', 'snapshot'], [(string) $rows[2]['matched_by_root_name'], $rows[2]['source']]);
check('строка другого агентства не тронута', 'action', $db->query("SELECT source FROM current_ratings WHERE issuer_id = 1306 AND agency = 'expert_ra'")->fetchColumn());

echo "\n--- 29.09.2026: список Эксперт РА без статуса наблюдения — перезапись оставляет наш ---\n";

$db->exec("INSERT INTO current_ratings VALUES (3100, 'expert_ra', 'ruAA-', 'under_review_stable', '2026-09-16', 0, 'action')"); // ТрансКонтейнер
$db->exec("INSERT INTO current_ratings VALUES (3101, 'expert_ra', 'ruAA-', 'under_review_stable', '2026-06-18', 0, 'action')"); // у агентства новее
$db->exec("INSERT INTO current_ratings VALUES (3102, 'expert_ra', 'ruBB-', 'under_review', '2026-08-24', 0, 'action')");        // голый under_review
$db->exec("INSERT INTO current_ratings VALUES (3100, 'nkr', 'AA-.ru', 'under_review_stable', '2026-09-16', 0, 'action')");     // НКР — статус в списке есть
$kept = null;
$written = SnapshotRows::apply($db, 'expert_ra', [
    ['issuer_id' => 3100, 'issuer_name' => 'ПАО "ТРАНСКОНТЕЙНЕР"', 'rating' => 'ruAA-', 'outlook' => 'stable', 'last_action_date' => '2026-09-16', 'source_url' => null],
    ['issuer_id' => 3101, 'issuer_name' => 'ООО "ДЕЛОПОРТС"', 'rating' => 'ruAA-', 'outlook' => 'stable', 'last_action_date' => '2026-09-16', 'source_url' => null],
    ['issuer_id' => 3102, 'issuer_name' => 'ООО "КОНТРОЛ ЛИЗИНГ"', 'rating' => 'ruBB-', 'outlook' => 'developing', 'last_action_date' => '2026-08-24', 'source_url' => null],
], $kept);
check('записана только строка, где у агентства дата новее', 1, $written);
check('оставлен наш статус у 3100 и 3102', [3100, 3102], $kept);
$outlook = static fn (int $id, string $agency): string => (string) $db->query("SELECT outlook FROM current_ratings WHERE issuer_id = {$id} AND agency = '{$agency}'")->fetchColumn();
check('3100: under_review_stable остался', 'under_review_stable', $outlook(3100, 'expert_ra'));
check('3101: дата агентства новее — взяли stable из снимка', 'stable', $outlook(3101, 'expert_ra'));
check('3102: голый under_review остался', 'under_review', $outlook(3102, 'expert_ra'));
SnapshotRows::apply($db, 'nkr', [
    ['issuer_id' => 3100, 'issuer_name' => 'Х', 'rating' => 'AA-.ru', 'outlook' => 'stable', 'last_action_date' => '2026-09-16', 'source_url' => null],
]);
check('НКР (статус в списке есть) — перезапись как раньше', 'stable', $outlook(3100, 'nkr'));

$ours = ['rating' => 'ruAA-', 'outlook' => 'under_review_stable', 'last_action_date' => '2026-09-16'];
$theirs = ['rating' => 'ruAA-', 'outlook' => 'stable', 'last_action_date' => '2026-09-16'];
check('keepsOurWatchStatus: другой рейтинг — нет', false, SnapshotRows::keepsOurWatchStatus('expert_ra', $ours, ['rating' => 'ruA+'] + $theirs));
check('keepsOurWatchStatus: другой прогноз — нет', false, SnapshotRows::keepsOurWatchStatus('expert_ra', $ours, ['outlook' => 'negative'] + $theirs));
check('keepsOurWatchStatus: у агентства прогноза нет — да', true, SnapshotRows::keepsOurWatchStatus('expert_ra', $ours, ['outlook' => null] + $theirs));
check('keepsOurWatchStatus: кириллица в нашем рейтинге — да', true, SnapshotRows::keepsOurWatchStatus('expert_ra', ['rating' => 'ruАА-'] + $ours, $theirs));
check('keepsOurWatchStatus: у нас не наблюдение — нет', false, SnapshotRows::keepsOurWatchStatus('expert_ra', ['outlook' => 'review_concluded'] + $ours, $theirs));
check('keepsOurWatchStatus: АКРА — нет (пока не проверено, есть ли статус в JSON)', false, SnapshotRows::keepsOurWatchStatus('acra', $ours, $theirs));

echo "\n--- П2: saveToFile() / loadFromFile() ---\n";

$dir = sys_get_temp_dir() . '/bondkeeper_snapshot_test_' . uniqid('', true);
$snapshot = [row(1, 'AAA.ru', '2026-07-01', 'ПАО «Роснефть»'), row(2, 'отозван', '2024-03-14', 'реСтор')];
$path = SnapshotRows::saveToFile('nkr', $snapshot, $dir);
check('файл создан, имя начинается с агентства', true, is_file($path) && str_starts_with(basename($path), 'nkr-'));
$loaded = SnapshotRows::loadFromFile($path, 'nkr');
check('строки после чтения совпадают', $snapshot, $loaded['rows']);
check('created_at записан', true, (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $loaded['created_at']));

$threw = static function (callable $fn): bool {
    try {
        $fn();
    } catch (RuntimeException) {
        return true;
    }
    return false;
};
check('снимок другого агентства — ошибка', true, $threw(static fn () => SnapshotRows::loadFromFile($path, 'expert_ra')));

$broken = $dir . '/broken.json';
file_put_contents($broken, json_encode(['agency' => 'nkr', 'created_at' => '2026-09-26 01:00:00', 'rows' => [['issuer_id' => '1', 'rating' => 'A', 'last_action_date' => '2026-01-01']]]));
check('повреждённая строка (issuer_id строкой) — ошибка', true, $threw(static fn () => SnapshotRows::loadFromFile($broken, 'nkr')));
check('несуществующий файл — ошибка', true, $threw(static fn () => SnapshotRows::loadFromFile($dir . '/nope.json', 'nkr')));

array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
