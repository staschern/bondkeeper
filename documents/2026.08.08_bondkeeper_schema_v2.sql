-- =====================================================================
-- BondKeeper — схема базы данных MySQL 8.0+  (v2, после детального разбора)
-- Сервис мониторинга событий и рисков по корпоративным облигациям РФ
--
-- v2 включает 59 пунктов изменений, накопленных в ходе построчного
-- разбора каждой таблицы, полную переномерацию таксономии событий
-- (A1-A8 вместо A1-A6, 24 типа событий вместо 22), а также два
-- дополнительных решения по итогам финального разбора: current_ratings
-- вместо current_rating/current_rating_agency в issuers, и retry_count
-- в raw_messages.
--
-- Кластеры:
--   1. Справочные данные   — issuers, securities, coupons, amortizations,
--                             offers, redemptions, market_data
--   2. Событийный движок    — event_types, events, event_stories,
--                             raw_messages, rating_actions, fns_blocks,
--                             current_ratings
--   3. Пользователи/коммерция — users, tariffs, subscriptions, payments,
--                             watchlist, notifications
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- КЛАСТЕР 1. СПРАВОЧНЫЕ ДАННЫЕ ПО БУМАГАМ
-- =====================================================================

CREATE TABLE issuers (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inn                   VARCHAR(12)     NOT NULL,
    ogrn                  VARCHAR(15)     NULL,
    full_name             VARCHAR(500)    NOT NULL,
    short_name            VARCHAR(255)    NOT NULL,
    okved_code            VARCHAR(10)     NULL,
    is_fns_blocked        BOOLEAN         NOT NULL DEFAULT FALSE,
    fns_last_checked_at   DATETIME        NULL,                      -- [32] когда модуль ФНС последний раз проверял этого эмитента
    is_in_risk_sector     BOOLEAN         NOT NULL DEFAULT FALSE,    -- сектор повышенного инвестриска Мосбиржи
    -- current_rating/current_rating_agency сознательно убраны: у эмитента
    -- может быть несколько параллельных рейтингов от разных агентств.
    -- Текущий рейтинг по каждому агентству — см. таблицу current_ratings.
    created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_issuers_inn (inn),
    KEY idx_issuers_short_name (short_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE securities (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    isin                    CHAR(12)        NOT NULL,
    secid                   VARCHAR(20)     NULL,                    -- [1] точный биржевой торговый код (SECID из ISS API)
    reg_number               VARCHAR(50)     NULL,
    issuer_id                BIGINT UNSIGNED NOT NULL,
    short_name                VARCHAR(255)    NOT NULL,
    full_name                  VARCHAR(500)    NULL,
    nominal_value                DECIMAL(15,2)   NOT NULL,
    currency                      CHAR(3)         NOT NULL DEFAULT 'RUB',
    trading_start_date            DATE            NULL,               -- [8] переименовано из issue_date: день старта торгов на бирже
    maturity_date                  DATE            NULL,
    initial_issue_volume            DECIMAL(18,2)   NULL,
    listing_level                     TINYINT UNSIGNED NULL,
    moex_board                         VARCHAR(20)     NULL,
    is_amortized                        BOOLEAN         NOT NULL DEFAULT FALSE,
    has_offer                            BOOLEAN         NOT NULL DEFAULT FALSE,
    security_type                          ENUM('corporate','ofz','municipal','short_term') NOT NULL DEFAULT 'corporate',
    is_high_yield                           BOOLEAN         NOT NULL DEFAULT FALSE,
    coupon_type                              ENUM('fixed','floating','indexed','zero_coupon') NOT NULL DEFAULT 'fixed',  -- [2]
    coupon_frequency                          ENUM('monthly','quarterly','semi_annual','annual') NULL,                  -- [6]
    is_subordinated                            BOOLEAN         NOT NULL DEFAULT FALSE,  -- [3]
    is_structured                               BOOLEAN         NOT NULL DEFAULT FALSE,  -- [4]
    is_qualified_investors_only                  BOOLEAN         NOT NULL DEFAULT FALSE,  -- [5]
    -- Поля [9-11]: заполняются только когда coupon_type = 'floating'
    floater_base_rate                             ENUM('ruonia','key_rate','other') NULL,
    floater_spread_percent                          DECIMAL(6,3)    NULL,
    floater_formula_note                             VARCHAR(500)    NULL,
    status                                            ENUM('active','matured','tech_default','default','delisted') NOT NULL DEFAULT 'active',
    last_synced_at                                     DATETIME        NULL,
    created_at                                          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                                           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_securities_isin (isin),
    KEY idx_securities_issuer (issuer_id),
    KEY idx_securities_status (status),
    KEY idx_securities_maturity (maturity_date),
    CONSTRAINT fk_securities_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE RESTRICT,
    CONSTRAINT chk_securities_floater CHECK (
        (coupon_type = 'floating') OR
        (floater_base_rate IS NULL AND floater_spread_percent IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupons (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    security_id           BIGINT UNSIGNED NOT NULL,
    issuer_id             BIGINT UNSIGNED NOT NULL,                  -- [14]
    coupon_number         SMALLINT UNSIGNED NOT NULL,
    period_start_date     DATE            NULL,
    period_end_date       DATE            NOT NULL,
    rate_percent          DECIMAL(7,4)    NULL,
    value_per_bond        DECIMAL(12,4)   NOT NULL,
    actual_value_per_bond DECIMAL(12,4)   NULL,
    full_default_date_planned DATE        NULL,                      -- [13]
    status                ENUM('planned','paid','partial','not_paid','tech_default') NOT NULL DEFAULT 'planned',
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coupons_security_number (security_id, coupon_number),
    KEY idx_coupons_period_end (period_end_date),
    KEY idx_coupons_status (status),
    KEY idx_coupons_issuer_date (issuer_id, period_end_date),         -- [14]
    CONSTRAINT fk_coupons_security FOREIGN KEY (security_id) REFERENCES securities(id) ON DELETE CASCADE,
    CONSTRAINT fk_coupons_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE amortizations (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    security_id           BIGINT UNSIGNED NOT NULL,
    issuer_id             BIGINT UNSIGNED NOT NULL,                  -- [15]
    payment_date_planned  DATE            NOT NULL,
    value_per_bond        DECIMAL(12,4)   NOT NULL,
    actual_value_per_bond DECIMAL(12,4)   NULL,
    percent_of_nominal    DECIMAL(6,3)    NULL,
    full_default_date_planned DATE        NULL,                      -- [16]
    status                ENUM('planned','paid','partial','not_paid','tech_default') NOT NULL DEFAULT 'planned',
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_amortizations_security (security_id),
    KEY idx_amortizations_issuer_date (issuer_id, payment_date_planned),  -- [15]
    CONSTRAINT fk_amortizations_security FOREIGN KEY (security_id) REFERENCES securities(id) ON DELETE CASCADE,
    CONSTRAINT fk_amortizations_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Погашение — обычно одна строка на бумагу (scheduled_maturity), но при
-- срабатывании C3 (право на досрочное погашение из-за дефолта по другому
-- выпуску того же эмитента) добавляется вторая строка (early_c3_triggered),
-- не заменяющая первую.
CREATE TABLE redemptions (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    security_id              BIGINT UNSIGNED NOT NULL,
    issuer_id                BIGINT UNSIGNED NOT NULL,
    payment_date_planned     DATE            NOT NULL,
    value_per_bond           DECIMAL(12,4)   NOT NULL,                -- остаток номинала после всех амортизаций, не весь номинал
    actual_value_per_bond    DECIMAL(12,4)   NULL,
    full_default_date_planned DATE           NULL,
    redemption_type          ENUM('scheduled_maturity','early_c3_triggered') NOT NULL DEFAULT 'scheduled_maturity',  -- [57]
    triggered_by_event_id    BIGINT UNSIGNED NULL,                    -- [58] заполнено только для early_c3_triggered
    status                   ENUM('planned','paid','partial','not_paid','tech_default') NOT NULL DEFAULT 'planned',
    created_at                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_redemptions_security (security_id),
    KEY idx_redemptions_issuer_date (issuer_id, payment_date_planned),
    CONSTRAINT fk_redemptions_security FOREIGN KEY (security_id) REFERENCES securities(id) ON DELETE CASCADE,
    CONSTRAINT fk_redemptions_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE,
    CONSTRAINT fk_redemptions_triggered_event FOREIGN KEY (triggered_by_event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE offers (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    security_id            BIGINT UNSIGNED NOT NULL,
    issuer_id              BIGINT UNSIGNED NOT NULL,                  -- [17]
    offer_type             ENUM('put','call') NOT NULL,
    announcement_date      DATE            NULL,
    period_start           DATE            NULL,
    period_end             DATE            NULL,
    execution_date_planned DATE            NOT NULL,
    price_percent          DECIMAL(6,3)    NULL,
    is_instruction_based    BOOLEAN         NOT NULL DEFAULT FALSE,
    bonds_submitted          BIGINT UNSIGNED NULL,                    -- [18]
    bonds_redeemed            BIGINT UNSIGNED NULL,                    -- [19]
    status                     ENUM('announced','in_progress','executed','not_executed') NOT NULL DEFAULT 'announced',
    created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_offers_security (security_id),
    KEY idx_offers_issuer_date (issuer_id, execution_date_planned),    -- [17]
    CONSTRAINT fk_offers_security FOREIGN KEY (security_id) REFERENCES securities(id) ON DELETE CASCADE,
    CONSTRAINT fk_offers_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Рыночные данные — растущий во времени журнал (INSERT, не UPDATE).
-- snapshot_time без DEFAULT намеренно: приложение обязано явно передавать
-- плановую отметку сетки расписания (не факт запуска джоба), иначе
-- UNIQUE-защита от дублей теряет смысл.
CREATE TABLE market_data (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    security_id        BIGINT UNSIGNED NOT NULL,
    snapshot_time       DATETIME        NOT NULL,
    price_percent        DECIMAL(7,3)    NULL,
    accrued_interest      DECIMAL(12,4)   NULL,
    yield_percent          DECIMAL(7,3)    NULL,                       -- доходность к БЛИЖАЙШЕМУ определяющему событию
    yield_target_event      ENUM('maturity','offer') NULL,             -- к чему именно посчитана доходность
    duration_days             DECIMAL(7,2)    NULL,
    source                      ENUM('moex_iss','internal_calc','other') NOT NULL DEFAULT 'moex_iss',
    created_at                   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_market_data_security_time (security_id, snapshot_time),
    KEY idx_market_data_security (security_id),
    CONSTRAINT fk_market_data_security FOREIGN KEY (security_id) REFERENCES securities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- КЛАСТЕР 2. СОБЫТИЙНЫЙ ДВИЖОК (таксономия A1-A8, B1-B5(+B2a), C1-C5, D1-D2, E1-E3)
-- =====================================================================

CREATE TABLE event_types (
    code                VARCHAR(10)     NOT NULL PRIMARY KEY,
    group_code          CHAR(1)         NOT NULL,
    name_ru             VARCHAR(255)    NOT NULL,
    description         TEXT            NULL,
    primary_source       ENUM('nsd','e_disclosure','fns','rating_agency','moex','internal_calc') NOT NULL,
    default_priority     ENUM('info','green','yellow','red','critical') NOT NULL DEFAULT 'info',
    is_derived           BOOLEAN         NOT NULL DEFAULT FALSE,
    notify_client         BOOLEAN         NOT NULL DEFAULT TRUE,
    is_active              BOOLEAN         NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE raw_messages (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source              ENUM('nsd','e_disclosure','fns','rating_agency','moex') NOT NULL,
    source_ref          VARCHAR(255)    NULL,
    isin                CHAR(12)        NULL,
    raw_payload         JSON            NOT NULL,
    fetched_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at        DATETIME        NULL,
    processing_status   ENUM('new','processed','failed','ignored') NOT NULL DEFAULT 'new',
    processing_error    TEXT            NULL,
    retry_count         INT UNSIGNED    NOT NULL DEFAULT 0,
    last_retry_at       DATETIME        NULL,
    KEY idx_raw_messages_status (processing_status),
    KEY idx_raw_messages_isin (isin),
    KEY idx_raw_messages_source_ref (source, source_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_stories (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    security_id         BIGINT UNSIGNED NOT NULL,
    coupon_id           BIGINT UNSIGNED NULL,
    amortization_id     BIGINT UNSIGNED NULL,
    redemption_id       BIGINT UNSIGNED NULL,
    title               VARCHAR(255)    NOT NULL,
    status              ENUM('open','resolved') NOT NULL DEFAULT 'open',
    resolution_type     ENUM('paid','defaulted') NULL,
    opened_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at         DATETIME        NULL,
    KEY idx_event_stories_security (security_id),
    KEY idx_event_stories_status (status),
    CONSTRAINT fk_event_stories_security FOREIGN KEY (security_id) REFERENCES securities(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_stories_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
    CONSTRAINT fk_event_stories_amortization FOREIGN KEY (amortization_id) REFERENCES amortizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_event_stories_redemption FOREIGN KEY (redemption_id) REFERENCES redemptions(id) ON DELETE SET NULL,
    CONSTRAINT chk_event_stories_exactly_one CHECK (
        (coupon_id IS NOT NULL AND amortization_id IS NULL AND redemption_id IS NULL) OR
        (coupon_id IS NULL AND amortization_id IS NOT NULL AND redemption_id IS NULL) OR
        (coupon_id IS NULL AND amortization_id IS NULL AND redemption_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    security_id         BIGINT UNSIGNED NULL,
    issuer_id           BIGINT UNSIGNED NOT NULL,
    event_type_code     VARCHAR(10)     NOT NULL,
    story_id            BIGINT UNSIGNED NULL,
    raw_message_id      BIGINT UNSIGNED NULL,
    related_event_id    BIGINT UNSIGNED NULL,
    event_date          DATE            NOT NULL,
    published_at        DATETIME        NULL,
    detected_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    amount_planned      DECIMAL(15,4)   NULL,
    amount_actual       DECIMAL(15,4)   NULL,
    status_text         VARCHAR(255)    NULL,
    priority             ENUM('info','green','yellow','red','critical') NOT NULL DEFAULT 'info',
    payload_json          JSON            NULL,
    KEY idx_events_security_date (security_id, event_date),
    KEY idx_events_issuer_date (issuer_id, event_date),
    KEY idx_events_type (event_type_code),
    KEY idx_events_story (story_id),
    CONSTRAINT fk_events_security FOREIGN KEY (security_id) REFERENCES securities(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_type FOREIGN KEY (event_type_code) REFERENCES event_types(code) ON DELETE RESTRICT,
    CONSTRAINT fk_events_story FOREIGN KEY (story_id) REFERENCES event_stories(id) ON DELETE SET NULL,
    CONSTRAINT fk_events_raw_message FOREIGN KEY (raw_message_id) REFERENCES raw_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_events_related FOREIGN KEY (related_event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rating_actions (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issuer_id                 BIGINT UNSIGNED NOT NULL,
    event_id                  BIGINT UNSIGNED NULL,
    agency                    ENUM('nra','acra','expert_ra','nkr') NOT NULL,
    action_date                DATE            NOT NULL,
    rating_from                 VARCHAR(20)     NULL,
    rating_to                    VARCHAR(20)     NOT NULL,
    outlook                        ENUM('positive','stable','negative','developing') NULL,
    review_type                     ENUM('scheduled','reactive') NOT NULL,
    related_default_event_id          BIGINT UNSIGNED NULL,
    source_url                          VARCHAR(500)    NULL,
    created_at                            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rating_actions_issuer (issuer_id),
    KEY idx_rating_actions_date (action_date),
    CONSTRAINT fk_rating_actions_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE,
    CONSTRAINT fk_rating_actions_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
    CONSTRAINT fk_rating_actions_related_default FOREIGN KEY (related_default_event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кэш "текущего" рейтинга по каждой паре (эмитент, агентство) — составной
-- первичный ключ корректно поддерживает несколько параллельных рейтингов
-- от разных агентств одновременно (в отличие от прежних issuers.current_rating/
-- current_rating_agency). Поддерживается через INSERT ... ON DUPLICATE KEY
-- UPDATE при каждой новой записи в rating_actions — не переключается в обе
-- стороны, как is_fns_blocked, а просто всегда перезаписывается последним
-- значением, поэтому риск рассинхронизации ниже.
CREATE TABLE current_ratings (
    issuer_id        BIGINT UNSIGNED NOT NULL,
    agency           ENUM('nra','acra','expert_ra','nkr') NOT NULL,
    rating           VARCHAR(20)     NOT NULL,
    outlook          ENUM('positive','stable','negative','developing') NULL,
    last_action_date DATE            NOT NULL,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (issuer_id, agency),
    CONSTRAINT fk_current_ratings_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fns_blocks (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issuer_id           BIGINT UNSIGNED NOT NULL,
    event_id            BIGINT UNSIGNED NULL,
    block_date          DATE            NOT NULL,
    unblock_date         DATE            NULL,
    blocked_amount        DECIMAL(15,2)   NULL,
    reason                  VARCHAR(500)    NULL,
    reason_category           ENUM('tax_debt','account_arrest','other') NULL,
    source_reference             VARCHAR(255)    NULL,
    created_at                     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fns_blocks_issuer (issuer_id),
    CONSTRAINT fk_fns_blocks_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE,
    CONSTRAINT fk_fns_blocks_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- КЛАСТЕР 3. ПОЛЬЗОВАТЕЛИ И КОММЕРЦИЯ
-- =====================================================================

CREATE TABLE users (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id           BIGINT          NOT NULL,
    telegram_username     VARCHAR(64)     NULL,
    telegram_bot_blocked  BOOLEAN         NOT NULL DEFAULT FALSE,
    email                  VARCHAR(255)    NULL,
    phone                    VARCHAR(20)     NULL,
    status                     ENUM('active','banned_by_us','deleted') NOT NULL DEFAULT 'active',
    registered_at                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_active_at                  DATETIME        NULL,               -- обновляется: открытие уведомления ИЛИ сообщение боту
    first_paid_at                     DATETIME        NULL,               -- заполняется 1 раз, при первом успешном платеже
    referred_by_user_id                 BIGINT UNSIGNED NULL,
    own_referral_code                     VARCHAR(50)     NULL,
    used_referral_code                       VARCHAR(50)     NULL,
    utm_source                                 VARCHAR(100)    NULL,
    utm_medium                                   VARCHAR(100)    NULL,
    utm_campaign                                   VARCHAR(100)    NULL,
    utm_content                                      VARCHAR(150)    NULL,
    utm_term                                           VARCHAR(150)    NULL,
    UNIQUE KEY uq_users_telegram_id (telegram_id),
    UNIQUE KEY uq_users_own_referral_code (own_referral_code),
    KEY idx_users_status (status),
    CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- code — первичный ключ (естественный, не суррогатный): небольшой,
-- редко меняющийся справочник, тот же паттерн, что у event_types.code
CREATE TABLE tariffs (
    code                   VARCHAR(20)     NOT NULL PRIMARY KEY,
    name                   VARCHAR(100)    NOT NULL,
    price_rub              DECIMAL(10,2)   NOT NULL DEFAULT 0,
    max_tracked_issuers     INT UNSIGNED    NULL,            -- NULL = без ограничения; считается по DISTINCT issuer_id в watchlist
    duration_days             INT UNSIGNED    NOT NULL DEFAULT 30,
    is_active                   BOOLEAN         NOT NULL DEFAULT TRUE,
    created_at                    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only: новая строка при КАЖДОМ продлении и при КАЖДОЙ смене тарифа.
-- status='active' — ровно у одной, последней по времени строки пользователя.
-- Внутри одной подписки (смена статуса без смены тарифа, напр. отмена) —
-- обновление существующей строки, а не новая запись.
-- Для бессрочных (free) подписок: current_period_end = '9999-12-31'
-- (максимум типа DATE в MySQL, осознанный sentinel-маркер "бессрочно").
CREATE TABLE subscriptions (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                BIGINT UNSIGNED NOT NULL,
    tariff_code            VARCHAR(20)     NOT NULL,
    status                  ENUM('trial','active','past_due','canceled','expired') NOT NULL DEFAULT 'trial',
    auto_renew                BOOLEAN         NOT NULL DEFAULT FALSE,
    started_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    current_period_end            DATETIME        NULL,
    canceled_at                      DATETIME        NULL,
    canceled_by                        ENUM('user','admin','system') NULL,
    created_at                           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_subscriptions_user (user_id),
    KEY idx_subscriptions_status (status),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_tariff FOREIGN KEY (tariff_code) REFERENCES tariffs(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                BIGINT UNSIGNED NOT NULL,
    subscription_id         BIGINT UNSIGNED NULL,
    amount                    DECIMAL(10,2)   NOT NULL,
    currency                    CHAR(3)         NOT NULL DEFAULT 'RUB',
    payment_method                 ENUM('card','sbp','other') NULL,
    provider_fee_amount              DECIMAL(10,2)   NULL,
    net_amount                         DECIMAL(10,2)   NULL,
    status                               ENUM('pending','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
    provider                              VARCHAR(50)     NULL,
    provider_payment_id                     VARCHAR(255)    NULL,
    fiscal_receipt_url                        VARCHAR(500)    NULL,
    paid_at                                     DATETIME        NULL,
    created_at                                    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payments_provider_payment (provider, provider_payment_id),
    KEY idx_payments_user (user_id),
    KEY idx_payments_status (status),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- issuer_id заполнен ВСЕГДА (в т.ч. при слежении за конкретной бумагой) —
-- так лимит тарифа считается простым COUNT(DISTINCT issuer_id) без join.
-- issuer_only_key — вычисляемая колонка для условной уникальности:
-- защищает от дублей "слежу за эмитентом X целиком" дважды, но не мешает
-- отслеживать несколько разных бумаг одного и того же эмитента.
-- Удаление записи — обычный DELETE, без soft-delete и без истории
-- (см. отдельное обсуждение — это настройка, не бизнес-критичные данные).
CREATE TABLE watchlist (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                BIGINT UNSIGNED NOT NULL,
    issuer_id               BIGINT UNSIGNED NOT NULL,
    security_id               BIGINT UNSIGNED NULL,
    issuer_only_key              BIGINT UNSIGNED GENERATED ALWAYS AS (
                                      CASE WHEN security_id IS NULL THEN issuer_id ELSE NULL END
                                  ) STORED,
    added_at                        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_watchlist_issuer_only (user_id, issuer_only_key),
    UNIQUE KEY uq_watchlist_user_security (user_id, security_id),
    KEY idx_watchlist_issuer (issuer_id),
    CONSTRAINT fk_watchlist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_watchlist_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE,
    CONSTRAINT fk_watchlist_security FOREIGN KEY (security_id) REFERENCES securities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                BIGINT UNSIGNED NOT NULL,
    event_id                BIGINT UNSIGNED NOT NULL,
    channel                  ENUM('telegram','email','web') NOT NULL DEFAULT 'telegram',
    status                     ENUM('queued','sent','failed','read') NOT NULL DEFAULT 'queued',
    -- status='read' практически недостижим для channel='telegram' — Bot API
    -- не даёт read receipt без доп. инженерных ухищрений (кнопка + callback).
    -- Для channel='web' — единственный канал с надёжным отслеживанием.
    failure_reason              VARCHAR(255)    NULL,
    sent_at                        DATETIME        NULL,
    read_at                          DATETIME        NULL,
    created_at                         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notifications_user_event_channel (user_id, event_id, channel),
    KEY idx_notifications_status (status),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;


-- =====================================================================
-- НАПОЛНЕНИЕ event_types — переномерованная таксономия A1-A8 (24 типа)
-- =====================================================================

INSERT INTO event_types (code, group_code, name_ru, primary_source, default_priority, is_derived, notify_client, is_active) VALUES
('A1',  'A', 'Установлена ставка купона',                          'nsd',           'info',     FALSE, TRUE,  TRUE),
('A2',  'A', 'Купон получен НРД',                                   'nsd',           'green',    FALSE, TRUE,  TRUE),
('A3',  'A', 'Купон передан депонентам',                             'nsd',           'green',    FALSE, FALSE, TRUE),
('A4',  'A', 'Амортизация получена НРД',                              'nsd',           'info',     FALSE, TRUE,  TRUE),
('A5',  'A', 'Амортизация передана депонентам',                        'nsd',           'info',     FALSE, FALSE, TRUE),
('A6',  'A', 'Погашение получено НРД',                                  'nsd',           'info',     FALSE, TRUE,  TRUE),
('A7',  'A', 'Погашение передано депонентам',                            'nsd',           'info',     FALSE, FALSE, TRUE),
('A8',  'A', 'Сущфакт «Выплаченные доходы»',                              'e_disclosure',  'info',     FALSE, FALSE, TRUE),
('B1',  'B', 'Частичная выплата',                                          'nsd',           'red',      FALSE, TRUE,  TRUE),
('B2',  'B', 'Невыплата в срок',                                             'nsd',           'red',      FALSE, TRUE,  TRUE),
('B2a', 'B', 'Нет сообщения «О получении» к концу дня Т',                     'internal_calc', 'yellow',   TRUE,  TRUE,  TRUE),
('B3',  'B', 'Сущфакт «Неисполнение обязательств» (техдефолт)',                'e_disclosure',  'red',      FALSE, TRUE,  TRUE),
('B4',  'B', 'Доплата (транш)',                                                 'nsd',           'yellow',   FALSE, TRUE,  TRUE),
('B5',  'B', 'Выход из техдефолта',                                              'nsd',           'green',    FALSE, TRUE,  TRUE),
('C1',  'C', 'Счётчик до дефолта',                                                'internal_calc', 'info',     TRUE,  TRUE,  TRUE),
('C2',  'C', 'Полный дефолт',                                                      'e_disclosure',  'critical', FALSE, TRUE,  TRUE),
('C3',  'C', 'Право требовать досрочного погашения',                               'internal_calc', 'red',      TRUE,  TRUE,  TRUE),
('C4',  'C', 'Действия биржи (сектор ПИР, режим «Д»)',                              'moex',          'red',      FALSE, TRUE,  TRUE),
('C5',  'C', 'Рейтинговое действие',                                                  'rating_agency', 'yellow',   FALSE, TRUE,  TRUE),
('D1',  'D', 'Объявление оферты/выкупа',                                               'nsd',           'info',     FALSE, TRUE,  TRUE),
('D2',  'D', 'Неисполнение оферты',                                                      'e_disclosure',  'red',      FALSE, TRUE,  TRUE),
('E1',  'E', 'Блокировка счетов ФНС',                                                      'fns',           'yellow',   FALSE, TRUE,  TRUE),
('E2',  'E', 'ОСВО / реструктуризация',                                                      'e_disclosure',  'yellow',   FALSE, TRUE,  TRUE),
('E3',  'E', 'Каскад по эмитенту',                                                             'internal_calc', 'red',      TRUE,  TRUE,  TRUE);

-- Базовые тарифы (code — первичный ключ). duration_days у 'free' условен —
-- current_period_end для бессрочных подписок всегда ставится как
-- '9999-12-31' напрямую в subscriptions, не вычисляется из этого поля.
INSERT INTO tariffs (code, name, price_rub, max_tracked_issuers, duration_days) VALUES
('free',   'Free',   0,    3,    36500),
('basic',  'Basic',  890,  20,   30),
('pro',    'Pro',    2990, 100,  30),
('expert', 'Expert', 5990, NULL, 30);
