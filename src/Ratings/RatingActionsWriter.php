<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Events\EventPublisher;
use PDO;

/**
 * Общий апсерт в rating_actions — вынесено, чтобы НРА (история из Excel)
 * и новостные парсеры (НКР/Эксперт РА/АКРА) не дублировали один и тот же
 * SQL. Ключ апсерта — UNIQUE (issuer_id, agency, action_date), см.
 * миграцию 014.
 *
 * $sourceTitle — заголовок пресс-релиза ДОСЛОВНО, как есть, без разбора
 * (см. миграцию 015). НЕ участвует в вычислении rating_from/rating_to/
 * outlook_from/outlook_to — это отдельное, «сырое» поле для provenance/
 * аудита. NULL допустим (опциональный параметр) — источник, у которого
 * гипотетически нет текстового заголовка, не должен из-за этого падать.
 *
 * $matchedByRootName (миграция 022, сентябрь 2026) — true, если issuer_id
 * получен третьим, самым неточным уровнем сопоставления
 * (IssuerMatcher::findIssuerIdByRootName() — SPV/материнская компания по
 * "корню" названия после снятия ОПФ и маркерных слов Финанс/Капитал, см.
 * докблок IssuerMatcher). Строка при этом пишется как обычно (по решению
 * пользователя — не терять данные), просто отдельно помечается для
 * последующей выборочной проверки — см. bin/seed_ratings.php, где такие
 * строки собираются в уведомление администратору.
 *
 * === Событийный движок (Этап 4, сентябрь 2026) ===
 *
 * Сразу после апсерта — EventPublisher::publishRatingAction() (событие
 * C5 "Рейтинговое действие"). Без фильтра существенности (решение
 * пользователя) — событие создаётся ВСЕГДА, даже на "подтверждено без
 * изменений". Именно поэтому событие рождается ЗДЕСЬ, а не в отдельном
 * опросе-диффе: upsert() и так уже знает всё нужное (rating_from/
 * rating_to/outlook_from/outlook_to), выдумывать второй источник правды
 * незачем. Это единственная точка апсерта rating_actions в проекте —
 * НРА (NraImporter) тоже проведена через этот класс, а не через свой SQL,
 * иначе НРА тихо осталась бы без событий. Подробности —
 * docs/STAGE4_EVENT_ENGINE.md.
 */
final class RatingActionsWriter
{
    public function __construct(
        private readonly PDO $db,
        private readonly EventPublisher $events,
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
        bool $matchedByRootName = false,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO rating_actions
                (issuer_id, agency, action_date, rating_from, rating_to, outlook_from, outlook_to, source_url, source_title, matched_by_root_name)
             VALUES
                (:issuer_id, :agency, :action_date, :rating_from, :rating_to, :outlook_from, :outlook_to, :source_url, :source_title, :matched_by_root_name)
             ON DUPLICATE KEY UPDATE
                rating_from = VALUES(rating_from),
                rating_to = VALUES(rating_to),
                outlook_from = VALUES(outlook_from),
                outlook_to = VALUES(outlook_to),
                source_url = VALUES(source_url),
                source_title = VALUES(source_title),
                matched_by_root_name = VALUES(matched_by_root_name)'
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
            'matched_by_root_name' => $matchedByRootName ? 1 : 0,
        ]);

        $eventId = $this->events->publishRatingAction(
            $issuerId,
            $agency,
            $actionDate,
            $ratingFrom,
            $ratingTo,
            $outlookFrom,
            $outlookTo,
            $sourceUrl,
            $sourceTitle,
        );

        // rating_actions.event_id — та же строка, что мы только что
        // апсертили (ключ (issuer_id, agency, action_date) её однозначно
        // определяет), просто ссылку на событие проставляем отдельным
        // UPDATE — event_id не входит в основной INSERT, потому что на
        // момент его подготовки события ещё не существовало.
        $this->db->prepare(
            'UPDATE rating_actions SET event_id = :event_id
             WHERE issuer_id = :issuer_id AND agency = :agency AND action_date = :action_date'
        )->execute([
            'event_id' => $eventId,
            'issuer_id' => $issuerId,
            'agency' => $agency,
            'action_date' => $actionDate,
        ]);
    }
}
