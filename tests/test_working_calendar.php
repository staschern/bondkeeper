<?php

declare(strict_types=1);

/**
 * Офлайн-проверка календаря рабочих дней (Этап 5, выплаты):
 * config/working_calendar.php + src/Payments/WorkingCalendar.php.
 * Число рабочих дней в году сверяется с официальным производственным
 * календарём (247 и в 2026, и в 2027), сроки дефолта — с реальными
 * кейсами из исследования пользователя.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring tests/test_working_calendar.php
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Payments\WorkingCalendar;

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

$calendar = WorkingCalendar::fromFile(dirname(__DIR__) . '/config/working_calendar.php');

echo "--- данные календаря ---\n";
$workingDaysIn = static function (int $year) use ($calendar): int {
    $count = 0;
    for ($day = new DateTimeImmutable("{$year}-01-01"); (int) $day->format('Y') === $year; $day = $day->modify('+1 day')) {
        if ($calendar->isWorkingDay($day->format('Y-m-d'))) {
            $count++;
        }
    }

    return $count;
};
check('2026: 247 рабочих дней, как в официальном календаре', 247, $workingDaysIn(2026));
check('2027: 247 рабочих дней, как в официальном календаре', 247, $workingDaysIn(2027));
check('2026 и 2027 в календаре есть, 2028 — нет', [true, true, false], [$calendar->coversYear(2026), $calendar->coversYear(2027), $calendar->coversYear(2028)]);
check('9 января 2026 (пятница) — выходной, перенос с субботы 3 января', false, $calendar->isWorkingDay('2026-01-09'));
check('31 декабря 2026 (четверг) — выходной', false, $calendar->isWorkingDay('2026-12-31'));
check('11 мая 2026 (понедельник после Дня Победы в субботу) — выходной', false, $calendar->isWorkingDay('2026-05-11'));
check('20 февраля 2027 (суббота) — рабочий день', true, $calendar->isWorkingDay('2027-02-20'));
check('22 февраля 2027 (понедельник) — выходной', false, $calendar->isWorkingDay('2027-02-22'));
check('обычная суббота — выходной, обычный вторник — рабочий', [false, true], [$calendar->isWorkingDay('2026-10-10'), $calendar->isWorkingDay('2026-10-13')]);
check('год без данных: будний день рабочий, суббота — выходной', [true, false], [$calendar->isWorkingDay('2028-01-03'), $calendar->isWorkingDay('2028-01-01')]);

echo "\n--- день исполнения выплаты ---\n";
check('рабочий день — сам день', '2026-10-13', $calendar->effectiveDate('2026-10-13'));
check('суббота → понедельник', '2026-10-12', $calendar->effectiveDate('2026-10-10'));
check('воскресенье → понедельник', '2026-10-12', $calendar->effectiveDate('2026-10-11'));
check('7 января 2026 → 12 января (новогодние каникулы)', '2026-01-12', $calendar->effectiveDate('2026-01-07'));
check('9 мая 2026 (суббота) → 12 мая (11 мая — выходной)', '2026-05-12', $calendar->effectiveDate('2026-05-09'));
check('в понедельник исполняются выплаты понедельника, воскресенья и субботы', ['2026-10-12', '2026-10-11', '2026-10-10'], $calendar->scheduleDatesDueOn('2026-10-12'));
check('12 января 2026 — выплаты за все каникулы с 1 января', 12, count($calendar->scheduleDatesDueOn('2026-01-12')));
check('в нерабочий день ничего не исполняется', [], $calendar->scheduleDatesDueOn('2026-10-10'));

echo "\n--- рабочие дни ---\n";
check('следующий рабочий день после пятницы — понедельник', '2026-10-12', $calendar->nextWorkingDay('2026-10-09'));
check('предыдущий рабочий день перед понедельником — пятница', '2026-10-09', $calendar->previousWorkingDay('2026-10-12'));
check('предыдущий рабочий день перед 12 января 2026 — 31 декабря 2025', '2025-12-31', $calendar->previousWorkingDay('2026-01-12'));
check('СибАвтоТранс: 16.04.2026 + 10 рабочих дней = 30.04.2026 (дефолт наступил ровно 30.04)', '2026-04-30', $calendar->addWorkingDays('2026-04-16', 10));
check('Роял Капитал: 04.05.2026 + 10 рабочих дней = 19.05.2026 (11 мая — выходной)', '2026-05-19', $calendar->addWorkingDays('2026-05-04', 10));
check('между 16.04 и 30.04.2026 — 10 рабочих дней', 10, $calendar->workingDaysBetween('2026-04-16', '2026-04-30'));
check('дата не позже начала — 0', 0, $calendar->workingDaysBetween('2026-04-30', '2026-04-16'));

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
