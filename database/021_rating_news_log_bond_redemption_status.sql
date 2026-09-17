-- =====================================================================
-- BondKeeper — миграция 021: новый статус rating_news_log для отзыва
-- рейтинга ВЫПУСКА облигаций из-за его погашения (17 сентября 2026).
--
-- Живая находка пользователя: "АКРА ОТОЗВАЛО КРЕДИТНЫЙ РЕЙТИНГ ВЫПУСКА
-- ОБЛИГАЦИЙ ПАО «ФОСАГРО» СЕРИИ БО-П02 (RU000A109K40) В СВЯЗИ С
-- ПОГАШЕНИЕМ ВЫПУСКА" писалось в current_ratings как обычный отзыв
-- КРЕДИТНОГО РЕЙТИНГА ЭМИТЕНТА (литерал 'отозван' на пару issuer_id+
-- agency) — хотя реальный кредитный рейтинг компании никуда не делся,
-- погасилась только одна серия облигаций. См. RatingsNormalizer::
-- isBondIssueRedemptionWithdrawal() и её использование в
-- AcraNewsImporter/NkrNewsImporter/ExpertRaNewsImporter::importRow() —
-- такие строки теперь полностью пропускаются (rating_actions/events/
-- current_ratings не создаются), но нужен новый статус в
-- rating_news_log, чтобы это тоже отметить как "обработано" (не пытаться
-- разобрать снова на следующем прогоне), отдельно от прочих skipped_*.
-- =====================================================================

ALTER TABLE rating_news_log
    MODIFY status ENUM(
        'matched',
        'skipped_not_rating',
        'skipped_no_grade',
        'skipped_unmatched',
        'skipped_non_standard',
        'skipped_bond_redemption'
    ) NOT NULL;
