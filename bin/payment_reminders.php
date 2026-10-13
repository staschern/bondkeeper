<?php

declare(strict_types=1);

/**
 * Напоминания о предстоящих выплатах и офертах (docs/STAGE5_PAYMENTS.md,
 * docs/OFFERS.md). Создаёт события:
 *   R1 — выплата: купон, амортизация, погашение;
 *   R2 — оферта, с указанием её вида (12.10.2026).
 * Рассылку клиентам делает обычный NotificationDispatcher (как для
 * рейтингов и ФНС).
 *
 * Когда напоминаем. По умолчанию о выплате — в последний рабочий день
 * перед датой по графику, об оферте — за 30 календарных дней. Пользователь
 * может задать в боте свой интервал для каждого вида (раздел
 * «Настройки ⚙️»): тогда для него создаётся отдельное напоминание — за
 * столько календарных дней, сколько он указал.
 *
 * Правило для всех интервалов одно (решение пользователя, 11.10.2026):
 * клиент должен успеть продать бумагу, а в выходной он её не продаст.
 * Поэтому если день «дата минус N» нерабочий, напоминание уходит в
 * последний рабочий день перед ним; в нерабочий день скрипт ничего не
 * создаёт. Если до даты уже осталось меньше N дней, а напоминания ещё не
 * было (оферта появилась поздно, пользователь только что задал интервал),
 * оно уходит в ближайший рабочий день. «Сегодня» — по московскому времени.
 *
 * Включается в config/payments.php: 'reminders' => true — выплаты,
 * 'offer_reminders' => true — оферты. Пока файла нет или там false —
 * соответствующая часть ничего не делает.
 *
 *   php bin/payment_reminders.php                    # обычный запуск
 *   php bin/payment_reminders.php --dry-run          # показать списки, ничего не создавая (работает и при выключенных напоминаниях)
 *   php bin/payment_reminders.php --date=2026-10-14  # считать «сегодня» этой датой
 *
 * По расписанию — раз в сутки в 10:00 мск. Если сервер живёт по UTC:
 *   0 7 * * * /usr/bin/php /path/to/bondkeeper/bin/payment_reminders.php >> /var/log/bondkeeper/payment_reminders.log 2>&1
 * Строку крона менять не нужно. Повторный запуск за тот же день
 * напоминаний не дублирует.
 *
 * Если скрипт упал, сообщение уходит разработчику в Telegram (CronGuard).
 * Перед работой проверяется, что в базе есть нужные типы событий и
 * таблицы настроек (миграция 032).
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Events\EventPublisher;
use BondKeeper\Payments\OfferWatch;
use BondKeeper\Payments\PaymentSchemaCheck;
use BondKeeper\Payments\PaymentsConfig;
use BondKeeper\Payments\PaymentWatch;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Support\CronGuard;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\AdminNotifier;
use BondKeeper\Telegram\NotificationPreferences;

$dryRun = in_array('--dry-run', $argv, true);
CronGuard::install('payment_reminders', !$dryRun);

$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
foreach ($argv as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $today = $m[1];
    }
}

$config = PaymentsConfig::fromFile(__DIR__ . '/../config/payments.php');
if (!$config->reminders && !$config->offerReminders && !$dryRun) {
    Logger::info('Напоминания выключены (config/payments.php: reminders, offer_reminders). Ничего не делаю.');
    exit(0);
}

$calendar = WorkingCalendar::fromFile(__DIR__ . '/../config/working_calendar.php');
$dates = $calendar->scheduleDatesRemindedOn($today);
if ($dates === []) {
    Logger::info("{$today} — нерабочий день: напоминания уходят в последний рабочий день перед выплатой, сегодня ничего не создаётся.");
    exit(0);
}

$db = Database::connection();
$publisher = new EventPublisher($db);
$watch = new PaymentWatch($db, $publisher, $calendar);

$warning = $watch->calendarGapWarning($today);
if ($warning !== null) {
    Logger::warn($warning);
    if (!$dryRun) {
        AdminNotifier::sendTech('⚠️ ' . $warning);
    }
}

// Без типов событий и колонки events.reminder_days база не даст создать ни одного напоминания — говорим об этом прямо и до начала работы.
$problems = [];
$missing = PaymentSchemaCheck::missingEventTypes($db, array_merge($config->reminders || $dryRun ? ['R1'] : [], $config->offerReminders || $dryRun ? ['R2'] : []));
if ($missing !== []) {
    $problems[] = PaymentSchemaCheck::describe($missing);
}
$schemaProblem = NotificationPreferences::schemaProblem($db);
if ($schemaProblem !== null) {
    $problems[] = $schemaProblem;
}
if ($problems !== []) {
    if (!$dryRun) {
        throw new RuntimeException(implode(' ', $problems));
    }
    Logger::warn(implode(' ', $problems) . ' Настоящий запуск с этим упадёт.');
    if ($schemaProblem !== null) {
        exit(0); // без колонки интервала просмотр показать нечем
    }
}

$prefix = $dryRun ? '=== ПРОСМОТР (ничего не создано) === ' : '';
$custom = (new NotificationPreferences($db))->customIntervals();
$date = static fn (string $d): string => date('d.m.Y', (int) strtotime($d));

// --- Выплаты ---------------------------------------------------------------
if ($config->reminders || $dryRun) {
    $result = $watch->remind($today, !$dryRun);
    Logger::info($prefix . "Напоминания о выплатах, сегодня {$today} (мск); даты выплат по графику: " . implode(', ', array_map($date, $dates)));
    foreach (PaymentWatch::adminLines($result['items']) as $line) {
        Logger::info($line);
    }
    Logger::info('Бумаг с выплатой: ' . count($result['items']) . "; создано напоминаний: {$result['created']}; уже были созданы раньше: {$result['existing']}");

    // Свои интервалы пользователей: число дней → эмитенты, за которыми следят такие пользователи.
    $buckets = [];
    foreach ([NotificationPreferences::KIND_COUPON, NotificationPreferences::KIND_AMORTIZATION, NotificationPreferences::KIND_REDEMPTION] as $kind) {
        foreach ($custom[$kind] ?? [] as $days => $issuerIds) {
            $buckets[$days] = array_values(array_unique(array_merge($buckets[$days] ?? [], $issuerIds)));
        }
    }
    ksort($buckets);
    foreach ($buckets as $days => $issuerIds) {
        $result = $watch->remind($today, !$dryRun, (int) $days, $issuerIds);
        Logger::info("Свой интервал — за {$days} дн. до выплаты (эмитентов у таких пользователей: " . count($issuerIds) . '): новых напоминаний '
            . ($dryRun ? 'будет ' . count($result['items']) : $result['created']));
        foreach (PaymentWatch::adminLines($result['items']) as $line) {
            Logger::info($line);
        }
    }
} else {
    Logger::info('Напоминания о выплатах выключены (config/payments.php: reminders).');
}

// --- Оферты ------------------------------------------------------------------
if ($config->offerReminders || $dryRun) {
    $offers = new OfferWatch($db, $publisher, $calendar, $config->offerPutRemindersFrom);
    $default = NotificationPreferences::DEFAULT_DAYS[NotificationPreferences::KIND_OFFER];
    $create = !$dryRun && $config->offerReminders;
    $result = $offers->remind($today, $create, $default);
    Logger::info($prefix . "Напоминания об офертах, за {$default} дн. до даты: новых — " . count($result['items'])
        . ($dryRun ? '' : "; создано: {$result['created']}") . "; уже были созданы раньше: {$result['existing']}"
        . ($dryRun && !$config->offerReminders ? ' (сейчас выключены: config/payments.php, offer_reminders)' : ''));
    foreach (OfferWatch::adminLines($result['items']) as $line) {
        Logger::info($line);
    }
    if ($config->offerPutRemindersFrom !== null) {
        Logger::info('О путах с датой оферты раньше ' . $date($config->offerPutRemindersFrom)
            . " не напоминаем (config/payments.php, offer_put_reminders_from): пропущено {$result['skipped_puts']}");
    } elseif ($dryRun && !$config->offerReminders) {
        // Первый запуск ещё впереди. Решение пользователя (12.10.2026): о путах, которые уже есть в базе и до которых
        // осталось меньше 10 дней, не напоминать. Печатаем готовую строку для настроек и то, что с ней получится.
        $from = date('Y-m-d', (int) strtotime($today . ' +10 day'));
        $withCutoff = (new OfferWatch($db, $publisher, $calendar, $from))->remind($today, false, $default);
        Logger::info("Для первого запуска добавьте в config/payments.php вместе с offer_reminders строку:  'offer_put_reminders_from' => '{$from}',");
        Logger::info('С ней новых напоминаний об офертах будет ' . count($withCutoff['items'])
            . "; не напомним о путах, до которых меньше 10 дней: {$withCutoff['skipped_puts']}");
    }
    $buckets = $custom[NotificationPreferences::KIND_OFFER] ?? [];
    ksort($buckets);
    foreach ($buckets as $days => $issuerIds) {
        $result = $offers->remind($today, $create, (int) $days, $issuerIds);
        Logger::info("Свой интервал — за {$days} дн. до оферты (эмитентов у таких пользователей: " . count($issuerIds) . '): новых напоминаний '
            . ($dryRun ? 'будет ' . count($result['items']) : $result['created']));
        foreach (OfferWatch::adminLines($result['items']) as $line) {
            Logger::info($line);
        }
    }
} else {
    Logger::info('Напоминания об офертах выключены (config/payments.php: offer_reminders).');
}
