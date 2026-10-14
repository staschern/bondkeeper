-- =====================================================================
-- BondKeeper — миграция 034: партнёрская программа, промокоды
-- (решения пользователя от 13.10.2026, docs/PARTNER_PROGRAM.md)
--
-- 1. partners — кто партнёр и какой у него промокод. Партнёр — это
--    пользователь бота, которому администратор назначил промокод
--    командой в боте. Строки нет — не партнёр.
--      promo_code     — промокод, всегда в верхнем регистре: латинские
--                       буквы, цифры, «_» и «-» (те же символы, что
--                       Telegram разрешает в ссылке t.me/бот?start=КОД);
--      status         — active / paused: по приостановленному коду новые
--                       клиенты не записываются, прежние остаются;
--      reward_percent — процент вознаграждения с оплат приведённых
--                       клиентов (NULL — ещё не задан).
-- 2. users.referred_by_user_id / used_referral_code (есть с миграции
--    001, до сих пор не заполнялись) — кто привёл клиента и какой
--    промокод он использовал. Добавляются:
--      referral_source — откуда взят промокод: link (ссылка) или manual
--                        (клиент ввёл сам);
--      referred_at     — когда промокод записан.
--    users.own_referral_code не используется: промокод партнёра лежит в
--    partners.promo_code.
-- 3. bot_delayed_messages — сообщения бота, которые нужно отправить
--    позже (предложение ввести промокод через 10 секунд после
--    «Знакомства»).
-- 4. bot_dialog_state — новое состояние: бот ждёт промокод.
--
-- Скидка по промокоду действует только на первую оплату: признак «ещё не
-- платил» — users.first_paid_at IS NULL (колонка есть с миграции 001).
--
-- Существующие строки не меняются. Повторный запуск: таблицы не
-- дублируются; на колонках users будет «Duplicate column name
-- 'referral_source'» — значит, миграция уже применена.
-- =====================================================================

CREATE TABLE IF NOT EXISTS partners (
    user_id         BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    promo_code      VARCHAR(32)     NOT NULL,
    status          ENUM('active', 'paused') NOT NULL DEFAULT 'active',
    reward_percent  DECIMAL(5,2)    NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_partners_promo_code (promo_code),
    CONSTRAINT fk_partners_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_delayed_messages (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    kind        VARCHAR(40)     NOT NULL,
    due_at      DATETIME        NOT NULL,
    sent_at     DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bot_delayed_messages_due (sent_at, due_at),
    CONSTRAINT fk_bot_delayed_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bot_dialog_state
    MODIFY COLUMN state ENUM(
        'awaiting_issuer_search',
        'awaiting_support_name',
        'awaiting_support_message',
        'in_support_chat',
        'awaiting_reminder_days',
        'awaiting_promo_code'
    ) NOT NULL;

ALTER TABLE users
    ADD COLUMN referral_source ENUM('link', 'manual') NULL AFTER used_referral_code,
    ADD COLUMN referred_at DATETIME NULL AFTER referral_source,
    ADD KEY idx_users_referred_by (referred_by_user_id, referred_at);
