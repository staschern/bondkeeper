<?php

declare(strict_types=1);

/**
 * Проверки «от НРД нет сообщения о деньгах» (Этап 5,
 * docs/STAGE5_PAYMENTS.md). Нужны для случая, когда выплаты НЕ было: тогда
 * от НРД просто ничего не приходит, и реагировать не на что.
 *
 * Схема (решение пользователя, 05.10.2026 — вместо прежних «19:00 дня
 * выплаты и утро следующего дня»):
 *
 *   --at=noon (12:02 мск, СЛЕДУЮЩИЙ рабочий день после дня выплаты):
 *     по вчерашним выплатам нет сообщения о деньгах → жёлтое событие B2a
 *     «НРД пока не сообщил о поступлении денег».
 *   --at=evening (19:00 мск, тот же день):
 *     от НРД нет вообще ничего — ни денег, ни объявления о неисполнении →
 *     это странно, возможно сбой: событие B2b и сообщение администратору
 *     (клиентам не показывается).
 *
 * Почему так: НРД сообщает о деньгах утром следующего рабочего дня после
 * их поступления. По тестовой выгрузке (982 выплаты) к 19:00 дня выплаты
 * НРД сообщил только о 74 % выплат, к 12:00 следующего дня — о 99 %.
 * Объявление о неисполнении выходит на следующий день, чаще в 17:20–17:35.
 * Проверка в полдень должна идти сразу ПОСЛЕ опроса GetNews (он в 12:00).
 *
 * «Нет сообщения» = выплата в графике в статусе planned. В нерабочий день
 * скрипт ничего не делает; «сегодня» — по московскому времени.
 *
 * Режим — config/payments.php, ключ 'checks':
 *   off     — выключено (по умолчанию; так и должно быть, пока опрос
 *             GetNews не включён — иначе тревога по каждой выплате);
 *   admin   — списки только администратору, событий для клиентов нет;
 *   clients — жёлтые уведомления клиентам; администратору — только
 *             случаи «от НРД нет вообще ничего».
 *
 *   php bin/payment_checks.php --at=noon
 *   php bin/payment_checks.php --at=evening
 *   php bin/payment_checks.php --at=noon --dry-run          # показать списки, ничего не создавая и не отправляя
 *   php bin/payment_checks.php --at=noon --date=2026-10-15  # считать «сегодня» этой датой
 *
 * По расписанию. Если сервер живёт по UTC (12:02 и 19:00 мск):
 *   2 9  * * 1-5 /usr/bin/php /path/to/bondkeeper/bin/payment_checks.php --at=noon    >> /var/log/bondkeeper/payment_checks.log 2>&1
 *   0 16 * * 1-5 /usr/bin/php /path/to/bondkeeper/bin/payment_checks.php --at=evening >> /var/log/bondkeeper/payment_checks.log 2>&1
 * (рабочая суббота в крон «1-5» не попадает — такие дни редки, 20.02.2027;
 * при желании поставить «* * *»: в нерабочий день скрипт сам ничего не
 * делает.) Повторный запуск за тот же день событий не дублирует.
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
    if (preg_match('/^--at=(noon|evening)$/', $arg, $m)) {
        $at = $m[1];
    }
}
if ($at === null) {
    fwrite(STDERR, "Использование: php bin/payment_checks.php --at=noon|evening [--dry-run] [--date=ГГГГ-ММ-ДД]\n"
        . "(--at=morning больше нет: проверка одна, в полдень следующего рабочего дня)\n");
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
$prefix = $dryRun ? '=== ПРОСМОТР (ничего не создано и не отправлено) === ' : '';
$dueDay = $calendar->previousWorkingDay($today);

if ($at === 'noon') {
    $toClients = $config->checks === PaymentsConfig::CHECKS_CLIENTS && !$dryRun;
    $noReceipt = $watch->checkNoReceipt($today, $toClients);
    $title = "Проверка {$today}: по выплатам за {$dueDay} НРД не сообщил о поступлении денег";
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
    exit(0);
}

$silence = $watch->checkSilence($today, !$dryRun);
$silenceLines = PaymentWatch::adminLines($silence['items']);
Logger::info($prefix . "Нет вообще никаких сообщений НРД по выплатам за {$dueDay} — бумаг: " . count($silence['items']));
foreach ($silenceLines as $line) {
    Logger::info($line);
}
if (!$dryRun && $silenceLines !== []) {
    AdminNotifier::sendLines(array_merge(
        ["⚠️ По выплатам за {$dueDay} от НРД нет никаких сообщений — ни о получении денег, ни о неисполнении (" . count($silenceLines) . '). Возможен технический сбой, нужна проверка:'],
        $silenceLines,
    ));
}
