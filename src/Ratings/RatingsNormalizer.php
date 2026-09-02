<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

/**
 * Общие для НРА/НКР (и, вероятно, будущих агентств) преобразования сырых
 * текстовых значений из выгрузок в формат схемы БД. Вынесено в отдельный
 * класс, а не продублировано в каждом импортёре — вокабуляр прогноза на
 * русском совпадает у обоих агентств, дата в обоих выгрузках — DD.MM.YYYY.
 */
final class RatingsNormalizer
{
    /**
     * current_ratings.outlook — ENUM('positive','stable','negative','developing').
     * Реальный словарь агентств шире (см. STAGE3_RATINGS.md — у НКР
     * встречаются "рейтинг на пересмотре...", "прогноз отозван", "—"): для
     * всего, что не сводится однозначно к одному из 4 значений, — честно
     * NULL, а не приблизительное угадывание направления.
     */
    public static function mapOutlook(string $raw): ?string
    {
        return match (mb_strtolower(trim($raw))) {
            'стабильный' => 'stable',
            'позитивный' => 'positive',
            'негативный' => 'negative',
            'неопределенный', 'неопределённый' => 'developing',
            // АКРА использует "развивающийся" там, где остальные говорят
            // "неопределённый" — тот же смысл (S&P-style "developing"),
            // другое слово (см. STAGE3_RATINGS.md, JSON-выгрузка АКРА).
            'развивающийся' => 'developing',
            default => null,
        };
    }

    /**
     * Обе выгрузки отдают дату как "ДД.ММ.ГГГГ" — приводим к DATE-формату
     * MySQL. Пустое/нераспознанное значение — NULL, а не текущая дата или
     * иная догадка (current_ratings.last_action_date NOT NULL — вызывающий
     * код должен сам решить, пропускать ли строку без даты).
     */
    public static function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
            return null;
        }

        [, $day, $month, $year] = $m;
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return "{$year}-{$month}-{$day}";
    }

    /** @var array<string, int> сокращённое русское название месяца (нижний регистр) => номер */
    private const RUSSIAN_MONTHS = [
        'янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4,
        'май' => 5, 'июн' => 6, 'июл' => 7, 'авг' => 8,
        'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12,
    ];

    /**
     * АКРА отдаёт дату как "28 авг 2026" (день, сокращённое русское
     * название месяца, год) — не ДД.ММ.ГГГГ, как у НРА/НКР, отдельный
     * формат под отдельный метод, а не подгонка под parseDate().
     */
    public static function parseRussianMonthDate(string $raw): ?string
    {
        $raw = mb_strtolower(trim($raw));
        if (!preg_match('/^(\d{1,2})\s+([а-я]{3})[а-я]*\s+(\d{4})$/u', $raw, $m)) {
            return null;
        }

        [, $day, $monthAbbr, $year] = $m;
        $month = self::RUSSIAN_MONTHS[$monthAbbr] ?? null;
        if ($month === null || !checkdate($month, (int) $day, (int) $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $year, $month, (int) $day);
    }

    /**
     * xlsx хранит дату как порядковый номер дня (число дней с
     * 1899-12-30 — да, именно 30-е, из-за исторической ошибки Excel с
     * "1900 годом как високосным"; для дат после марта 1900 это не
     * влияет на результат). XlsxReader сам не преобразует форматы ячеек
     * (не разбирает styles.xml), поэтому для xlsx-источников, где дата
     * приходит именно так (см. ручной файл в STAGE3_RATINGS.md), нужен
     * отдельный разбор — не ДД.ММ.ГГГГ и не "28 авг 2026".
     */
    public static function parseExcelSerialDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (!preg_match('/^\d+$/', $raw)) {
            return null;
        }

        $serial = (int) $raw;
        if ($serial < 1 || $serial > 100000) {
            return null;
        }

        $timestamp = ($serial - 25569) * 86400;

        return gmdate('Y-m-d', $timestamp);
    }

    /**
     * Заголовки/подзаголовки новостей — не отдельное поле с одним словом
     * (как у mapOutlook()), а свободный текст с русскими падежными
     * окончаниями: "прогноз изменён на стабильный", "со стабильным
     * прогнозом", "прогноз — стабильный" — все варианты одного слова
     * должны дать один результат. Поэтому здесь — поиск по корню слова,
     * а не точное совпадение всей строки. Как и mapOutlook() — честный
     * NULL для всего, что не сводится к одному из 4 значений (например,
     * "рейтинг на пересмотре с возможностью понижения" у НКР — это не
     * простой прогноз, а отдельное состояние, которое схема не хранит).
     */
    public static function mapOutlookFromProse(string $text): ?string
    {
        $text = mb_strtolower($text);

        return match (true) {
            (bool) preg_match('/позитивн|положительн/u', $text) => 'positive',
            (bool) preg_match('/негативн|отрицательн/u', $text) => 'negative',
            (bool) preg_match('/стабильн/u', $text) => 'stable',
            (bool) preg_match('/развивающ|неопределенн|неопределённ/u', $text) => 'developing',
            default => null,
        };
    }
}
