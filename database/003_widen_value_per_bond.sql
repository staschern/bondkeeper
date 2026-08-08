-- =====================================================================
-- BondKeeper — миграция 003: DECIMAL(12,4) тесновата для крупных лотов
--
-- Найдено на реальных данных (bondkeeper.ru, август 2026): у части
-- облигаций институциональных размещений (например, субординированные
-- выпуски банков — "АЛЬФАT2CR3", "БСинараС01") FACEVALUE/INITIALFACEVALUE
-- в ISS API равен ровно 100 000 000 ₽ на бумагу. DECIMAL(12,4) вмещает
-- максимум 99 999 999.9999 — ровно чуть меньше. 7 из 3061 бумаг в первом
-- полном прогоне seed_market.php упали на "Numeric value out of range"
-- (1264) именно на этом.
--
-- Расширяем везде, где сумма по природе соразмерна номиналу бумаги (не
-- только redemptions.value_per_bond, где нашли проблему, но и coupons/
-- amortizations — амортизация в последнем периоде может быть сопоставима
-- по величине с погашением, а market_data.accrued_interest — с купоном),
-- а не только там, где уже упало. DECIMAL(18,4) даёт запас на 6 порядков
-- (до ~99,99 трлн ₽ на бумагу) — с большим запасом хватит на любой
-- реалистичный номинал.
-- =====================================================================

ALTER TABLE coupons
    MODIFY COLUMN value_per_bond        DECIMAL(18,4) NOT NULL,
    MODIFY COLUMN actual_value_per_bond DECIMAL(18,4) NULL;

ALTER TABLE amortizations
    MODIFY COLUMN value_per_bond        DECIMAL(18,4) NOT NULL,
    MODIFY COLUMN actual_value_per_bond DECIMAL(18,4) NULL;

ALTER TABLE redemptions
    MODIFY COLUMN value_per_bond        DECIMAL(18,4) NOT NULL,
    MODIFY COLUMN actual_value_per_bond DECIMAL(18,4) NULL;

ALTER TABLE market_data
    MODIFY COLUMN accrued_interest DECIMAL(18,4) NULL;
