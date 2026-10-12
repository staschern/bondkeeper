<?php

declare(strict_types=1);

/**
 * Офлайн-проверка статусов строк графика, которые ставятся не по
 * сообщению о конкретной выплате (BondKeeper\Payments\ScheduleStatuses,
 * docs/STAGE5_PAYMENTS.md, раздел «Статусы выплат»):
 *   - «дата прошла, сообщения НРД нет» → unconfirmed, и когда именно;
 *   - остаток графика после досрочного погашения → cancelled;
 *   - строка планового погашения с нулём — зеркало последней амортизации.
 * Календарь рабочих дней — настоящий (config/working_calendar.php).
 *
 * Запуск (из корня репозитория):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_schedule_statuses.php
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Payments\ScheduleStatuses;
use BondKeeper\Payments\WorkingCalendar;

$failures = 0;
$checks = 0;

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected === $actual) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n       ожидалось: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n       получено:  " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE securities (id INTEGER PRIMARY KEY, short_name TEXT, status TEXT DEFAULT 'active', status_reason TEXT, ended_at TEXT, maturity_date TEXT)");
$row = "id INTEGER PRIMARY KEY AUTOINCREMENT, security_id INTEGER, %s TEXT, value_per_bond TEXT, actual_value_per_bond TEXT, full_default_date_planned TEXT, status TEXT DEFAULT 'planned'";
$db->exec('CREATE TABLE coupons (' . sprintf($row, 'period_end_date') . ')');
$db->exec('CREATE TABLE amortizations (' . sprintf($row, 'payment_date_planned') . ')');
$db->exec('CREATE TABLE redemptions (' . sprintf($row, 'payment_date_planned') . ", redemption_type TEXT DEFAULT 'scheduled_maturity')");

$schedule = new ScheduleStatuses($db, WorkingCalendar::fromFile(dirname(__DIR__) . '/config/working_calendar.php'));
$add = static function (string $table, int $securityId, string $date, string $status = 'planned', string $value = '10.0000', ?string $type = null) use ($db): void {
    $column = $table === 'coupons' ? 'period_end_date' : 'payment_date_planned';
    $db->prepare("INSERT INTO {$table} (security_id, {$column}, value_per_bond, status" . ($type !== null ? ', redemption_type' : '') . ') VALUES (?, ?, ?, ?' . ($type !== null ? ', ?' : '') . ')')
        ->execute(array_merge([$securityId, $date, $value, $status], $type !== null ? [$type] : []));
};
/** @return array<string, string> дата → статус */
$statuses = static function (string $table, int $securityId) use ($db): array {
    $column = $table === 'coupons' ? 'period_end_date' : 'payment_date_planned';

    return $db->query("SELECT {$column}, status FROM {$table} WHERE security_id = {$securityId} ORDER BY {$column}, id")->fetchAll(PDO::FETCH_KEY_PAIR);
};

// =====================================================================
echo "--- дата прошла, сообщения НРД нет → «без подтверждения» ---\n";
$db->exec("INSERT INTO securities (id, short_name) VALUES (1, 'Обычная')");
foreach (['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-12', '2026-10-13', '2026-10-20'] as $date) { // 10.10 — суббота
    $add('coupons', 1, $date);
}
$add('coupons', 1, '2026-09-01', 'paid');
$add('coupons', 1, '2026-09-08', 'tech_default');
$add('amortizations', 1, '2026-10-09');
$add('redemptions', 1, '2026-10-09', 'planned', '0.0000');

$preview = $schedule->markUnconfirmed('2026-10-14', false);
check('просмотр считает, но ничего не меняет', [['coupons' => 4, 'amortizations' => 1, 'redemptions' => 1], 6],
    [$preview, (int) $db->query("SELECT COUNT(*) FROM coupons WHERE status = 'planned'")->fetchColumn()]);

$schedule->markUnconfirmed('2026-10-12');
check('понедельник 12.10: пятничная выплата (09.10) ещё planned — её проверки идут сегодня; четверговая (08.10) — уже «без подтверждения»',
    ['2026-10-08' => 'unconfirmed', '2026-10-09' => 'planned'], array_intersect_key($statuses('coupons', 1), ['2026-10-08' => 1, '2026-10-09' => 1]));
$schedule->markUnconfirmed('2026-10-13');
check('вторник 13.10: пятничная — «без подтверждения»; субботняя (исполнение в понедельник, проверки сегодня) — ещё planned',
    ['2026-10-09' => 'unconfirmed', '2026-10-10' => 'planned', '2026-10-12' => 'planned'],
    array_intersect_key($statuses('coupons', 1), ['2026-10-09' => 1, '2026-10-10' => 1, '2026-10-12' => 1]));
