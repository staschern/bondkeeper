-- =====================================================================
-- BondKeeper — миграция 031: статусы выплат и досрочные погашения
--
-- Статус строки графика (coupons, amortizations, redemptions) —
-- «лестница», согласованная с пользователем 11.10.2026
-- (docs/STAGE5_PAYMENTS.md, раздел «Статусы выплат»):
--
--   planned      — впереди;
--   paid         — получено полностью;
--   partial      — получена часть;
--   tech_default — НРД объявил: не исполнено в срок (раньше — not_paid);
--   default      — НРД объявил дефолт (раньше отдельного значения не было);
--   unconfirmed  — дата прошла, а сообщения НРД о выплате нет (раньше
--                  такая строка оставалась planned навсегда);
--   cancelled    — выплаты не будет: выпуск погашен досрочно.
--
-- Значение not_paid заменяется на tech_default: в списке оно было, но
-- код его не ставил, а «не выплачено в срок» записывал как not_paid.
--
-- redemptions.redemption_type получает два значения:
--   early_issuer_call    — досрочно по решению эмитента (строку создаёт
--                          обработка сообщения НРД о деньгах, ссылка на
--                          событие — в triggered_by_event_id);
--   early_holders_demand — досрочно по требованию владельцев (задел: пока
--                          не заполняется).
--
-- Повторный запуск безвреден.
-- =====================================================================

UPDATE coupons SET status = 'tech_default' WHERE status = 'not_paid';
UPDATE amortizations SET status = 'tech_default' WHERE status = 'not_paid';
UPDATE redemptions SET status = 'tech_default' WHERE status = 'not_paid';

ALTER TABLE coupons
    MODIFY COLUMN status ENUM('planned', 'paid', 'partial', 'tech_default', 'default', 'unconfirmed', 'cancelled') NOT NULL DEFAULT 'planned';

ALTER TABLE amortizations
    MODIFY COLUMN status ENUM('planned', 'paid', 'partial', 'tech_default', 'default', 'unconfirmed', 'cancelled') NOT NULL DEFAULT 'planned';

ALTER TABLE redemptions
    MODIFY COLUMN status ENUM('planned', 'paid', 'partial', 'tech_default', 'default', 'unconfirmed', 'cancelled') NOT NULL DEFAULT 'planned',
    MODIFY COLUMN redemption_type ENUM('scheduled_maturity', 'early_c3_triggered', 'early_issuer_call', 'early_holders_demand') NOT NULL DEFAULT 'scheduled_maturity';
