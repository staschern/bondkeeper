-- =====================================================================
-- BondKeeper — миграция 025: источник значения в current_ratings
-- (сентябрь 2026)
--
-- Разбор сверки показал: все 12 случаев "у нас есть рейтинг, в снимке
-- агентства компании нет" оказались ожидаемыми — 5 рейтингов выпусков
-- облигаций из новостей и 7 строк ручного xlsx. Сверка не могла этого
-- понять, потому что не знала, откуда взято значение. Каждый писатель
-- current_ratings теперь ставит источник:
--   snapshot  — полная выгрузка агентства (NkrImporter/ExpertRaImporter/AcraImporter);
--   action    — рейтинговое действие (новости *NewsImporter и история НРА,
--               через CurrentRatingsSync);
--   manual    — ручной xlsx (ManualRatingsImporter);
--   reconcile — дописано сверкой (reconcile_ratings.php --apply-missing).
-- NULL — значение записано до этой миграции и с тех пор не менялось.
--
-- Заполнение уже существующих строк: 'action' — там, где есть
-- рейтинговое действие той же даты. Ручные строки помечаются отдельным
-- разовым скриптом (bin/apply_2026_09_review_decisions.php), остальные
-- получат источник при следующей записи.
-- =====================================================================

ALTER TABLE current_ratings
    ADD COLUMN source ENUM('snapshot','action','manual','reconcile') NULL AFTER matched_by_root_name;

UPDATE current_ratings cr
SET cr.source = 'action'
WHERE EXISTS (
    SELECT 1 FROM rating_actions ra
    WHERE ra.issuer_id = cr.issuer_id
      AND ra.agency = cr.agency
      AND ra.action_date = cr.last_action_date
);
