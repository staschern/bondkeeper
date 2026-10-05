<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use DateTimeImmutable;
use RuntimeException;

/**
 * Канонический календарь рабочих дней (Этап 5, выплаты — см.
 * docs/STAGE5_PAYMENTS.md). Из исследования пользователя
 * (documents/…Event_Taxonomy_QA.docx): расчёт «10 рабочих дней» вокруг
 * праздников путают даже профильные СМИ (Роял Капитал: 13/19/20 мая в
 * трёх источниках), поэтому календарь — один на всю систему.
 *
 * Данные — config/working_calendar.php (отклонения от «суббота и
 * воскресенье — выходные» по годам, обновляются раз в год). Для года без
 * данных выходными считаются только суббота и воскресенье; coversYear()
 * даёт вызывающему коду повод предупредить администратора.
 *
 * Все даты — строки 'Y-m-d'. Чистый класс, без БД и сети.
 */
final class WorkingCalendar
{
    /** @var array<string, true> */
    private array $nonWorkingWeekdays = [];
    /** @var array<string, true> */
    private array $workingWeekends = [];
    /** @var array<int, true> */
    private array $years = [];

    /** @param array<int, array{non_working_weekdays?: array<int, string>, working_weekends?: array<int, string>}> $years */
    public function __construct(array $years)
    {
        foreach ($years as $year => $data) {
            $this->years[(int) $year] = true;
            foreach ($data['non_working_weekdays'] ?? [] as $date) {
                $this->nonWorkingWeekdays[$date] = true;
            }
            foreach ($data['working_weekends'] ?? [] as $date) {
                $this->workingWeekends[$date] = true;
            }
        }
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Не найден календарь рабочих дней: {$path}");
        }
        $years = require $path;
        if (!is_array($years)) {
            throw new RuntimeException("Календарь рабочих дней {$path} должен возвращать массив по годам.");
        }

        return new self($years);
    }

    /** Есть ли в календаре данные о праздниках этого года. */
    public function coversYear(int $year): bool
    {
        return isset($this->years[$year]);
    }

    public function isWorkingDay(string $date): bool
    {
        $weekday = (int) self::parse($date)->format('N'); // 1 — понедельник … 7 — воскресенье
        if ($weekday >= 6) {
            return isset($this->workingWeekends[$date]);
        }

        return !isset($this->nonWorkingWeekdays[$date]);
    }

    /**
     * Дата, в которую выплата должна быть исполнена на деле: сама дата,
     * если это рабочий день, иначе первый рабочий день после неё (типовое
     * правило эмиссионной документации — без компенсации за задержку).
     */
    public function effectiveDate(string $date): string
    {
        return $this->isWorkingDay($date) ? $date : $this->nextWorkingDay($date);
    }

    /** Ближайший рабочий день строго после даты. */
    public function nextWorkingDay(string $date): string
    {
        return $this->addWorkingDays($date, 1);
    }

    /** Ближайший рабочий день строго до даты. */
    public function previousWorkingDay(string $date): string
    {
        $day = self::parse($date);
        do {
            $day = $day->modify('-1 day');
        } while (!$this->isWorkingDay($day->format('Y-m-d')));

        return $day->format('Y-m-d');
    }

    /**
     * N-й рабочий день после даты (сама дата не считается). Срок полного
     * дефолта — addWorkingDays(день выплаты, 10): СибАвтоТранс, 16.04.2026
     * → 30.04.2026.
     */
    public function addWorkingDays(string $date, int $days): string
    {
        $day = self::parse($date);
        $left = $days;
        while ($left > 0) {
            $day = $day->modify('+1 day');
            if ($this->isWorkingDay($day->format('Y-m-d'))) {
                $left--;
            }
        }

        return $day->format('Y-m-d');
    }

    /** Сколько рабочих дней от даты (не включая) до даты (включая); 0, если $to не позже $from. */
    public function workingDaysBetween(string $from, string $to): int
    {
        $count = 0;
        $day = self::parse($from);
        $end = self::parse($to);
        while ($day < $end) {
            $day = $day->modify('+1 day');
            if ($this->isWorkingDay($day->format('Y-m-d'))) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Даты по графику, у которых день исполнения — $workingDay: он сам и
     * все нерабочие дни подряд перед ним (выплата из субботы и
     * воскресенья исполняется в понедельник). Для нерабочего дня — пусто.
     *
     * @return array<int, string>
     */
    public function scheduleDatesDueOn(string $workingDay): array
    {
        if (!$this->isWorkingDay($workingDay)) {
            return [];
        }
        $dates = [$workingDay];
        $day = self::parse($workingDay)->modify('-1 day');
        while (!$this->isWorkingDay($day->format('Y-m-d'))) {
            $dates[] = $day->format('Y-m-d');
            $day = $day->modify('-1 day');
        }

        return $dates;
    }

    private static function parse(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false) {
            throw new RuntimeException("Дата не в формате ГГГГ-ММ-ДД: {$date}");
        }

        return $parsed;
    }
}
