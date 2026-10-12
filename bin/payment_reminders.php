<?php

declare(strict_types=1);

/**
 * Напоминания о предстоящих выплатах (Этап 5, docs/STAGE5_PAYMENTS.md):
 * купон, амортизация, погашение. Создаёт событие R1 по каждой бумаге, для
 * которой сегодня — последний рабочий день перед датой выплаты по
 * графику; рассылку клиентам делает обычный NotificationDispatcher (как
 * для рейтингов и ФНС).
 *
 * Правило (решение пользователя, 11.10.2026; раньше — за календарные
 * сутки): клиент должен успеть продать бумагу до выплаты, а в выходной он
 * её не продаст. Поэтому дата по графику в субботу, воскресенье или
 * понедельник — напоминание в пятницу; перед праздниками — в последний
 * рабочий день. В нерабочий день скрипт ничего не создаёт. «Сегодня» — по
 * московскому времени, независимо от часового пояса сервера.
 *
 * Включается в config/payments.php ('reminders' => true); пока файла нет
 * или там false — скрипт ничего не делает.
 *
 *   php bin/payment_reminders.php                    # обычный запуск
 *   php bin/payment_reminders.php --dry-run          # показать список, ничего не создавая (работает и при выключенных напоминаниях)
 *   php bin/payment_reminders.php --date=2026-10-14  # считать «сегодня» этой датой
 *
 * По расписанию — раз в сутки в 10:00 мск. Если сервер живёт по UTC:
 *   0 7 * * * /usr/bin/php /path/to/bondkeeper/bin/payment_reminders.php >> /var/log/bondkeeper/payment_reminders.log 2>&1
 * Строку крона менять не нужно: в нерабочий день скрипт сам ничего не
 * делает. Повторный запуск за тот же день напоминаний не дублирует.
 *
 * Если скрипт упал, сообщение уходит разработчику в Telegram (CronGuard).
 * Перед работой проверяется, что в базе есть тип события R1.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Events\EventPublisher;
use BondKeeper\Payments\PaymentSchemaCheck;
use BondKeeper\Payments\PaymentsConfig;
use BondKeeper\Payments\PaymentWatch;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Support\CronGuard;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\AdminNotifier;

$dryRun = in_array('--dry-run', $argv, true);
CronGuard::install('payment_reminders', !$dryRun);

$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
foreach ($argv as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $today = $m[1];
    }
}

$config = PaymentsConfig::fromFile(__DIR__ . '/../config/payments.php');
if (!$config->reminders && !$dryRun) {
    Logger::info('Напоминания о выплатах выключены (config/payments.php: reminders). Ничего не делаю.');
    exit(0);
}

$calendar = WorkingCalendar::fromFile(__DIR__ . '/../config/working_calendar.php');
$dates = $calendar->scheduleDatesRemindedOn($today);
if ($dates === []) {
    Logger::info("{$today} — нерабочий день: напоминания уходят в последний рабочий день перед выплатой, сегодня ничего не создаётся.");
    exit(0);
}

$db = Database::connection();
$watch = new PaymentWatch($db, new EventPublisher($db), $calendar);

$warning = $watch->calendarGapWarning($today);
if ($warning !== null) {
    Logger::warn($warning);
    if (!$dryRun) {
        AdminNotifier::sendTech('⚠️ ' . $warning);
    }
}

// Без типа события R1 база не даст создать ни одного напоминания — говорим об этом прямо и до начала работы.
$missing = PaymentSchemaCheck::missingEventTypes($db, ['R1']);
if ($missing !== []) {
    if (!$dryRun) {
        throw new RuntimeException(PaymentSchemaCheck::describe($missing));
    }
    Logger::warn(PaymentSchemaCheck::describe($missing) . ' Настоящий запуск с этим упадёт.');
}

$result = $watch->remind($today, !$dryRun);

$datesText = implode(', ', array_map(static fn (string $d): string => date('d.m.Y', (int) strtotime($d)), $dates));
Logger::info(($dryRun ? '=== ПРОСМОТР (ничего не создано) === ' : '') . "Напоминания о выплатах, сегодня {$today} (мск); даты выплат по графику: {$datesText}");
foreach (PaymentWatch::adminLines($result['items']) as $line) {
    Logger::info($line);
}
Logger::info('Бумаг с выплатой: ' . count($result['items']) . "; создано напоминаний: {$result['created']}; уже были созданы раньше: {$result['existing']}");
