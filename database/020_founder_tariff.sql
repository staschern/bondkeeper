-- =====================================================================
-- BondKeeper — миграция 020: тариф 'founder' — безлимит по числу
-- отслеживаемых эмитентов и фактически безлимит по времени (прямой
-- запрос пользователя, 17 сентября 2026, для двух конкретных учредителей
-- проекта).
--
-- max_tracked_issuers = NULL — уже поддержанный в коде способ снять
-- лимит (см. докблок BotCommandHandler::currentTariffLimit(), где
-- гипотетический тариф 'expert' приводился как пример именно такого
-- случая — 'founder' первый тариф, который реально этим пользуется).
--
-- "Неограниченное время" не требует нового столбца/флага:
-- currentTariffLimit() проверяет только subscriptions.status = 'active',
-- current_period_end НИКАК не влияет на лимит и не проверяется никаким
-- фоновым job'ом/cron'ом — только показывается на экране "Подписка".
-- Даты продления умеет считать только ensureFreeSubscription(), и то
-- ТОЛЬКО для tariff_code='free' — 'founder' она не тронет никогда.
--
-- Саму подписку на этот тариф для конкретных telegram_id выдаёт
-- отдельный одноразовый скрипт (не эта миграция — миграции не должны
-- знать про конкретных живых пользователей): bin/grant_founder_subscription.php.
-- =====================================================================

INSERT INTO tariffs (code, name, price_rub, max_tracked_issuers, duration_days, is_active)
VALUES ('founder', 'Founder', 0, NULL, 36500, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    max_tracked_issuers = VALUES(max_tracked_issuers),
    is_active = VALUES(is_active);
