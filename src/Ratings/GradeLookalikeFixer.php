<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Разово: уже сохранённые рейтинги с кириллическими двойниками букв
 * ("ruВВВ+", "eAАА.ru") → латиница, тем же правилом, что теперь
 * применяется при каждой записи (RatingsNormalizer::normalizeGrade(),
 * 28.09.2026). Колонки: current_ratings.rating, rating_actions.rating_from,
 * rating_actions.rating_to. Ключи таблиц не меняются — только значение
 * рейтинга. Переносимый SQL (SELECT DISTINCT + UPDATE по точному значению)
 * — ради офлайн-теста на SQLite, см. tests/test_grade_lookalike_fixer.php.
 */
final class GradeLookalikeFixer
{
    private const COLUMNS = [
        ['current_ratings', 'rating'],
        ['rating_actions', 'rating_from'],
        ['rating_actions', 'rating_to'],
    ];

    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /**
     * @return array<int, array{table: string, column: string, from: string, to: string, rows: int}>
     *         что заменено (при $apply) или будет заменено (без $apply)
     */
    public function run(bool $apply): array
    {
        $changes = [];
        foreach (self::COLUMNS as [$table, $column]) {
            $values = $this->db->query("SELECT DISTINCT {$column} FROM {$table} WHERE {$column} IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($values as $value) {
                $normalized = RatingsNormalizer::normalizeGrade((string) $value);
                if ($normalized === $value) {
                    continue;
                }

                $count = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :value");
                $count->execute(['value' => $value]);
                $rows = (int) $count->fetchColumn();

                if ($apply) {
                    $this->db->prepare("UPDATE {$table} SET {$column} = :normalized WHERE {$column} = :value")
                        ->execute(['normalized' => $normalized, 'value' => $value]);
                }
                $changes[] = ['table' => $table, 'column' => $column, 'from' => (string) $value, 'to' => $normalized, 'rows' => $rows];
            }
        }

        return $changes;
    }
}
