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
 * ИСКЛЮЧЕНИЕ (по прямому запросу пользователя, найдено вживую на ООО
 * «ЛКХ», НКР понизило до "D") — если НОВЫЙ рейтинг дефолтный
 * (RatingsNormalizer::isDefaultGrade()), прогноз для него не
 * предусмотрен в принципе, поэтому обнуляется ВСЕГДА, даже если сам
 * кэш до этого хранил какое-то направление — см. sync().
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

    /**
     * @param array{rating: ?string, outlook: ?string, last_action_date: ?string} $cached
     * $matchedByRootName (миграция 022) — см. RatingActionsWriter::upsert():
     * та же строка, если issuer_id этого действия получен третьим,
     * "по корню" уровнем сопоставления (IssuerMatcher::findIssuerIdByRootName()).
     */
    public static function sync(
        PDO $db,
        int $issuerId,
        string $agency,
        string $actionDate,
        string $ratingTo,
        ?string $outlookTo,
        array $cached,
        bool $matchedByRootName = false,
    ): void {
        if ($cached['last_action_date'] !== null && $cached['last_action_date'] > $actionDate) {
            return;
        }

        $outlook = self::resolveOutlook($ratingTo, $outlookTo, $cached['outlook']);
        $stmt = $db->prepare(
            'INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date, matched_by_root_name)
             VALUES (:issuer_id, :agency, :rating, :outlook, :last_action_date, :matched_by_root_name)
             ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                outlook = VALUES(outlook),
                last_action_date = VALUES(last_action_date),
                matched_by_root_name = VALUES(matched_by_root_name)'
        );
        $stmt->execute([
            'issuer_id' => $issuerId,
            'agency' => $agency,
            'rating' => mb_substr($ratingTo, 0, 20),
            'outlook' => $outlook,
            'last_action_date' => $actionDate,
            'matched_by_root_name' => $matchedByRootName ? 1 : 0,
        ]);
    }

    /**
     * Вынесено отдельным чистым методом ради офлайн-теста без завязки на
     * MySQL-диалект (SQLite не понимает "ON DUPLICATE KEY UPDATE ...
     * VALUES()", тот же нюанс, что и везде в проекте, см.
     * tests/test_offers_importer.php) — см. tests/
     * test_default_grade_clears_outlook.php, вызывается через Reflection.
     */
    private static function resolveOutlook(string $ratingTo, ?string $outlookTo, ?string $cachedOutlook): ?string
    {
        return RatingsNormalizer::isDefaultGrade($ratingTo) ? null : ($outlookTo ?? $cachedOutlook);
    }
}
