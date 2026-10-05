<?php

declare(strict_types=1);

/**
 * Напоминания о предстоящих выплатах (Этап 5, docs/STAGE5_PAYMENTS.md):
 * купон, амортизация, погашение. Создаёт событие R1 по каждой бумаге, у
 * которой выплата по графику — завтра; рассылку клиентам делает обычный
 * NotificationDispatcher (как для рейтингов и ФНС).
 *
 * Сутки считаются от даты выплаты ПО ГРАФИКУ, а не от дня, когда деньги
 * придут на деле (решение пользователя, 04.10.2026: клиент должен успеть
 * продать бумагу до выплаты). «Сегодня» — по московскому времени,
 * независимо от часового пояса сервера.
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
 * Повторный запуск за тот же день напоминаний не дублирует.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Events\EventPublisher;
use BondKeeper\Payments\PaymentsConfig;
use BondKeeper\Payments\PaymentWatch;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\AdminNotifier;

$dryRun = in_array('--dry-run', $argv, true);
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

$db = Database::connection();
$watch = new PaymentWatch($db, new EventPublisher($db), WorkingCalendar::fromFile(__DIR__ . '/../config/working_calendar.php'));

$warning = $watch->calendarGapWarning($today);
if ($warning !== null) {
    Logger::warn($warning);
    if (!$dryRun) {
        AdminNotifier::send('⚠️ ' . $warning);
    }
}

$result = $watch->remind($today, !$dryRun);

Logger::info(($dryRun ? '=== ПРОСМОТР (ничего не создано) === ' : '') . "Напоминания о выплатах на завтра, сегодня {$today} (мск)");
foreach (PaymentWatch::adminLines($result['items']) as $line) {
    Logger::info($line);
}
Logger::info('Бумаг с выплатой завтра: ' . count($result['items']) . "; создано напоминаний: {$result['created']}; уже были созданы раньше: {$result['existing']}");
