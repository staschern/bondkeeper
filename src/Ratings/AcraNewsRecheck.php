<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;
use Throwable;

/**
 * Разовая перепроверка уже записанных новостей АКРА (03.10.2026, сверка
 * АКРА) — общая логика для bin/fix_acra_news_ratings.php (без --apply
 * только показывает план). Каждая строка rating_actions агентства 'acra'
 * с заголовком сайта ("АКРА <глагол> …") разбирается заново исправленным
 * кодом:
 *   - новость, которая теперь не пишется вообще — ипотечные ценные бумаги
 *     (RatingsNormalizer::isNonStandardRating()) или заголовок без
 *     рейтинга (AcraNewsTitleParser::extractGrade() = NULL) — удаляется
 *     вместе с событием;
 *   - рейтинг в заголовке другой, чем записан, — исправляется на рейтинг
 *     из заголовка; событие с неверным рейтингом удаляется.
 * Живые случаи: СФО «ВСМ Инвест — Первый» и СФО «Столичные дороги» —
 * записан "C" (предлог "С" в "облигаций С залоговым обеспечением") вместо
 * AAA(RU); СФО «ТБ-11» — "A" из названия класса «А1», рейтинга в заголовке
 * нет; ДОМ.РФ Ипотечный агент — "отозван" из новости о погашении выпуска
 * ипотечных ценных бумаг.
 *
 * Новости про ожидаемый рейтинг и субординированные облигации, записанные
 * до правил 28–29.09, НЕ трогаются — историю по ним решено оставить
 * (ТБанк, решение пользователя 28.09.2026). Отзывы из-за погашения
 * облигаций чистит bin/fix_bond_redemption_ratings.php.
 *
 * Текущий рейтинг (current_ratings) меняется, только если он всё ещё взят
 * из этой самой строки (та же дата и тот же записанный рейтинг) — значение
 * из перезаписи снимком или ручного ввода не затирается, как и в
 * BondRedemptionCleanup. Для удаляемой строки замена — последняя
 * оставшаяся новость, кроме тех, что теперь не влияют на рейтинг компании;
 * нет такой — строка current_ratings удаляется.
 *
 * Идемпотентно: после применения повторный план пуст. Переносимый SQL —
 * ради офлайн-теста на SQLite (tests/test_acra_news_recheck.php).
 */