$counts = $schedule->markUnconfirmed('2026-10-14');
check('среда 14.10: субботняя и понедельничная — «без подтверждения»; вторничная и будущая — planned',
    ['2026-10-10' => 'unconfirmed', '2026-10-12' => 'unconfirmed', '2026-10-13' => 'planned', '2026-10-20' => 'planned'],
    array_intersect_key($statuses('coupons', 1), ['2026-10-10' => 1, '2026-10-12' => 1, '2026-10-13' => 1, '2026-10-20' => 1]));
check('в этот запуск изменились только две строки', ['coupons' => 2, 'amortizations' => 0, 'redemptions' => 0], $counts);
check('выплаченные и просроченные строки не трогаются', ['2026-09-01' => 'paid', '2026-09-08' => 'tech_default'],
    array_intersect_key($statuses('coupons', 1), ['2026-09-01' => 1, '2026-09-08' => 1]));
check('амортизации и погашения — по тому же правилу', ['unconfirmed', 'unconfirmed'], [$statuses('amortizations', 1)['2026-10-09'], $statuses('redemptions', 1)['2026-10-09']]);
check('повторный запуск ничего не меняет', ['coupons' => 0, 'amortizations' => 0, 'redemptions' => 0], $schedule->markUnconfirmed('2026-10-14'));

// =====================================================================
echo "\n--- выпуск погашен досрочно → остаток графика отменён ---\n";
// Бумага 2: досрочное погашение по записи биржи 20.08.2026 (четверг), срок был 20.08.2028.
$db->exec("INSERT INTO securities (id, short_name, status, status_reason, ended_at, maturity_date) VALUES (2, 'Досрочно по бирже', 'matured', 'early_redemption_moex', '2026-08-20', '2028-08-20')");
$add('coupons', 2, '2026-05-20', 'paid');
$add('coupons', 2, '2026-08-20', 'unconfirmed');
$add('coupons', 2, '2026-09-20', 'unconfirmed');
$add('coupons', 2, '2026-11-20');
$add('amortizations', 2, '2026-08-20', 'unconfirmed');
$add('amortizations', 2, '2028-08-20', 'planned', '500.0000');
$add('redemptions', 2, '2028-08-20', 'planned', '0.0000');
// Бумага 3: погашена по сроку 23.09.2026 — последняя амортизация и погашение стоят в день окончания выпуска.
$db->exec("INSERT INTO securities (id, short_name, status, status_reason, ended_at, maturity_date) VALUES (3, 'По сроку', 'matured', 'scheduled_maturity', '2026-09-23', '2026-09-23')");
$add('coupons', 3, '2026-09-23', 'unconfirmed');
$add('amortizations', 3, '2026-09-23', 'unconfirmed', '1000.0000');
$add('redemptions', 3, '2026-09-23', 'unconfirmed', '0.0000');
// Бумага 4: ушла с биржи, причина не установлена.
$db->exec("INSERT INTO securities (id, short_name, status, status_reason, ended_at, maturity_date) VALUES (4, 'Снята с торгов', 'delisted', 'left_exchange_unknown', '2026-09-15', '2027-12-15')");
$add('coupons', 4, '2026-12-15');
// Бумага 5: досрочное погашение по сообщению НРД 28.09.2026, деньги пришли на день позже; в графике на 26.09 (суббота) — купон и амортизация.
$db->exec("INSERT INTO securities (id, short_name, status, status_reason, ended_at, maturity_date) VALUES (5, 'Досрочно по НРД', 'matured', 'early_redemption_nsd', '2026-09-29', '2030-03-26')");
$add('redemptions', 5, '2026-09-28', 'paid', '98.7700', 'early_issuer_call');
$add('coupons', 5, '2026-09-26', 'unconfirmed');
$add('coupons', 5, '2026-09-29', 'unconfirmed');
$add('amortizations', 5, '2026-09-26', 'unconfirmed');
$add('redemptions', 5, '2030-03-26', 'planned', '0.0000');

$preview = $schedule->cancelTails(false);
check('просмотр: две бумаги, семь строк; ничего не изменено', [['bonds' => 2, 'coupons' => 3, 'amortizations' => 3, 'redemptions' => 2], 0],
    [$preview, (int) $db->query("SELECT (SELECT COUNT(*) FROM coupons WHERE status = 'cancelled') + (SELECT COUNT(*) FROM amortizations WHERE status = 'cancelled')")->fetchColumn()]);
