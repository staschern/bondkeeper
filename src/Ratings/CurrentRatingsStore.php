<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Чтение/запись current_ratings для новостных импортёров
 * (NkrNewsImporter/ExpertRaNewsImporter) — по прямому указанию
 * пользователя, "старый" рейтинг/прогноз для rating_actions берётся не
 * из текста новости (в тексте не всегда однозначно читается), а из
 * того, что уже сохранено в current_ratings ДО этого действия — а
 * после разбора новости current_ratings обновляется этим же новым
 * значением. current_ratings — источник истины про "текущее", а не
 * побочный продукт парсинга одной новости.
 */
final class CurrentRatingsStore
{
    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /** @return array{rating: string, outlook: ?string}|null */
    public function find(int $issuerId, string $agency): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT rating, outlook FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency'
        );
        $stmt->execute(['issuer_id' => $issuerId, 'agency' => $agency]);
        $row = $stmt->fetch();

        return $row !== false ? ['rating' => $row['rating'], 'outlook' => $row['outlook']] : null;
    }

    public function upsert(int $issuerId, string $agency, string $rating, ?string $outlook, string $lastActionDate): void
    {
        $stmt = $this->db->prepare(
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
            'rating' => mb_substr($rating, 0, 20),
            'outlook' => $outlook,
            'last_action_date' => $lastActionDate,
        ]);
    }
}
