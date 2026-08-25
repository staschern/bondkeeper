-- =====================================================================
-- BondKeeper — миграция 011: верификация ФНС переезжает на fns_blocks
--
-- После миграции 010 fns_blocks стала одной строкой на эмитента — то же
-- самое место, где естественно жить состоянию "когда и чем закончилась
-- проверка", а не issuers. Переносим is_fns_blocked/verification/
-- date_verification/last_success_verification с issuers на fns_blocks.
--
-- Раз verification/date_verification теперь пишутся при КАЖДОЙ попытке
-- (включая капчу/сетевую ошибку — см. FnsBlocksImporter::markVerificationError),
-- а не только когда есть реальная блокировка, строка в fns_blocks должна
-- существовать для любого проверенного эмитента, а не только для тех, у
-- кого блокировка когда-либо была. Поэтому block_date (раньше NOT NULL)
-- становится нулабельным, а active_bank_count по умолчанию 0, а не 1 —
-- "проверили, блокировок нет" тоже валидное состояние строки.
-- =====================================================================

ALTER TABLE issuers
    DROP COLUMN is_fns_blocked,
    DROP COLUMN verification,
    DROP COLUMN date_verification,
    DROP COLUMN last_success_verification;

ALTER TABLE fns_blocks
    MODIFY COLUMN block_date DATE NULL,
    MODIFY COLUMN active_bank_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN is_fns_blocked BOOLEAN NOT NULL DEFAULT FALSE AFTER issuer_id,
    ADD COLUMN verification ENUM('success', 'error') NULL AFTER is_fns_blocked,
    ADD COLUMN date_verification DATETIME NULL AFTER verification,
    ADD COLUMN last_success_verification DATETIME NULL AFTER date_verification;
