-- =====================================================================
-- BondKeeper — миграция 013: приводим rating_actions/current_ratings
-- к уточнённой спецификации этапа 3 (сбор рейтингов агентств)
--
-- Три расхождения со схемой из 001_schema.sql, найденные при сверке с
-- полем-за-полем спецификацией пользователя перед тем, как писать
-- импортёр:
--
--   1. rating_actions.outlook — было ОДНО поле "текущий прогноз после
--      действия". Пользователь явно запросил разделить на outlook_from
--      (прогноз ДО) и outlook_to (прогноз ПОСЛЕ) — по аналогии с уже
--      существующей парой rating_from/rating_to. Переименовываем
--      существующую колонку в outlook_to (сохраняя её смысл) и добавляем
--      outlook_from рядом.
--
--   2. rating_actions.review_type — было NOT NULL без DEFAULT. Пока
--      парсинг новостей не умеет отличать плановое рейтинговое действие
--      от внепланового (реактивного), пользователь попросил дефолт
--      'scheduled' везде — тот же принцип "честно, но с рабочим дефолтом",
--      что offer_type/coupon_type='unknown' в более ранних миграциях,
--      только тут дефолт не "неизвестно", а наиболее частый на практике
--      случай (у обоих основных агентств абсолютное большинство действий
--      плановые, см. документ с комментариями пользователя по агентствам).
--
--   3. current_ratings — не было отдельного created_at (только updated_at
--      с ON UPDATE). Пользователь явно разделил семантику: created_at —
--      когда строка появилась (в т.ч. при первом севе с сайта агентства),
--      updated_at — когда обновлялась в последний раз (обычно совпадает
--      с last_action_date, но не обязано — см. комментарий пользователя
--      "по сути last_action_date=updated_at, но возможны нюансы").
-- =====================================================================

ALTER TABLE rating_actions
    ADD COLUMN outlook_from ENUM('positive','stable','negative','developing') NULL AFTER rating_to,
    CHANGE COLUMN outlook outlook_to ENUM('positive','stable','negative','developing') NULL,
    MODIFY COLUMN review_type ENUM('scheduled','reactive') NOT NULL DEFAULT 'scheduled';

ALTER TABLE current_ratings
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER last_action_date;
