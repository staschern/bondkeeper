<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Общий апсерт в rating_actions — общий на НРА (история из Excel) и
 * новостные парсеры (НКР/Эксперт РА).
 *
 * Ключ апсерта — ссылка на пресс-релиз (source_url, миграция 015), не
 * (issuer_id, agency, action_date): при скользящем окне новостей (см.
 * NkrNewsImporter/ExpertRaNewsImporter) один и тот же прогон видит одну
 * и ту же новость помногу раз подряд (окно перекрывается между
 * прогонами каждые ~30 минут) — по ссылке однозначно понятно "это та
 * же самая запись", и апсерт корректно ОБНОВЛЯЕТ её, а не плодит дубли.
 *
 * $issuerId и $ratingTo — nullable: по прямому указанию пользователя,
 * если сущность или уровень рейтинга не распознались из текста новости,
 * строка всё равно пишется (с null в нераспознанных полях), а не
 * теряется молча. $unresolvedFields — список имён таких полей (для
 * ручного просмотра пользователем отдельно от автоматического потока).
 */
final class RatingActionsWriter
{
    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /** @param array<int, string> $unresolvedFields */
    public function upsert(
        ?int $issuerId,
        string $agency,
        string $actionDate,
        ?string $ratingFrom,
        ?string $ratingTo,
        ?string $outlookFrom,
        ?string $outlookTo,
        string $sourceUrl,
        array $unresolvedFields = [],
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO rating_actions
                (issuer_id, agency, action_date, rating_from, rating_to, outlook_from, outlook_to,
                 source_url, has_unresolved_fields, unresolved_fields)
             VALUES
                (:issuer_id, :agency, :action_date, :rating_from, :rating_to, :outlook_from, :outlook_to,
                 :source_url, :has_unresolved_fields, :unresolved_fields)
             ON DUPLICATE KEY UPDATE
                issuer_id = VALUES(issuer_id),
                agency = VALUES(agency),
                action_date = VALUES(action_date),
                rating_from = VALUES(rating_from),
                rating_to = VALUES(rating_to),
                outlook_from = VALUES(outlook_from),
                outlook_to = VALUES(outlook_to),
                has_unresolved_fields = VALUES(has_unresolved_fields),
                unresolved_fields = VALUES(unresolved_fields)'
        );
        $stmt->execute([
            'issuer_id' => $issuerId,
            'agency' => $agency,
            'action_date' => $actionDate,
            'rating_from' => $ratingFrom !== null ? mb_substr($ratingFrom, 0, 20) : null,
            'rating_to' => $ratingTo !== null ? mb_substr($ratingTo, 0, 20) : null,
            'outlook_from' => $outlookFrom,
            'outlook_to' => $outlookTo,
            'source_url' => mb_substr($sourceUrl, 0, 500),
            'has_unresolved_fields' => $unresolvedFields !== [] ? 1 : 0,
            'unresolved_fields' => $unresolvedFields !== [] ? mb_substr(implode(',', $unresolvedFields), 0, 255) : null,
        ]);
    }
}
