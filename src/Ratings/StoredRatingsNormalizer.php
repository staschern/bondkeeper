<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Разово (28.09.2026): уже сохранённые рейтинги приводятся к тем же
 * правилам, что теперь действуют при каждой записи:
 *   1. кириллические двойники букв в рейтинге → латиница ("ruВВВ+" →
 *      "ruBBB+", "eAАА.ru" → "eAAA.ru") — RatingsNormalizer::normalizeGrade();
 *      колонки current_ratings.rating, rating_actions.rating_from/rating_to;
 *   2. отзыв НРА, записанный прочерком "—", → 'отозван' (как у остальных
 *      агентств) — RatingsNormalizer::ratingFromNraColumn();
 *   3. у отозванного рейтинга в current_ratings прогноз пустой (решение
 *      пользователя: "если рейтинг отозван, то прогноз пустой").
 * История rating_actions по прогнозу не меняется. Ключи таблиц не
 * меняются. Переносимый SQL — ради офлайн-теста на SQLite, см.
 * tests/test_stored_ratings_normalizer.php.
 */
final class StoredRatingsNormalizer
{
    private const GRADE_COLUMNS = [
        ['current_ratings', 'rating'],
        ['rating_actions', 'rating_from'],
        ['rating_actions', 'rating_to'],
    ];
    private const NRA_DASHES = ['—', '–', '-'];

    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /**
     * @return array{
     *     grades: array<int, array{table: string, column: string, from: string, to: string, rows: int}>,
     *     nra_withdrawn: array<int, array{table: string, column: string, rows: int}>,
     *     withdrawn_outlook: array<int, array{issuer_id: int, agency: string, outlook: string}>
     * } что изменено (при $apply) или будет изменено (без $apply)
     */
    public function run(bool $apply): array
    {
        $report = ['grades' => [], 'nra_withdrawn' => [], 'withdrawn_outlook' => []];

        // 1. Кириллица в рейтингах.
        foreach (self::GRADE_COLUMNS as [$table, $column]) {
            $values = $this->db->query("SELECT DISTINCT {$column} FROM {$table} WHERE {$column} IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($values as $value) {
                $normalized = RatingsNormalizer::normalizeGrade((string) $value);
                if ($normalized === $value) {
                    continue;
                }
                $rows = $this->count("SELECT COUNT(*) FROM {$table} WHERE {$column} = :value", ['value' => $value]);
                if ($apply) {
                    $this->db->prepare("UPDATE {$table} SET {$column} = :normalized WHERE {$column} = :value")
                        ->execute(['normalized' => $normalized, 'value' => $value]);
                }
                $report['grades'][] = ['table' => $table, 'column' => $column, 'from' => (string) $value, 'to' => $normalized, 'rows' => $rows];
            }
        }

        // 2. Отзыв НРА прочерком → 'отозван'.
        $dashes = "('" . implode("','", self::NRA_DASHES) . "')";
        foreach (self::GRADE_COLUMNS as [$table, $column]) {
            $rows = $this->count("SELECT COUNT(*) FROM {$table} WHERE agency = 'nra' AND {$column} IN {$dashes}", []);
            if ($rows === 0) {
                continue;
            }
            if ($apply) {
                $this->db->exec("UPDATE {$table} SET {$column} = 'отозван' WHERE agency = 'nra' AND {$column} IN {$dashes}");
            }
            $report['nra_withdrawn'][] = ['table' => $table, 'column' => $column, 'rows' => $rows];
        }

        // 3. Отозван → прогноз пустой (в режиме просмотра учитываем и
        // прочерки НРА, которые шаг 2 превратит в 'отозван').
        $withdrawn = $apply
            ? "rating = 'отозван'"
            : "(rating = 'отозван' OR (agency = 'nra' AND rating IN {$dashes}))";
        $rows = $this->db->query(
            "SELECT issuer_id, agency, outlook FROM current_ratings WHERE {$withdrawn} AND outlook IS NOT NULL ORDER BY agency, issuer_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $report['withdrawn_outlook'][] = ['issuer_id' => (int) $row['issuer_id'], 'agency' => (string) $row['agency'], 'outlook' => (string) $row['outlook']];
        }
        if ($apply && $rows !== []) {
            $this->db->exec("UPDATE current_ratings SET outlook = NULL WHERE rating = 'отозван' AND outlook IS NOT NULL");
        }

        return $report;
    }

    /** @param array<string, string> $params */
    private function count(string $sql, array $params): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
