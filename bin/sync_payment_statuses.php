<?php

declare(strict_types=1);

/**
 * Ежедневное приведение статусов строк графика выплат в порядок
 * (docs/STAGE5_PAYMENTS.md, раздел «Статусы выплат»). Что делает — см.
 * BondKeeper\Payments\ScheduleStatuses:
 *
 *   1. у бумаг, погашенных досрочно, отменяет остаток графика: купоны
 *      после даты погашения, амортизации и плановое погашение → cancelled;
 *   2. строку планового погашения с нулевой суммой делает зеркалом
 *      последней амортизации (она и есть выплата номинала);
 *   3. выплаты, дата которых прошла, а сообщения НРД нет, переводит из
 *      planned в unconfirmed.
 *
 * От опроса НРД и его настроек не зависит. Пока опрос выключен, все
 * прошедшие выплаты будут получать unconfirmed — это и есть правда: дата
 * прошла, подтверждения нет.
 *
 *   php bin/sync_payment_statuses.php                    # обычный запуск
 *   php bin/sync_payment_statuses.php --dry-run          # показать, сколько строк изменится, ничего не меняя
 *   php bin/sync_payment_statuses.php --date=2026-10-12  # считать «сегодня» этой датой
 *
 * По расписанию — раз в сутки, после сверки статусов бумаг (ей нужен
 * свежий статус «погашена досрочно»):
 *   55 2 * * * /usr/bin/php /path/to/bondkeeper/bin/sync_payment_statuses.php >> /path/to/bondkeeper/var/log/sync_payment_statuses.log 2>&1
 *
 * Первый запуск меняет много строк: все прошедшие выплаты за все годы,
 * кроме уже подтверждённых НРД, получают unconfirmed. Дальше — десятки
 * строк в день. Если скрипт упал — сообщение разработчику (CronGuard).
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Payments\ScheduleStatuses;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Support\CronGuard;
use BondKeeper\Support\Logger;

$dryRun = in_array('--dry-run', $argv, true);
CronGuard::install('sync_payment_statuses', !$dryRun);

$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
foreach ($argv as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $today = $m[1];
    }
}

$db = Database::connection();
// Без новых значений статуса (миграция 031) база либо отвергнет запись, либо молча запишет пустую строку — проверяем заранее.
$problem = ScheduleStatuses::schemaProblem($db);
if ($problem !== null) {
    throw new RuntimeException($problem);
}

$calendar = WorkingCalendar::fromFile(__DIR__ . '/../config/working_calendar.php');
$schedule = new ScheduleStatuses($db, $calendar);
$verb = $dryRun ? 'будет' : '';
Logger::info(($dryRun ? '=== ПРОСМОТР (ничего не меняется) === ' : '') . "Статусы выплат на {$today}");

// Три шага зависят друг от друга (отменённая строка уже не станет «без подтверждения»), поэтому просмотр —
// это тот же запуск, только с откатом в конце: числа в нём точные, а в базе ничего не остаётся.
$db->beginTransaction();
try {
    $tails = $schedule->cancelTails();
    $mirrored = $schedule->mirrorFinalAmortization();
    $unconfirmed = $schedule->markUnconfirmed($today);
    $dryRun ? $db->rollBack() : $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    throw $e;
}

Logger::info(trim("Отменено после досрочного погашения {$verb}") . ": бумаг {$tails['bonds']} — купонов {$tails['coupons']}, амортизаций {$tails['amortizations']}, плановых погашений {$tails['redemptions']}");
Logger::info(trim("Строк планового погашения приведено к статусу последней амортизации {$verb}") . ": {$mirrored}");
Logger::info(trim("Дата прошла, сообщения НРД нет — статус «без подтверждения» {$verb}") . ": купонов {$unconfirmed['coupons']}, амортизаций {$unconfirmed['amortizations']}, погашений {$unconfirmed['redemptions']}");
Logger::info($dryRun ? 'Ничего не изменено (--dry-run).' : 'Готово.');
