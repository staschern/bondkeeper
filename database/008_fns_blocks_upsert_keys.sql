-- =====================================================================
-- BondKeeper — миграция 008: fns_blocks — ключ для апсерта
--
-- Источник данных (подтверждено вживую, август 2026): официальный сервис
-- ФНС service.nalog.ru/bi.do, тип запроса "Запрос о действующих
-- приостановлениях операций по счетам" — POST на /bi2-proc.json,
-- requestType=FINDPRS. У одного эмитента одновременно может быть
-- активная блокировка сразу в НЕСКОЛЬКИХ банках (живой пример — ООО
-- "СЛДК", ИНН 1101148661: 6 решений в 6 разных банках одновременно).
-- Значит на схему это ложится как несколько строк fns_blocks на одного
-- эмитента, и нужен естественный ключ для апсерта при повторных
-- проверках — иначе каждый прогон будет плодить дубли тех же решений.
--
-- Ключ — (issuer_id, bank_bik, decision_number): БИК банка + номер
-- решения из ответа сервиса (поля BIK/NOMER) однозначно идентифицируют
-- конкретное решение о приостановлении. updated_at нужен, чтобы отличать
-- строки, подтверждённые последним прогоном, от тех, что больше не
-- встретились в ответе (см. FnsBlocksImporter::applyResult — такие
-- строки помечаются unblock_date = CURDATE(), а не удаляются).
-- =====================================================================

ALTER TABLE fns_blocks
    ADD COLUMN bank_bik VARCHAR(9) NULL AFTER issuer_id,
    ADD COLUMN decision_number VARCHAR(50) NULL AFTER bank_bik,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY uq_fns_blocks_issuer_bank_decision (issuer_id, bank_bik, decision_number);
