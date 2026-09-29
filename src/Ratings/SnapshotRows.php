<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;
use RuntimeException;

/**
 * "Снимок сейчас" от агентства (НКР, Эксперт РА, АКРА): одна строка на
 * эмитента, сохранение снимка в файл и запись в current_ratings.
 *
 * === Одна строка на эмитента (П1, сентябрь 2026) ===
 *
 * Живая находка при разборе сверки: на raexpert.ru одна компания
 * встречается в нескольких категориях — после смены методологии старый
 * рейтинг отзывают, и он остаётся в архиве старой категории (АФК Система:
 * отозван в "нефинансовых" в 2017 году, действующий — в "холдинговых";
 * ПКБ, Магистраль двух столиц, ТРАНСФИН-М, Ситиматик, Россиум — то же).
 * Раньше в current_ratings побеждала строка категории, обработанной
 * последней (у ТРАНСФИН-М так записалась старая дата), а сверка
 * сравнивала каждую строку и шумела.
 *
 * Правило: выигрывает самая свежая дата. Так действующий рейтинг
 * перебивает архивный "отозван", а если отзыв — самое последнее
 * событие, остаётся "отозван". При равной дате — строка не "отозван".
 *
 * === Сначала сверка, потом перезапись (П2, сентябрь 2026) ===
 *
 * Идея пользователя: сначала посмотреть результаты сверки, подтвердить,
 * что всё в порядке, и только потом перезаписывать. Поэтому
 * bin/reconcile_ratings.php сохраняет снимок в файл (saveToFile()), а
 * перезапись — это `bin/seed_ratings.php --agency=... --snapshot=ФАЙЛ`,
 * которая пишет ровно тот снимок, что был проверен (loadFromFile() +
 * apply()), без повторного скачивания.
 *
 * @phpstan-type SnapshotRow array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, source_url: ?string}
 */
final class SnapshotRows
{
    /**
     * @param array<int, SnapshotRow> $rows
     * @return array<int, SnapshotRow>
     */
    public static function latestPerIssuer(array $rows): array
    {
        $best = [];
        foreach ($rows as $row) {
            $issuerId = $row['issuer_id'];
            if (!isset($best[$issuerId]) || self::isBetter($row, $best[$issuerId])) {
                $best[$issuerId] = $row;
            }
        }

        return array_values($best);
    }

    /**
     * Пишет снимок в current_ratings с source='snapshot' (миграция 025).
     * Переносимый SQL (SELECT → UPDATE/INSERT, без ON DUPLICATE KEY
     * UPDATE) — ради офлайн-теста на SQLite, см. tests/test_snapshot_rows.php.
     * Параллельной записи одного агентства нет: seed_ratings.php держит
     * блокировку на агентство.
     *
     * @param array<int, SnapshotRow> $rows
     * @return int сколько строк записано
     */
    public static function apply(PDO $db, string $agency, array $rows): int
    {
        $exists = $db->prepare('SELECT 1 FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency');
        $update = $db->prepare(
            "UPDATE current_ratings
             SET rating = :rating, outlook = :outlook, last_action_date = :last_action_date,
                 matched_by_root_name = 0, source = 'snapshot'
             WHERE issuer_id = :issuer_id AND agency = :agency"
        );
        $insert = $db->prepare(
            "INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date, matched_by_root_name, source)
             VALUES (:issuer_id, :agency, :rating, :outlook, :last_action_date, 0, 'snapshot')"
        );

        $count = 0;
        foreach ($rows as $row) {
            $key = ['issuer_id' => $row['issuer_id'], 'agency' => $agency];
            $rating = mb_substr(RatingsNormalizer::normalizeGrade($row['rating']), 0, 20);
            $values = $key + [
                'rating' => $rating,
                // "отозван" → прогноз пустой для любого источника (решение 28.09.2026).
                'outlook' => RatingsNormalizer::outlookForRating($rating, $row['outlook']),
                'last_action_date' => $row['last_action_date'],
            ];

            $exists->execute($key);
            $found = $exists->fetchColumn() !== false;
            $exists->closeCursor();

            ($found ? $update : $insert)->execute($values);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<int, SnapshotRow> $rows
     * @return string путь к сохранённому файлу
     */
    public static function saveToFile(string $agency, array $rows, string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Не удалось создать папку для снимков: {$dir}");
        }

        $path = rtrim($dir, '/\\') . '/' . $agency . '-' . date('Y-m-d_His') . '.json';
        $json = json_encode(
            ['agency' => $agency, 'created_at' => date('Y-m-d H:i:s'), 'rows' => array_values($rows)],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Не удалось записать снимок: {$path}");
        }

        return $path;
    }

    /**
     * @return array{agency: string, created_at: string, rows: array<int, SnapshotRow>}
     */
    public static function loadFromFile(string $path, string $expectedAgency): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Файл снимка не найден: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['agency'], $data['created_at'], $data['rows']) || !is_array($data['rows'])) {
            throw new RuntimeException("Файл не похож на снимок bin/reconcile_ratings.php: {$path}");
        }
        if ($data['agency'] !== $expectedAgency) {
            throw new RuntimeException("Снимок {$path} — агентства '{$data['agency']}', а перезапись запрошена для '{$expectedAgency}'.");
        }

        $rows = [];
        foreach ($data['rows'] as $i => $row) {
            if (
                !is_array($row)
                || !is_int($row['issuer_id'] ?? null) || $row['issuer_id'] <= 0
                || !is_string($row['rating'] ?? null) || $row['rating'] === ''
                || !is_string($row['last_action_date'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['last_action_date'])
                || !(is_string($row['outlook'] ?? null) || ($row['outlook'] ?? null) === null)
            ) {
                throw new RuntimeException("Снимок {$path}: строка #{$i} повреждена.");
            }
            $rows[] = [
                'issuer_id' => $row['issuer_id'],
                'issuer_name' => (string) ($row['issuer_name'] ?? ''),
                'rating' => $row['rating'],
                'outlook' => $row['outlook'] ?? null,
                'last_action_date' => $row['last_action_date'],
                'source_url' => isset($row['source_url']) ? (string) $row['source_url'] : null,
            ];
        }

        return ['agency' => (string) $data['agency'], 'created_at' => (string) $data['created_at'], 'rows' => $rows];
    }

    /**
     * @param array{rating: string, last_action_date: string} $candidate
     * @param array{rating: string, last_action_date: string} $current
     */
    private static function isBetter(array $candidate, array $current): bool
    {
        if ($candidate['last_action_date'] !== $current['last_action_date']) {
            return $candidate['last_action_date'] > $current['last_action_date'];
        }

        return $current['rating'] === 'отозван' && $candidate['rating'] !== 'отозван';
    }
}
