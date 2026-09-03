<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Общий журнал "просмотренных" пресс-релизов (rating_news_log, миграция
 * 016) — дедуп по (agency, source_url). Изначально сделан для НКР (частый
 * автопрогон каждые 30 минут, дедуп по URL — решение пользователя), но
 * спроектирован сразу на все агентства (колонка agency) — переиспользован
 * для НРА без единой правки схемы (сентябрь 2026).
 *
 * status='matched' — обработано окончательно, не трогаем повторно; любой
 * другой статус (включая отсутствие строки) — пробуем заново на каждом
 * прогоне. Это автоматически чинит проблему "эмитента добавили в issuers
 * позже, чем впервые встретился его пресс-релиз" — пропущенное действие
 * само подхватится на следующем прогоне, без ручного полного пересмотра.
 */
final class RatingNewsLog
{
    public static function isAlreadyMatched(PDO $db, string $agency, string $sourceUrl): bool
    {
        $stmt = $db->prepare('SELECT status FROM rating_news_log WHERE agency = :agency AND source_url = :url');
        $stmt->execute(['agency' => $agency, 'url' => $sourceUrl]);

        return $stmt->fetchColumn() === 'matched';
    }

    public static function log(PDO $db, string $agency, string $sourceUrl, string $actionDate, string $status): void
    {
        $stmt = $db->prepare(
            'INSERT INTO rating_news_log (agency, source_url, action_date, status)
             VALUES (:agency, :url, :date, :status)
             ON DUPLICATE KEY UPDATE
                action_date = VALUES(action_date),
                status = VALUES(status),
                processed_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(['agency' => $agency, 'url' => $sourceUrl, 'date' => $actionDate, 'status' => $status]);
    }
}
