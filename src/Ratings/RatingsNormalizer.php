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
}
