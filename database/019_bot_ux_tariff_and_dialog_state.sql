-- =====================================================================
-- BondKeeper — миграция 019: тариф Free под ТЗ Telegram-бота (7 сентября
-- 2026, docs/BOT_UX_SPEC.md) + служебные таблицы для диалогового
-- состояния бота и релея обращений в поддержку.
--
-- Переномерована с 018 на 019 при слиянии: номер 018 уже занят миграцией
-- outlook ENUM (под_review) из этапа 3, применённой в проде раньше.
--
-- 1) Тариф 'free' — было 3 эмитента / 36500 дней (искусственное
--    "навсегда", придумано до появления реального продуктового решения
--    по MVP-тарифу). По прямому ТЗ пользователя: 10 эмитентов, 14
--    календарных дней, с автопродлением до запуска платной тарификации
--    (продление — задача отдельного фонового скрипта, не этой миграции;
--    здесь только новые базовые значения тарифа).
--
-- 2) `bot_dialog_state` — лёгкая память "чего бот ждёт от пользователя
--    следующим свободным текстовым сообщением". Нужна, потому что часть
--    сценариев ТЗ требует нескольких шагов подряд обычным текстом (форма
--    Помощь: сначала имя, потом сообщение; поиск эмитента после кнопки
--    "Добавить новых эмитентов" — следующее сообщение это уже не команда,
--    а поисковый запрос). Без этой таблицы бот не отличит "свободный
--    текст, который надо на что-то потратить" от случайного сообщения.
--    `context_json` — место для промежуточных данных многошаговой формы
--    (например, уже введённое имя, пока ждём текст обращения).
--
-- 3) `support_thread_map` — минимальная связка для полноценного
--    двустороннего чат-релея "Помощь" (решение пользователя, 7 сентября
--    2026: не одноразовая пересылка, а именно чат). Бот пересылает
--    обращение пользователя администратору ОБЫЧНЫМ сообщением (не
--    Telegram-объектом "forward" — своим текстом с указанием, от кого);
--    id этого отправленного администратору сообщения кладём сюда вместе
--    с user_id. Когда администратор отвечает Reply именно на это
--    сообщение, по admin_message_id однозначно находим, кому вернуть
--    ответ. Ключ составной (admin_chat_id, admin_message_id) — Telegram
--    message_id уникален только В ПРЕДЕЛАХ одного чата, не глобально;
--    для MVP администратор один, но ключ пусть будет правильным сразу.
-- =====================================================================

UPDATE tariffs
SET max_tracked_issuers = 10,
    duration_days = 14
WHERE code = 'free';

CREATE TABLE bot_dialog_state (
    user_id       BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    state         ENUM(
                      'awaiting_issuer_search',
                      'awaiting_support_name',
                      'awaiting_support_message',
                      'in_support_chat'
                  ) NOT NULL,
    context_json  JSON NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bot_dialog_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_thread_map (
    admin_chat_id     BIGINT          NOT NULL,
    admin_message_id  BIGINT          NOT NULL,
    user_id           BIGINT UNSIGNED NOT NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_chat_id, admin_message_id),
    KEY idx_support_thread_map_user (user_id),
    CONSTRAINT fk_support_thread_map_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
