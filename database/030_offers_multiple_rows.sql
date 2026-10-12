-- =====================================================================
-- BondKeeper — миграция 030: оферты — несколько строк на бумагу
--
-- До сих пор в offers была одна строка на бумагу (ключ uq_offers_security,
-- миграция 012): одна дата и один вид — ближайшая оферта. Вторая
-- предстоящая оферта терялась (на 11.10.2026 у 24 наших бумаг впереди
-- больше одной оферты, у двух выпусков «ГПБ Финанс» — по шестнадцать).
--
-- Теперь строка — это одна оферта: бумага + дата + вид. Подробно —
-- docs/OFFERS.md.
--
-- Что меняется:
--   1. Ключ: вместо «одна строка на бумагу» — «бумага + дата + вид».
--   2. source — откуда строка: moex (таблица оферт биржи), nsd (объявление
--      НРД), file (загрузка из файла — задел на будущее, сейчас не
--      используется).
--   3. source_ref — номер корпоративного действия у НРД (для строк nsd).
--   4. status получает значение cancelled: оферта отменена сообщением НРД.
--      Строка остаётся до своей даты, чтобы ночная загрузка с биржи не
--      вернула оферту обратно (у биржи отменённая оферта может висеть ещё
--      долго).
--   5. execution_date_planned снова обязательна: оферта без даты не
--      оферта. Строки без даты (остатки первой версии импортёра, почти
--      все — ипотечные выпуски) удаляются.
--   6. has_buyback_date удаляется: это копия поля биржи BUYBACKDATE, а оно
--      заполнено и там, где оферты нет (у всех ипотечных выпусков).
--      Значение можно в любой момент получить у биржи заново.
--
-- Повторный запуск выдаст ошибку «Can't DROP 'uq_offers_security'» — это
-- значит, что миграция уже применена.
-- =====================================================================

DELETE FROM offers WHERE execution_date_planned IS NULL;

ALTER TABLE offers
    DROP INDEX uq_offers_security,
    DROP COLUMN has_buyback_date,
    MODIFY COLUMN execution_date_planned DATE NOT NULL,
    MODIFY COLUMN status ENUM('announced', 'in_progress', 'executed', 'not_executed', 'unknown', 'cancelled') NOT NULL DEFAULT 'unknown',
    ADD COLUMN source ENUM('moex', 'nsd', 'file') NOT NULL DEFAULT 'moex' AFTER offer_type,
    ADD COLUMN source_ref VARCHAR(40) NULL AFTER source,
    ADD UNIQUE KEY uq_offers_security_date_type (security_id, execution_date_planned, offer_type);