final class AcraNewsRecheck
{
    private const AGENCY = 'acra';

    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /**
     * @return array<int, array{
     *     id: int, issuer_id: int, short_name: string, action_date: string, source_title: string, source_url: ?string, event_id: ?int,
     *     stored: string, action: string, reason: string, grade: ?string,
     *     current: ?array{rating: string, outlook: ?string, last_action_date: string, source: ?string},
     *     active: bool,
     *     replacement: ?array{rating: string, outlook: ?string, action_date: string, source_title: ?string}
     * }> action — 'drop' (удалить) или 'regrade' (исправить рейтинг на grade)
     */
    public function plan(): array
    {
        $stmt = $this->db->prepare(
            'SELECT ra.id, ra.issuer_id, i.short_name, ra.action_date, ra.rating_to, ra.source_title, ra.source_url, ra.event_id
             FROM rating_actions ra
             JOIN issuers i ON i.id = ra.issuer_id
             WHERE ra.agency = :agency
             ORDER BY ra.action_date, ra.id'
        );
        $stmt->execute(['agency' => self::AGENCY]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $title = (string) ($row['source_title'] ?? '');
            $verb = $title !== '' ? AcraNewsTitleParser::matchVerb($title) : null;
            if ($verb === null) {
                continue; // не заголовок сайта (например, строка из почтовой рассылки)
            }

            $stored = (string) $row['rating_to'];
            $grade = null;
            if (RatingsNormalizer::isNonStandardRating($title)) {
                $action = 'drop';
                $reason = 'ипотечные ценные бумаги или шкала структурированного финансирования — такие новости не пишутся';
            } else {
                $grade = AcraNewsTitleParser::extractGrade($title, $verb);
                if ($grade === null) {
                    $action = 'drop';
                    $reason = 'в заголовке нет рейтинга';
                } elseif ($grade !== RatingsNormalizer::normalizeGrade($stored)) {
                    $action = 'regrade';
                    $reason = "в заголовке рейтинг {$grade}, записан {$stored}";
                } else {
                    continue;
                }
            }

            $items[] = [
                'id' => (int) $row['id'],
                'issuer_id' => (int) $row['issuer_id'],
                'short_name' => (string) $row['short_name'],
                'action_date' => (string) $row['action_date'],
                'source_title' => $title,
                'source_url' => $row['source_url'] !== null ? (string) $row['source_url'] : null,
                'event_id' => $row['event_id'] !== null ? (int) $row['event_id'] : null,
                'stored' => $stored,
                'action' => $action,
                'reason' => $reason,
                'grade' => $grade,
            ];
        }

        $dropIds = array_column(array_filter($items, static fn (array $i): bool => $i['action'] === 'drop'), 'id');
        $regrades = array_column(array_filter($items, static fn (array $i): bool => $i['action'] === 'regrade'), 'grade', 'id');

        foreach ($items as &$item) {
            $current = $this->fetchCurrent($item['issuer_id']);
            $item['current'] = $current;
            $item['active'] = $current !== null
                && $current['last_action_date'] === $item['action_date']
                && RatingsNormalizer::normalizeGrade($current['rating']) === RatingsNormalizer::normalizeGrade($item['stored']);
            $item['replacement'] = $item['action'] === 'drop' && $item['active']
                ? $this->findReplacement($item['issuer_id'], $dropIds, $regrades)
                : null;
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<int, array{id: int, issuer_id: int, event_id: ?int, action: string, grade: ?string, active: bool, current: ?array{outlook: ?string}, replacement: ?array{rating: string, outlook: ?string, action_date: string}}> $plan
     * @return array{dropped: int, regraded: int, deleted_events: int, current_updated: int, current_removed: int, current_untouched: int, errors: array<int, string>}
     */
    public function apply(array $plan): array
    {
        $stats = ['dropped' => 0, 'regraded' => 0, 'deleted_events' => 0, 'current_updated' => 0, 'current_removed' => 0, 'current_untouched' => 0, 'errors' => []];

        foreach ($plan as $item) {
            $key = ['issuer_id' => $item['issuer_id'], 'agency' => self::AGENCY];
            $this->db->beginTransaction();
            try {
                if ($item['event_id'] !== null) {
                    // Событие с неверным рейтингом (или про новость, которой
                    // не должно быть) — уведомления удаляются каскадом.
                    $this->db->prepare('UPDATE rating_actions SET event_id = NULL WHERE id = :id')->execute(['id' => $item['id']]);
                    $this->db->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $item['event_id']]);
                    $stats['deleted_events']++;
                }

                if ($item['action'] === 'drop') {
                    $this->db->prepare('DELETE FROM rating_actions WHERE id = :id')->execute(['id' => $item['id']]);
                    $stats['dropped']++;
                } else {
                    $this->db->prepare('UPDATE rating_actions SET rating_to = :grade WHERE id = :id')->execute(['grade' => $item['grade'], 'id' => $item['id']]);
                    $stats['regraded']++;
                }

                if (!$item['active']) {
                    $stats['current_untouched']++;
                } elseif ($item['action'] === 'regrade') {
                    $this->db->prepare('UPDATE current_ratings SET rating = :rating, outlook = :outlook WHERE issuer_id = :issuer_id AND agency = :agency')
                        ->execute($key + [
                            'rating' => $item['grade'],
                            'outlook' => RatingsNormalizer::outlookForRating((string) $item['grade'], $item['current']['outlook'] ?? null),
                        ]);
                    $stats['current_updated']++;
                } elseif ($item['replacement'] !== null) {
                    $rating = $item['replacement']['rating'];
                    $this->db->prepare(
                        "UPDATE current_ratings
                         SET rating = :rating, outlook = :outlook, last_action_date = :last_action_date, source = 'action'
                         WHERE issuer_id = :issuer_id AND agency = :agency"
                    )->execute($key + [
                        'rating' => $rating,
                        'outlook' => RatingsNormalizer::outlookForRating($rating, $item['replacement']['outlook']),
                        'last_action_date' => $item['replacement']['action_date'],
                    ]);
                    $stats['current_updated']++;
                } else {
                    $this->db->prepare('DELETE FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency')->execute($key);
                    $stats['current_removed']++;
                }

                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                $stats['errors'][] = "rating_actions.id={$item['id']}: {$e->getMessage()}";
            }
        }

        return $stats;
    }

    /** @return array{rating: string, outlook: ?string, last_action_date: string, source: ?string}|null */
    private function fetchCurrent(int $issuerId): ?array
    {
        $stmt = $this->db->prepare('SELECT rating, outlook, last_action_date, source FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency');
        $stmt->execute(['issuer_id' => $issuerId, 'agency' => self::AGENCY]);
        $row = $stmt->fetch();

        return $row !== false
            ? ['rating' => (string) $row['rating'], 'outlook' => $row['outlook'], 'last_action_date' => (string) $row['last_action_date'], 'source' => $row['source']]
            : null;
    }

    /**
     * Последняя новость компании, кроме удаляемых и тех, что теперь не
     * влияют на рейтинг компании; у исправляемой строки — уже исправленный
     * рейтинг.
     *
     * @param array<int, int> $dropIds
     * @param array<int, string> $regrades id строки => исправленный рейтинг
     * @return array{rating: string, outlook: ?string, action_date: string, source_title: ?string}|null
     */
    private function findReplacement(int $issuerId, array $dropIds, array $regrades): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, rating_to, outlook_to, action_date, source_title FROM rating_actions
             WHERE issuer_id = :issuer_id AND agency = :agency
             ORDER BY action_date DESC, id DESC'
        );
        $stmt->execute(['issuer_id' => $issuerId, 'agency' => self::AGENCY]);
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            if (in_array($id, $dropIds, true)) {
                continue;
            }
            $title = (string) ($row['source_title'] ?? '');
            if ($title !== '' && RatingsNormalizer::bondNewsSkipStatusForStoredTitle($title) !== null) {
                continue;
            }

            return [
                'rating' => $regrades[$id] ?? (string) $row['rating_to'],
                'outlook' => $row['outlook_to'],
                'action_date' => (string) $row['action_date'],
                'source_title' => $row['source_title'],
            ];
        }

        return null;
    }
}
