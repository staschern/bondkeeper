-- =====================================================================
-- BondKeeper — миграция 024: сопоставление по названию только после
-- подтверждения человеком (сентябрь 2026)
--
-- Живая находка при разборе сверки: сопоставление "по корню" названия
-- (миграция 022) дало ложные совпадения — фармацевтическая ООО «Озон»
-- (Жигулёвск) получила рейтинги ОЗОН Банка и ОЗОН Капитала, курское
-- ЗАО «Прогресс» — рейтинг липецкого АО «ПРОГРЕСС» («ФрутоНяня»). Корень
-- совпадает не потому, что компании из одной группы, а потому, что у
-- разных компаний одинаковое слово в названии.
--
-- Решение пользователя: там, где сопоставление идёт ПО НАЗВАНИЮ (точное
-- имя или "по корню"), в базу ничего не пишется без его подтверждения.
-- Такое совпадение становится ПРЕДЛОЖЕНИЕМ (строка этой таблицы со
-- status='pending'); администратор получает его в Telegram вместе с
-- заголовком новости и ссылкой на пресс-релиз и подтверждает/отклоняет
-- через bin/review_matches.php.
--
-- Ключ предложения — то, по чему источник узнаётся в следующий раз:
--   - source_key_type='inn'  — источник дал ИНН (которого нет в issuers).
--     Подтверждение превращается в связку issuer_spv_links (миграция 023)
--     — дальше такой ИНН сопоставляется напрямую, до названий.
--   - source_key_type='name' — ИНН у источника нет (например, вторая
--     компания в составном действии НКР — ИНН на странице даётся только
--     для первой). Ключ — нормализованное название
--     (IssuerMatcher::nameKey()). Подтверждённое название сопоставляется
--     напрямую через IssuerMatcher::findIssuerIdByApprovedName().
--
-- status='rejected' — пара больше никогда не предлагается.
-- notified_at — когда предложение ушло администратору (чтобы не слать
-- одно и то же каждые 30 минут, пока новость пробуется заново в окне).
-- =====================================================================

CREATE TABLE issuer_name_match_reviews (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key_type  ENUM('inn','name') NOT NULL,
    source_key       VARCHAR(255)    NOT NULL,
    issuer_id        BIGINT UNSIGNED NOT NULL,
    status           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    match_type       ENUM('exact_name','root_name') NOT NULL,
    agency           ENUM('nra','acra','expert_ra','nkr') NOT NULL,
    source_name      VARCHAR(500)    NULL,
    source_title     VARCHAR(500)    NULL,
    source_url       VARCHAR(500)    NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at      DATETIME        NULL,
    decided_at       DATETIME        NULL,
    UNIQUE KEY uq_issuer_name_match_reviews (source_key_type, source_key, issuer_id),
    KEY idx_issuer_name_match_reviews_status (status),
    CONSTRAINT fk_issuer_name_match_reviews_issuer FOREIGN KEY (issuer_id) REFERENCES issuers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
