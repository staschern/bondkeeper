<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Общий апсерт в rating_actions — вынесено, чтобы НРА (история из Excel)
 * и новостные парсеры (НКР/Эксперт РА) не дублировали один и тот же SQL.
 * Ключ апсерта — UNIQUE (issuer_id, agency, action_date), см. миграцию 014.
 *
 * $sourceTitle — заголовок пресс-релиза ДОСЛОВНО, как есть, без разбора
 * (см. миграцию 015). НЕ участвует в вычислении rating_from/rating_to/
 * outlook_from/outlook_to — это отдельное, «сырое» поле для provenance/
 * аудита. NULL допустим (опциональный параметр) — источник, у которого
 * гипотетически нет текстового заголовка, не должен из-за этого падать.
 */
final class RatingActionsWriter
{
    public function __construct(
        private readonly PDO $db,
    ) {
    }

    public function upsert(
        int $issuerId,
        string $agency,
        string $actionDate,
        ?string $ratingFrom,
        string $ratingTo,
        ?string $outlookFrom,
        ?string $outlookTo,
        ?string $sourceUrl,
        ?string $sourceTitle = null,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO rating_actions
                (issuer_id, agency, action_date, rating_from, rating_to, outlook_from, outlook_to, source_url, source_title)
             VALUES
                (:issuer_id, :agency, :action_date, :rating_from, :rating_to, :outlook_from, :outlook_to, :source_url, :source_title)
             ON DUPLICATE KEY UPDATE
                rating_from = VALUES(rating_from),
                rating_to = VALUES(rating_to),
                outlook_from = VALUES(outlook_from),
                outlook_to = VALUES(outlook_to),
                source_url = VALUES(source_url),
                source_title = VALUES(source_title)'
        );
        $stmt->execute([
            'issuer_id' => $issuerId,
            'agency' => $agency,
            'action_date' => $actionDate,
            'rating_from' => $ratingFrom !== null ? mb_substr($ratingFrom, 0, 20) : null,
            'rating_to' => mb_substr($ratingTo, 0, 20),
            'outlook_from' => $outlookFrom,
            'outlook_to' => $outlookTo,
            'source_url' => $sourceUrl !== null ? mb_substr($sourceUrl, 0, 500) : null,
            'source_title' => $sourceTitle !== null ? mb_substr($sourceTitle, 0, 500) : null,
        ]);
    }
}
