<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;
use Throwable;

/**
 * Разовая очистка уже записанных ложных "отозван" от отзыва рейтинга
 * ВЫПУСКА облигаций из-за погашения (решение пользователя 17.09.2026:
 * такие строки — шум, удаляются вместе с событиями). Общая логика для
 * bin/debug_bond_redemption_ratings.php (только показывает план) и
 * bin/fix_bond_redemption_ratings.php (применяет) — раньше каждый скрипт
 * считал сам.
 *
 * Правка 29.09.2026 (сверка Эксперт РА). Скрипт снова нужен: до пакета
 * 26.09 правило не узнавало формулировку Эксперт РА "рейтинг облигаций …
 * в связи с их полным погашением" (не "рейтинг выпуска облигаций"), и
 * ПетроИнжиниринг, Автодор, СОПФ ДОМ.РФ получили "отозван". Но раньше
 * скрипт пересчитывал current_ratings из истории для КАЖДОЙ затронутой
 * пары — и затирал бы более точное значение: после перезаписи снимком
 * (ПетроИнжиниринг уже ruA из списка агентства) или ручного ввода история
 * новостей старше. Теперь текущий рейтинг пересчитывается, только если
 * он всё ещё тот самый ложный "отозван" (рейтинг 'отозван' и дата одной
 * из удаляемых строк) — тот же признак "активного заражения", что и в
 * диагностике. Новое значение — последняя оставшаяся новость, кроме тех,
 * что теперь не влияют на рейтинг компании
 * (RatingsNormalizer::bondNewsSkipStatusForStoredTitle(): субординированные,
 * ожидаемый рейтинг и т.п.). Прогноз — по общему правилу
 * outlookForRating(). Не осталось ни одной новости — строка current_ratings
 * удаляется (рейтинг этой пары неизвестен), как и раньше.
 *
 * Идемпотентно: удалённые строки при повторном запуске не находятся.
 * Переносимый SQL — ради офлайн-теста на SQLite
 * (tests/test_bond_redemption_cleanup.php).
 */
final class BondRedemptionCleanup
{
    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /**
     * @return array{
     *     actions: array<int, array{id: int, issuer_id: int, short_name: string, agency: string, action_date: string, source_title: string, source_url: ?string, event_id: ?int}>,
     *     pairs: array<int, array{issuer_id: int, short_name: string, agency: string, current: ?array{rating: string, outlook: ?string, last_action_date: string, source: ?string}, active: bool, replacement: ?array{rating: string, outlook: ?string, action_date: string, source_title: ?string}}>
     * }
     */
    public function plan(): array
    {
        $stmt = $this->db->query(
            "SELECT ra.id, ra.issuer_id, i.short_name, ra.agency, ra.action_date, ra.source_title, ra.source_url, ra.event_id
             FROM rating_actions ra
             JOIN issuers i ON i.id = ra.issuer_id
             WHERE ra.rating_to = 'отозван'
             ORDER BY ra.issuer_id, ra.agency, ra.action_date, ra.id"
        );

        $actions = [];
        /** @var array<string, array{issuer_id: int, short_name: string, agency: string, ids: array<int, int>, dates: array<string, true>}> $byPair */
        $byPair = [];
        foreach ($stmt->fetchAll() as $row) {
            $title = (string) ($row['source_title'] ?? '');
            if ($title === '' || !RatingsNormalizer::isBondIssueRedemptionWithdrawal($title)) {
                continue;
            }
            $action = [
                'id' => (int) $row['id'],
                'issuer_id' => (int) $row['issuer_id'],
                'short_name' => (string) $row['short_name'],
                'agency' => (string) $row['agency'],
                'action_date' => (string) $row['action_date'],
                'source_title' => $title,
                'source_url' => $row['source_url'] !== null ? (string) $row['source_url'] : null,
                'event_id' => $row['event_id'] !== null ? (int) $row['event_id'] : null,
            ];
            $actions[] = $action;

            $key = "{$action['issuer_id']}:{$action['agency']}";
            $byPair[$key] ??= ['issuer_id' => $action['issuer_id'], 'short_name' => $action['short_name'], 'agency' => $action['agency'], 'ids' => [], 'dates' => []];
            $byPair[$key]['ids'][] = $action['id'];
            $byPair[$key]['dates'][$action['action_date']] = true;
        }

        $pairs = [];
        foreach ($byPair as $pair) {
            $current = $this->fetchCurrent($pair['issuer_id'], $pair['agency']);
            $active = $current !== null && $current['rating'] === 'отозван' && isset($pair['dates'][$current['last_action_date']]);
            $pairs[] = [
                'issuer_id' => $pair['issuer_id'],
                'short_name' => $pair['short_name'],
                'agency' => $pair['agency'],
                'current' => $current,
                'active' => $active,
                'replacement' => $active ? $this->findReplacement($pair['issuer_id'], $pair['agency'], $pair['ids']) : null,
            ];
        }

        return ['actions' => $actions, 'pairs' => $pairs];
    }

