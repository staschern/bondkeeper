-- =====================================================================
-- BondKeeper — миграция 012: offers — заготовка под первый сев
--
-- Начинаем заполнять таблицу offers (до сих пор ни разу не сеялась).
-- Пока не парсим большинство полей (offer_type, даты объявления/периода,
-- цену, признак по инструкции, количество бумаг, статус) — честно
-- помечаем их как 'unknown'/NULL, по тому же принципу, что и
-- coupon_type в миграции 004: не угадывать, а явно говорить "не знаем".
--
-- Заодно нашлась задача от пользователя: у BUYBACKDATE (уже используется
-- как критерий securities.has_offer) и OFFERDATE (отдельное поле из
-- доска-специфичного эндпоинта, ещё не запрашивали) может быть НЕ
-- пересечение, а разные множества бумаг — где-то есть одно, где-то
-- другое, где-то оба. has_buyback_date — тот же самый флаг
-- "BUYBACKDATE IS NOT NULL", что и securities.has_offer, продублированный
-- сюда, чтобы держать оба сигнала рядом и сверить их на реальных данных
-- (см. docs/STAGE1_POSTPROCESSING.md, bin/seed_offers.php).
-- =====================================================================

ALTER TABLE offers
    MODIFY COLUMN offer_type ENUM('put', 'call', 'unknown') NOT NULL DEFAULT 'unknown',
    MODIFY COLUMN execution_date_planned DATE NULL,
    MODIFY COLUMN is_instruction_based BOOLEAN NULL,
    MODIFY COLUMN status ENUM('announced', 'in_progress', 'executed', 'not_executed', 'unknown') NOT NULL DEFAULT 'unknown',
    ADD COLUMN has_buyback_date BOOLEAN NOT NULL DEFAULT FALSE AFTER execution_date_planned,
    ADD UNIQUE KEY uq_offers_security (security_id);
