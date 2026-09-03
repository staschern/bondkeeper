<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Общая логика чтения/обновления кэша current_ratings — используется
 * всеми news-импортёрами, которые пишут историю в rating_actions построчно
 * (NkrNewsImporter, NraImporter; в будущем — остальные агентства). Раньше
 * жила приватными методами внутри NkrNewsImporter — вынесена сюда, когда
 * NraImporter потребовал ровно ту же логику 1:1 (сентябрь 2026).
 *
 * Приём: перед записью нового действия читаем ТЕКУЩЕЕ состояние кэша —
 * это и есть outlook_from/rating_from "по умолчанию" (см. вызывающий код
 * каждого импортёра). После записи — обновляем кэш новым значением, но
 * ТОЛЬКО если оно не старше уже сохранённого (защита от порчи кэша, если
 * в одном прогоне вперемешку обрабатываются старые пропущенные действия
 * и свежие — работает корректно только если сами действия обрабатываются
 * в ХРОНОЛОГИЧЕСКОМ порядке, это ответственность вызывающего кода).
 * Если действие не называет новый прогноз явно (outlookTo = NULL) —
 * прежнее значение в кэше не затирается NULL'ом, остаётся как было.
 */
final class CurrentRatingsSync
{
    /** @return array{rating: ?string, outlook: ?string, last_action_date: ?string} */
    public static function fetch(PDO $db, int $issuerId, string $agency): array
    {
        $stmt = $db->prepare(
            'SELECT rating, outlook, last_action_date FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency'
        );
        $stmt->execute(['issuer_id' => $issuerId, 'agency' => $agency]);
        $row = $stmt->fetch();

        return $row !== false
            ? ['rating' => $row['rating'], 'outlook' => $row['outlook'], 'last_action_date' => $row['last_action_date']]
            : ['rating' => null, 'outlook' => null, 'last_action_date' => null];
    }

    /** @param array{rating: ?string, outlook: ?string, last_action_date: ?string} $cached */
    public static function sync(
        PDO $db,
        int $issuerId,
        string $agency,
        string $actionDate,
        string $ratingTo,
        ?string $outlookTo,
        array $cached,
    ): void {
        if ($cached['last_action_date'] !== null && $cached['last_action_date'] > $actionDate) {
            return;
        }

        $outlook = $outlookTo ?? $cached['outlook'];
        $stmt = $db->prepare(
            'INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date)
             VALUES (:issuer_id, :agency, :rating, :outlook, :last_action_date)
             ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                outlook = VALUES(outlook),
                last_action_date = VALUES(last_action_date)'
        );
        $stmt->execute([
            'issuer_id' => $issuerId,
            'agency' => $agency,
            'rating' => mb_substr($ratingTo, 0, 20),
            'outlook' => $outlook,
            'last_action_date' => $actionDate,
        ]);
    }
}