$done = $schedule->cancelTails();
check('запуск отменяет то же, что показал просмотр', $preview, $done);
check('купон в день досрочного погашения остаётся (он выплачивается вместе с ним), более поздние отменены; выплаченное не трогается',
    ['2026-05-20' => 'paid', '2026-08-20' => 'unconfirmed', '2026-09-20' => 'cancelled', '2026-11-20' => 'cancelled'], $statuses('coupons', 2));
check('амортизации со дня погашения и плановое погашение отменены', [['2026-08-20' => 'cancelled', '2028-08-20' => 'cancelled'], ['2028-08-20' => 'cancelled']],
    [$statuses('amortizations', 2), $statuses('redemptions', 2)]);
check('бумага, погашенная по сроку: последние выплаты в день окончания выпуска не отменяются', ['unconfirmed', 'unconfirmed', 'unconfirmed'],
    [$statuses('coupons', 3)['2026-09-23'], $statuses('amortizations', 3)['2026-09-23'], $statuses('redemptions', 3)['2026-09-23']]);
check('бумага, снятая с торгов по неизвестной причине: график не отменяется — выплаты могут продолжаться', ['2026-12-15' => 'planned'], $statuses('coupons', 4));
check('дата берётся из строки досрочного погашения (28.09), а не из дня поступления денег: субботний купон с исполнением 28.09 остаётся, купон 29.09 отменён',
    ['2026-09-26' => 'unconfirmed', '2026-09-29' => 'cancelled'], $statuses('coupons', 5));
check('амортизация с исполнением в день погашения отменена; строка самого досрочного погашения не тронута',
    [['2026-09-26' => 'cancelled'], ['2026-09-28' => 'paid', '2030-03-26' => 'cancelled']], [$statuses('amortizations', 5), $statuses('redemptions', 5)]);
check('повторный запуск ничего не меняет', ['bonds' => 0, 'coupons' => 0, 'amortizations' => 0, 'redemptions' => 0], $schedule->cancelTails());

// =====================================================================
echo "\n--- строка планового погашения с нулём — зеркало последней амортизации ---\n";
$db->exec("INSERT INTO securities (id, short_name) VALUES (6, 'Выплачено'), (7, 'Не выплачено'), (8, 'С суммой в погашении'), (9, 'Впереди')");
$add('amortizations', 6, '2026-09-23', 'paid', '1000.0000');
$add('redemptions', 6, '2026-09-23', 'unconfirmed', '0.0000');
$add('amortizations', 7, '2026-09-08', 'default', '1000.0000');
$db->exec("UPDATE amortizations SET full_default_date_planned = '2026-09-22' WHERE security_id = 7");
$add('redemptions', 7, '2026-09-08', 'planned', '0.0000');
$add('amortizations', 8, '2026-09-23', 'paid', '300.0000');
$add('redemptions', 8, '2026-09-23', 'planned', '700.0000');
$add('amortizations', 9, '2027-09-23', 'planned', '1000.0000');
$add('redemptions', 9, '2027-09-23', 'planned', '0.0000');
$redemption = static fn (int $securityId): array => array_values($db->query(
    "SELECT status, actual_value_per_bond, full_default_date_planned FROM redemptions WHERE security_id = {$securityId} AND redemption_type = 'scheduled_maturity'"
)->fetch());

check('просмотр: две строки, ничего не изменено', [2, 'unconfirmed'], [$schedule->mirrorFinalAmortization(null, false), $redemption(6)[0]]);
check('по одной бумаге — только её строка', [1, 'planned'], [$schedule->mirrorFinalAmortization(6), $redemption(7)[0]]);
check('номинал выплачен строкой амортизации → погашение «выплачено», факт по этой строке — 0 (номинал не считается дважды)', ['paid', '0.0000', null], $redemption(6));
$schedule->mirrorFinalAmortization();
check('дефолт по последней амортизации → тот же статус и срок у строки погашения, факта нет', ['default', null, '2026-09-22'], $redemption(7));
check('строка погашения со своей суммой не трогается — деньги по ней приходят отдельно', ['planned', null, null], $redemption(8));
check('выплата ещё впереди — не трогается', ['planned', null, null], $redemption(9));
check('повторный запуск ничего не меняет', 0, $schedule->mirrorFinalAmortization());

check('в SQLite список значений статуса не ограничен — проверка структуры замечаний не даёт', null, ScheduleStatuses::schemaProblem($db));

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
