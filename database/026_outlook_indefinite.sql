-- =====================================================================
-- BondKeeper — миграция 026: отдельные коды 'indefinite' («неопределённый»,
-- НКР) и 'under_review_indefinite' («рейтинг на пересмотре —
-- неопределённый прогноз», НКР) (28 сентября 2026)
--
-- Решение пользователя: «неопределённый» и «развивающийся» — разные
-- понятия, и хранить их одним кодом 'developing' нельзя. По определениям
-- самих агентств:
--   - НКР, «неопределённый» — высокая вероятность ИЗМЕНЕНИЯ рейтинга в
--     течение 12 месяцев, направление определить невозможно;
--   - Эксперт РА / АКРА / НРА, «развивающийся» — равновероятны несколько
--     исходов (сохранение, повышение, понижение).
-- Проверено на живых данных (28.09.2026): в выгрузке НКР (ratings.ru/
-- issuers.php, 260 строк) — 10 раз «неопределённый» и ни одного
-- «развивающийся»; в выгрузке НРА (958 строк) — только «Развивающийся»;
-- Эксперт РА и АКРА пишут «развивающийся». Поэтому все строки НКР с
-- 'developing' — это «неопределённый», их переводим в 'indefinite'.
--
-- Статус «рейтинг на пересмотре — неопределённый прогноз» (НКР) — это
-- статус пересмотра (= «под наблюдением», under_review) ПЛЮС направление
-- «неопределённый» → 'under_review_indefinite', по той же схеме, что
-- under_review_negative/_positive/_developing. Раньше он хранился как
-- голый 'under_review'. У НКР ровно три формулировки пересмотра
-- (проверено по 1069 заголовкам и выгрузке 28.09.2026): «с возможностью
-- понижения» (under_review_negative), «с возможностью повышения»
-- (under_review_positive), «— неопределённый прогноз». Голого «на
-- пересмотре» без направления у НКР нет — поэтому все строки НКР с
-- 'under_review' переводятся в 'under_review_indefinite'.
--
-- rating_news_log.status — два новых статуса пропуска (решение
-- пользователя, 28.09.2026: новости про облигации, не влияющие на рейтинг
-- компании, см. RatingsNormalizer::bondNewsSkipStatus()):
--   skipped_bond_not_placed — отзыв рейтинга облигаций из-за неразмещения /
--     отзыв ожидаемого рейтинга (ПСБ, Башкортостан);
--   skipped_subordinated — любая новость про субординированные облигации
--     (Банк ГПБ, ТБанк, Альфа-Банк, ПСБ);
--   skipped_expected — любая новость про ожидаемый рейтинг облигаций (выпуск
--     ещё не размещён, итоговый рейтинг придёт после размещения).
--
-- ENUM расширяется добавлением значения в конец списка — безопасно для
-- существующих строк (см. миграцию 018). Применять вместе с кодом
-- пакета 2026-09-28: RatingsNormalizer пишет 'indefinite' для
-- «неопределённый».
-- =====================================================================

ALTER TABLE rating_actions
    MODIFY COLUMN outlook_from ENUM(
        'positive','stable','negative','developing',
        'under_review','under_review_negative','under_review_positive',
        'under_review_stable','under_review_developing','review_concluded',
        'indefinite','under_review_indefinite'
    ) NULL,
    MODIFY COLUMN outlook_to ENUM(
        'positive','stable','negative','developing',
        'under_review','under_review_negative','under_review_positive',
        'under_review_stable','under_review_developing','review_concluded',
        'indefinite','under_review_indefinite'
    ) NULL;

ALTER TABLE current_ratings
    MODIFY COLUMN outlook ENUM(
        'positive','stable','negative','developing',
        'under_review','under_review_negative','under_review_positive',
        'under_review_stable','under_review_developing','review_concluded',
        'indefinite','under_review_indefinite'
    ) NULL;

UPDATE current_ratings SET outlook = 'indefinite' WHERE agency = 'nkr' AND outlook = 'developing';
UPDATE rating_actions SET outlook_from = 'indefinite' WHERE agency = 'nkr' AND outlook_from = 'developing';
UPDATE rating_actions SET outlook_to = 'indefinite' WHERE agency = 'nkr' AND outlook_to = 'developing';

UPDATE current_ratings SET outlook = 'under_review_indefinite' WHERE agency = 'nkr' AND outlook = 'under_review';
UPDATE rating_actions SET outlook_from = 'under_review_indefinite' WHERE agency = 'nkr' AND outlook_from = 'under_review';
UPDATE rating_actions SET outlook_to = 'under_review_indefinite' WHERE agency = 'nkr' AND outlook_to = 'under_review';

ALTER TABLE rating_news_log
    MODIFY status ENUM(
        'matched',
        'skipped_not_rating',
        'skipped_no_grade',
        'skipped_unmatched',
        'skipped_non_standard',
        'skipped_bond_redemption',
        'skipped_bond_not_placed',
        'skipped_subordinated',
        'skipped_expected'
    ) NOT NULL;
