<?php

declare(strict_types=1);

/**
 * Проверки «сообщения о получении денег нет» (Этап 5,
 * docs/STAGE5_PAYMENTS.md). Нужны для случая, когда выплаты НЕ было: тогда
 * от НРД в день выплаты просто ничего не приходит, и реагировать не на
 * что. Схема согласована с пользователем 04.10.2026:
 *
 *   --at=evening (19:00 мск, после крайнего срока перевода в 17:00):
 *     1) по выплатам с днём исполнения СЕГОДНЯ нет «получено НРД» →
 *        жёлтое событие B2a «деньги от эмитента пока не поступили»;
 *     2) по выплатам с днём исполнения в ПРЕДЫДУЩИЙ рабочий день от НРД
 *        нет вообще ничего → это странно, возможно сбой: событие B2b и
 *        сообщение администратору (клиентам не показывается).
 *   --at=morning (10:00 мск следующего рабочего дня):
 *     по вчерашним выплатам «получено НРД» всё ещё нет → B2a повторно.
 *
 * «Нет сообщения» = выплата в графике в статусе planned. В нерабочий день
 * скрипт ничего не делает; «сегодня» — по московскому времени.
 *
 * Режим — config/payments.php, ключ 'checks':
 *   off     — выключено (по умолчанию; так и должно быть, пока сообщения
 *             НРД не поступают в базу — иначе тревога по каждой выплате);
 *   admin   — списки только администратору, событий для клиентов нет;
 *   clients — жёлтые уведомления клиентам; администратору — только
 *             случаи «от НРД нет вообще ничего».
 *
 *   php bin/payment_checks.php --at=evening
 *   php bin/payment_checks.php --at=morning
 *   php bin/payment_checks.php --at=evening --dry-run          # показать списки, ничего не создавая и не отправляя
 *   php bin/payment_checks.php --at=evening --date=2026-10-15  # считать «сегодня» этой датой
 *
 * По расписанию. Если сервер живёт по UTC (10:00 и 19:00 мск):
 *   0 7  * * * /usr/bin/php /path/to/bondkeeper/bin/payment_checks.php --at=morning >> /var/log/bondkeeper/payment_checks.log 2>&1
 *   0 16 * * * /usr/bin/php /path/to/bondkeeper/bin/payment_checks.php --at=evening >> /var/log/bondkeeper/payment_checks.log 2>&1
 * Повторный запуск за тот же день событий не дублирует.
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
$at = null;
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
foreach ($argv as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $today = $m[1];
    }
    if (preg_match('/^--at=(evening|morning)$/', $arg, $m)) {
        $at = $m[1];
    }
}
if ($at === null) {
    fwrite(STDERR, "Использование: php bin/payment_checks.php --at=evening|morning [--dry-run] [--date=ГГГГ-ММ-ДД]\n");
    exit(1);
}

$config = PaymentsConfig::fromFile(__DIR__ . '/../config/payments.php');
if ($config->checks === PaymentsConfig::CHECKS_OFF && !$dryRun) {
    Logger::info('Проверки выплат выключены (config/payments.php: checks). Ничего не делаю.');
    exit(0);
}

$calendar = WorkingCalendar::fromFile(__DIR__ . '/../config/working_calendar.php');
if (!$calendar->isWorkingDay($today)) {
    Logger::info("{$today} — нерабочий день, проверки выплат не выполняются.");
    exit(0);
}

$db = Database::connection();
$watch = new PaymentWatch($db, new EventPublisher($db), $calendar);
$toClients = $config->checks === PaymentsConfig::CHECKS_CLIENTS && !$dryRun;
$prefix = $dryRun ? '=== ПРОСМОТР (ничего не создано и не отправлено) === ' : '';

$check = $at === 'morning' ? PaymentWatch::CHECK_MORNING : PaymentWatch::CHECK_EVENING;
$noReceipt = $watch->checkNoReceipt($today, $check, $toClients);
$title = $at === 'morning'
    ? "Утренняя проверка {$today}: по вчерашним выплатам сообщения «получено НРД» всё ещё нет"
    : "Вечерняя проверка {$today}: по сегодняшним выплатам сообщения «получено НРД» нет";
$lines = PaymentWatch::adminLines($noReceipt['items']);

Logger::info($prefix . $title . ' — бумаг: ' . count($noReceipt['items']));
foreach ($lines as $line) {
    Logger::info($line);
}
if ($toClients) {
    Logger::info("Создано жёлтых уведомлений клиентам: {$noReceipt['created']}; уже были созданы раньше: {$noReceipt['existing']}");
} elseif (!$dryRun && $lines !== []) {
    // Режим admin: клиентам ничего, список — администратору.
    AdminNotifier::sendLines(array_merge(['🟡 ' . $title . ' (' . count($lines) . '):'], $lines));
}

if ($at === 'evening') {
    $silence = $watch->checkSilence($today, !$dryRun);
    $silenceLines = PaymentWatch::adminLines($silence['items']);
    Logger::info($prefix . 'Нет вообще никаких сообщений НРД по выплатам предыдущего рабочего дня — бумаг: ' . count($silence['items']));
    foreach ($silenceLines as $line) {
        Logger::info($line);
    }
    if (!$dryRun && $silenceLines !== []) {
        AdminNotifier::sendLines(array_merge(
            ['⚠️ По выплатам предыдущего рабочего дня от НРД нет никаких сообщений — ни о получении, ни о неисполнении (' . count($silenceLines) . '). Возможен технический сбой, нужна проверка:'],
            $silenceLines,
        ));
    }
}
