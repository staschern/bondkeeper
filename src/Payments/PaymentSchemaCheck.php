<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use PDO;
use RuntimeException;

/**
 * Проверка перед работой скриптов выплат: есть ли в базе типы событий,
 * которые скрипт собирается создавать (11.10.2026).
 *
 * У events.event_type_code внешний ключ на event_types: если нужной строки
 * нет (не применена миграция), скрипт падает на первой же вставке с
 * невнятной ошибкой базы. Так с 07 по 10.10.2026 падали напоминания.
 * Здесь та же беда ловится до начала работы и называется прямо — вместе с
 * миграцией, которую надо применить.
 */
final class PaymentSchemaCheck
{
    /** Типы событий, добавленные миграциями после исходной схемы. */
    private const ADDED_BY = [
        'R1' => 'database/027_payment_events.sql',
        'B2b' => 'database/027_payment_events.sql',
        'B1a' => 'database/028_payment_events_partial_before_due.sql',
        'R2' => 'database/032_notification_settings.sql',
    ];

    /**
     * @param array<int, string> $codes
     * @return array<int, string> коды, которых в event_types нет
     */
    public static function missingEventTypes(PDO $db, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $stmt = $db->prepare('SELECT code FROM event_types WHERE code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')');
        $stmt->execute(array_values($codes));
        $present = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_diff($codes, $present));
    }

    /**
     * Бросает исключение с понятным текстом, если каких-то типов нет.
     *
     * @param array<int, string> $codes
     */
    public static function requireEventTypes(PDO $db, array $codes): void
    {
        $missing = self::missingEventTypes($db, $codes);
        if ($missing !== []) {
            throw new RuntimeException(self::describe($missing));
        }
    }

    /** @param array<int, string> $missing */
    public static function describe(array $missing): string
    {
        $migrations = array_values(array_unique(array_map(
            static fn (string $code): string => self::ADDED_BY[$code] ?? 'database/001_schema.sql (начальное заполнение event_types)',
            $missing,
        )));

        return 'В базе нет типов событий: ' . implode(', ', $missing) . '. Нужно применить: ' . implode('; ', $migrations) . '.';
    }
}
