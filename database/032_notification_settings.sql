-- =====================================================================
-- BondKeeper — миграция 032: настройки уведомлений и напоминание об
-- оферте (ТЗ пользователя от 12.10.2026, docs/BOT_UX_SPEC.md, раздел 10)
--
-- 1. user_notification_settings — одна строка на пользователя. Строки
--    нет — действуют значения по умолчанию: напоминания включены, о
--    выплатах — за 1 рабочий день, об офертах — за 30 календарных дней.
--      reminders_enabled — «Отключить для всего списка»;
--      *_days — «Свой интервал»: за сколько календарных дней до даты
--               напоминать (NULL — значение по умолчанию).
-- 2. user_reminder_mutes — эмитенты, по которым пользователь отключил
--    напоминания («Выбрать эмитентов»).
-- 3. user_excluded_securities — выпуски, с которых пользователь снял
--    отметку («Уведомления по эмитентам»): по ним не уходят ни
--    уведомления, ни напоминания. Хранятся исключения, а не выбор: новый
--    выпуск эмитента попадает в уведомления сам, как и сказано в тексте
--    настроек («по умолчанию — по всем выпускам»).
-- 4. events.reminder_days — за сколько дней до даты создано напоминание
--    (R1 — выплата, R2 — оферта). Пользователи с разными интервалами
--    получают разные события об одной и той же выплате. NULL у прежних
--    напоминаний равен 1.
-- 5. Новый вид события R2 — напоминание о предстоящей оферте.
-- 6. bot_dialog_state — новое состояние: бот ждёт число дней.
--
-- Повторный запуск: таблицы и вид события не дублируются; на колонке
-- events.reminder_days будет «Duplicate column name» — значит, миграция
-- уже применена.
-- =====================================================================

CREATE TABLE IF NOT EXISTS user_notification_settings (
    user_id            BIGINT UNSIGNED   NOT NULL PRIMARY KEY,
    reminders_enabled  BOOLEAN           NOT NULL DEFAULT TRUE,
    coupon_days        SMALLINT UNSIGNED NULL,
    amortization_days  SMALLINT UNSIGNED NULL,
    redemption_days    SMALLINT UNSIGNED NULL,
    offer_days         SMALLINT UNSIGNED NULL,
    updated_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_notification_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_reminder_mutes (
    user_id     BIGINT UNSIGNED NOT NULL,
    issuer_id   BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, issuer_id),
    CONSTRAINT fk_user_reminder_mutes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_reminder_mutes_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_excluded_securities (
    user_id      BIGINT UNSIGNED NOT NULL,
    security_id  BIGINT UNSIGNED NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, security_id),
    CONSTRAINT fk_user_excluded_securities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_excluded_securities_security FOREIGN KEY (security_id) REFERENCES securities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO event_types (code, group_code, name_ru, primary_source, default_priority, is_derived, notify_client, is_active) VALUES
('R2', 'R', 'Напоминание о предстоящей оферте', 'internal_calc', 'info', TRUE, TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    group_code = VALUES(group_code),
    name_ru = VALUES(name_ru),
    primary_source = VALUES(primary_source),
    default_priority = VALUES(default_priority),
    is_derived = VALUES(is_derived),
    notify_client = VALUES(notify_client),
    is_active = VALUES(is_active);

ALTER TABLE bot_dialog_state
    MODIFY COLUMN state ENUM(
        'awaiting_issuer_search',
        'awaiting_support_name',
        'awaiting_support_message',
        'in_support_chat',
        'awaiting_reminder_days'
    ) NOT NULL;

ALTER TABLE events
    ADD COLUMN reminder_days SMALLINT UNSIGNED NULL AFTER event_date;
