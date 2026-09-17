<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Сверка `current_ratings` с "истиной" от агентства напрямую (прямой
 * запрос пользователя, 17 сентября 2026) — принципиально НЕ импортёр:
 * ничего не пишет, только сравнивает и отчитывается о расхождениях.
 *
 * Зачем отдельный класс, а не режим внутри существующих импортёров:
 * ответственность другая. Импортёры (NkrImporter/NraImporter/...) знают,
 * КАК получить свежие данные от конкретного агентства (HTTP/Excel/HTML) —
 * этот класс сознательно ничего не знает про источники, только про
 * СРАВНЕНИЕ уже готового нормализованного "снимка сейчас" (агентство-
 * независимый формат) с тем, что лежит в `current_ratings`. Получение
 * снимка — забота вызывающего кода (bin/reconcile_ratings.php):
 *   - НКР — единственное агентство с отдельной страницей "снимок
 *     сейчас" (issuers.php) — переиспользует NkrImporter::fetchSnapshot()
 *     буквально, только БЕЗ записи в БД.
 *   - НРА — истории с 2020 года, отдельного снимка нет — "сейчас"
 *     пересчитывается как последняя по дате строка НА КАЖДОГО ЭМИТЕНТА
 *     среди строк NraImporter::fetchCreditRatingCandidates().
 *
 * Расхождение НЕ чинится автоматически, даже когда однозначно понятно,
 * какое значение "правильное" — решение пользователя: расхождение почти
 * всегда сигнал реальной проблемы выше по цепочке (пропущенное или
 * неправильно разобранное действие в *NewsImporter), а не просто
 * "устаревшие данные" — молчаливая перезапись замаскировала бы саму
 * проблему, а не только её симптом. Отчёт — первый шаг, автоматическое
 * исправление осознанно не реализовано.
 *
 * Три вида расхождений в отчёте:
 *   1. field_mismatches — эмитент есть и там, и там, но какое-то поле
 *      (rating/outlook/last_action_date) отличается.
 *   2. missing_in_ours — агентство знает эмитента, у нас в
 *      current_ratings для этой пары (issuer_id, agency) нет строки
 *      вообще (ни разу не сихронизировался, или ошибка сопоставления).
 *   3. missing_in_snapshot — у нас есть строка current_ratings для этой
 *      пары, но свежий снимок агентства этого эмитента не содержит —
 *      либо агентство сняло рейтинг и убрало из списка целиком (у НКР
 *      возможно: полное отсутствие в issuers.php), либо сбой самого
 *      снимка (не стоит принимать этот вид расхождения за чистую монету
 *      без проверки — источник мог просто не отдать часть строк).
 */
final class CurrentRatingsReconciler
{
    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /**
     * @param array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string}> $snapshot
     * @return array{
     *     snapshot_count: int,
     *     field_mismatches: array<int, array{issuer_id: int, issuer_name: string, field: string, ours: ?string, theirs: ?string}>,
     *     missing_in_ours: array<int, array{issuer_id: int, issuer_name: string}>,
     *     missing_in_snapshot: array<int, array{issuer_id: int, issuer_name: string, ours: ?string}>
     * }
     */
    public function reconcile(string $agency, array $snapshot): array
    {
        $fieldMismatches = [];
        $missingInOurs = [];
        $snapshotIssuerIds = [];

        foreach ($snapshot as $row) {
            $snapshotIssuerIds[$row['issuer_id']] = true;
            $ours = $this->fetchOurs($row['issuer_id'], $agency);

            if ($ours['rating'] === null && $ours['last_action_date'] === null) {
                // current_ratings.rating — NOT NULL в схеме: обе NULL
                // одновременно возможны ТОЛЬКО когда строки для этой пары
                // нет вообще, не как значение реальной строки.
                $missingInOurs[] = ['issuer_id' => $row['issuer_id'], 'issuer_name' => $row['issuer_name']];
                continue;
            }

            foreach (['rating', 'outlook', 'last_action_date'] as $field) {
                if ($ours[$field] !== $row[$field]) {
                    $fieldMismatches[] = [
                        'issuer_id' => $row['issuer_id'],
                        'issuer_name' => $row['issuer_name'],
                        'field' => $field,
                        'ours' => $ours[$field],
                        'theirs' => $row[$field],
                    ];
                }
            }
        }

        $missingInSnapshot = [];
        $stmt = $this->db->prepare(
            'SELECT cr.issuer_id, i.short_name, cr.rating
             FROM current_ratings cr
             JOIN issuers i ON i.id = cr.issuer_id
             WHERE cr.agency = :agency'
        );
        $stmt->execute(['agency' => $agency]);
        foreach ($stmt->fetchAll() as $row) {
            $issuerId = (int) $row['issuer_id'];
            if (!isset($snapshotIssuerIds[$issuerId])) {
                $missingInSnapshot[] = [
                    'issuer_id' => $issuerId,
                    'issuer_name' => (string) $row['short_name'],
                    'ours' => $row['rating'],
                ];
            }
        }

        return [
            'snapshot_count' => count($snapshot),
            'field_mismatches' => $fieldMismatches,
            'missing_in_ours' => $missingInOurs,
            'missing_in_snapshot' => $missingInSnapshot,
        ];
    }

    /** @return array{rating: ?string, outlook: ?string, last_action_date: ?string} */
    private function fetchOurs(int $issuerId, string $agency): array
    {
        $stmt = $this->db->prepare(
            'SELECT rating, outlook, last_action_date FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency'
        );
        $stmt->execute(['issuer_id' => $issuerId, 'agency' => $agency]);
        $row = $stmt->fetch();

        return $row !== false
            ? ['rating' => $row['rating'], 'outlook' => $row['outlook'], 'last_action_date' => $row['last_action_date']]
            : ['rating' => null, 'outlook' => null, 'last_action_date' => null];
    }
}