    /**
     * @param array{actions: array<int, array{id: int, event_id: ?int}>, pairs: array<int, array{issuer_id: int, agency: string, active: bool, replacement: ?array{rating: string, outlook: ?string, action_date: string}}>} $plan
     * @return array{deleted_actions: int, deleted_events: int, recomputed: int, removed: int, untouched: int, errors: array<int, string>}
     */
    public function apply(array $plan): array
    {
        $stats = ['deleted_actions' => 0, 'deleted_events' => 0, 'recomputed' => 0, 'removed' => 0, 'untouched' => 0, 'errors' => []];

        // Проход 1 — ложные строки rating_actions и их события (уведомления
        // удаляются каскадом, FK ON DELETE CASCADE). Каждая строка —
        // отдельная транзакция.
        foreach ($plan['actions'] as $action) {
            $this->db->beginTransaction();
            try {
                if ($action['event_id'] !== null) {
                    $this->db->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $action['event_id']]);
                    $stats['deleted_events']++;
                }
                $this->db->prepare('DELETE FROM rating_actions WHERE id = :id')->execute(['id' => $action['id']]);
                $stats['deleted_actions']++;
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                $stats['errors'][] = "rating_actions.id={$action['id']}: {$e->getMessage()}";
            }
        }

        // Проход 2 — текущий рейтинг, только где он всё ещё ложный "отозван".
        foreach ($plan['pairs'] as $pair) {
            if (!$pair['active']) {
                $stats['untouched']++;
                continue;
            }
            $key = ['issuer_id' => $pair['issuer_id'], 'agency' => $pair['agency']];
            try {
                if ($pair['replacement'] !== null) {
                    $rating = $pair['replacement']['rating'];
                    $this->db->prepare(
                        "UPDATE current_ratings
                         SET rating = :rating, outlook = :outlook, last_action_date = :last_action_date, source = 'action'
                         WHERE issuer_id = :issuer_id AND agency = :agency"
                    )->execute($key + [
                        'rating' => $rating,
                        'outlook' => RatingsNormalizer::outlookForRating($rating, $pair['replacement']['outlook']),
                        'last_action_date' => $pair['replacement']['action_date'],
                    ]);
                    $stats['recomputed']++;
                } else {
                    $this->db->prepare('DELETE FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency')->execute($key);
                    $stats['removed']++;
                }
            } catch (Throwable $e) {
                $stats['errors'][] = "current_ratings issuer_id={$pair['issuer_id']}/{$pair['agency']}: {$e->getMessage()}";
            }
        }

        return $stats;
    }

    /** @return array{rating: string, outlook: ?string, last_action_date: string, source: ?string}|null */
    private function fetchCurrent(int $issuerId, string $agency): ?array
    {
        $stmt = $this->db->prepare('SELECT rating, outlook, last_action_date, source FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency');
        $stmt->execute(['issuer_id' => $issuerId, 'agency' => $agency]);
        $row = $stmt->fetch();

        return $row !== false
            ? ['rating' => (string) $row['rating'], 'outlook' => $row['outlook'], 'last_action_date' => (string) $row['last_action_date'], 'source' => $row['source']]
            : null;
    }

    /**
     * Последняя новость пары, кроме удаляемых и тех, что теперь не влияют
     * на рейтинг компании.
     *
     * @param array<int, int> $excludeIds
     * @return array{rating: string, outlook: ?string, action_date: string, source_title: ?string}|null
     */
    private function findReplacement(int $issuerId, string $agency, array $excludeIds): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, rating_to, outlook_to, action_date, source_title FROM rating_actions
             WHERE issuer_id = :issuer_id AND agency = :agency
             ORDER BY action_date DESC, id DESC'
        );
        $stmt->execute(['issuer_id' => $issuerId, 'agency' => $agency]);
        foreach ($stmt->fetchAll() as $row) {
            if (in_array((int) $row['id'], $excludeIds, true)) {
                continue;
            }
            $title = (string) ($row['source_title'] ?? '');
            if ($title !== '' && RatingsNormalizer::bondNewsSkipStatusForStoredTitle($title) !== null) {
                continue;
            }

            return [
                'rating' => (string) $row['rating_to'],
                'outlook' => $row['outlook_to'],
                'action_date' => (string) $row['action_date'],
                'source_title' => $row['source_title'],
            ];
        }

        return null;
    }
}
