<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

/**
 * Перевод сырого сообщения GetNews (API НРД) в наш PaymentMessage —
 * класс, который PaymentMessage::__construct() докблок (04.10.2026)
 * прямо назвал "пишется после получения спецификации API и реальных
 * примеров". Both появились 05.10.2026 (тестовый доступ, окно
 * 18.09–02.10.2026, 1000 реальных сообщений — см.
 * var/getnews_debug_2026-09-18_2026-10-02.json на сервере, не
 * коммитится). Правила ниже — не гипотеза, а то, что реально
 * наблюдалось в этой выгрузке; где уверенности не было — возвращаем
 * null, а не гадаем (см. docs/STAGE5_PAYMENTS.md, раздел 9).
 *
 * === Что подтвердили реальные данные ===
 *
 * 1. ca_type (на выдаче часто с суффиксом, типа "REDM/BN" — то же
 *    действие, что "REDM"): INTR — купон, PRED (НРД сам зовёт его
 *    "Частичное досрочное погашение", но на деле это и есть ОБЫЧНАЯ
 *    плановая амортизация у розничных облигаций — проверено на
 *    реальных сериях с нормальными процентами 5-6% от номинала) —
 *    амортизация, REDM — погашение по графику (size_percent=100,
 *    "Погашение (выплата номинальной стоимости)"). BPUT (оферта пут)
 *    и MCAL (отзыв эмитентом) НЕ обрабатываются: это не то же самое,
 *    что наша таблица redemptions (scheduled_maturity) — нет
 *    подтверждённого примера, что они туда ложатся корректно.
 *
 * 2. data.state.code — главный сигнал исполнения:
 *      A ("Состоялось")      -> execution = full
 *      T ("Техн.дефолт")     -> execution = none (подтверждено
 *                               реальными случаями: АО «Гарант-
 *                               Инвест» (6 из 9), ООО «ЛКХ», ООО
 *                               «ЭМ ЗАПАД», ПАО «ГК Самолет», ООО
 *                               «Ю Ди Пи Авто» — все известные
 *                               проблемные эмитенты)
 *      N ("Не состоялось")   -> пока рано (назначено на будущую дату
 *                               ИЛИ ещё не подтверждено) — не признак
 *                               провала сам по себе, т.к. это и
 *                               состояние ДО даты, и короткое "висит"
 *                               состояние прямо на дате до подтверж-
 *                               дения. Возвращаем null — ждём
 *                               следующего сообщения с другим state.
 *      C ("Отменено")        -> возвращаем null (слишком редко и
 *                               неоднозначно, чтобы сейчас решать)
 *
 * 3. "Получено" и "передано депонентам" — РЕАЛЬНО разные сообщения
 *    (не теория!): найдены десятки пар action_id, где сначала
 *    приходит "О получении..." (НРД получил от эмитента), через
 *    ~1 день — "О передаче..." (НРД передал депонентам), с той же
 *    суммой. Для обычных розничных бумаг чаще — ОДНО сообщение
 *    "О получении и передаче" сразу. Различается только ТЕКСТОМ
 *    заголовка/анонса, отдельного поля для этого нет — разбираем
 *    регуляркой, как AcraNewsTitleParser разбирает заголовки.
 *
 * 4. Частичная выплата (B1/B4) — ни одного подтверждённого примера
 *    за эти 2 недели. Поле data.{coupon,repayment}.part_payment_size,
 *    на которое рассчитывали, в выгрузке ведёт себя непоследовательно
 *    (то больше size, то меньше) — НЕ используется, пока не появится
 *    подтверждённый пример. Это означает: execution здесь может быть
 *    только full или none, partial — через PaymentProcessor's
 *    `transferred`-handling += будущую доработку, когда появится
 *    реальный случай.
 *
 * 5. content_id_out — дедуп-ключ сообщения (технический, стабильный);
 *    action_id — связывает все сообщения одного события (получение +
 *    передача + техдефолт) между собой, но мы его не используем здесь
 *    отдельно — PaymentProcessor сам отсекает повторы по source_ref.
 */
final class GetNewsMessageMapper
{
    /**
     * @param array<string, mixed> $rawMessage один элемент ответа GetNewsClient::fetchNews()
     */
    public static function map(array $rawMessage): ?PaymentMessage
    {
        $kind = self::kindFromCaType((string) ($rawMessage['ca_type'] ?? ''));
        if ($kind === null) {
            return null;
        }

        $data = is_array($rawMessage['data'] ?? null) ? $rawMessage['data'] : [];
        $stateCode = (string) ($data['state']['code'] ?? '');
        $execution = match ($stateCode) {
            'A' => PaymentMessage::EXECUTION_FULL,
            'T' => PaymentMessage::EXECUTION_NONE,
            default => null, // N (пока рано) и C (отменено) — осознанно не обрабатываем
        };
        if ($execution === null) {
            return null;
        }

        $securities = is_array($data['securities'] ?? null) ? $data['securities'] : [];
        $security = is_array($securities[0] ?? null) ? $securities[0] : null;
        $isin = (string) ($security['isin'] ?? '');
        $paymentDate = (string) ($data['action_date_plan'] ?? $data['action_date_calc'] ?? '');
        $messageDate = substr((string) ($rawMessage['pub_date'] ?? ''), 0, 10);
        $sourceRef = (string) ($rawMessage['content_id_out'] ?? $rawMessage['id'] ?? '');
        if ($isin === '' || $paymentDate === '' || $messageDate === '' || $sourceRef === '') {
            return null;
        }

        $title = (string) ($rawMessage['announce_ru'] ?? $rawMessage['title_ru'] ?? '');

        return new PaymentMessage(
            sourceRef: $sourceRef,
            isin: $isin,
            kind: $kind,
            paymentDate: $paymentDate,
            stage: self::stageFromTitle($title),
            execution: $execution,
            amountPerBond: self::amountFor($kind, $data),
            messageDate: $messageDate,
            recordDate: isset($data['record_date_plan']) ? (string) $data['record_date_plan'] : null,
            title: $title !== '' ? $title : null,
            raw: $rawMessage,
        );
    }

    private static function kindFromCaType(string $caType): ?string
    {
        $base = explode('/', $caType)[0];

        return match ($base) {
            'INTR' => PaymentMessage::KIND_COUPON,
            'PRED' => PaymentMessage::KIND_AMORTIZATION,
            'REDM' => PaymentMessage::KIND_REDEMPTION,
            default => null,
        };
    }

    /**
     * "О получении и передаче..." (одно сообщение, самый частый случай
     * у розничных бумаг) и "О получении..." (первое из пары) — обе
     * STAGE_RECEIVED. Только "О передаче..." БЕЗ слова "получении" —
     * второе сообщение пары, STAGE_TRANSFERRED (не уведомляет клиента,
     * см. EventPublisher — та же сумма уже посчитана на первом).
     */
    private static function stageFromTitle(string $title): string
    {
        if (preg_match('/о передаче/ui', $title) === 1 && preg_match('/получении/ui', $title) !== 1) {
            return PaymentMessage::STAGE_TRANSFERRED;
        }

        return PaymentMessage::STAGE_RECEIVED;
    }

    /** @param array<string, mixed> $data */
    private static function amountFor(string $kind, array $data): ?string
    {
        if ($kind === PaymentMessage::KIND_COUPON) {
            $coupon = is_array($data['coupon'] ?? null) ? $data['coupon'] : [];
            $amount = $coupon['payment_size'] ?? $coupon['size'] ?? null;
        } else {
            $repayment = is_array($data['repayment'] ?? null) ? $data['repayment'] : [];
            $amount = $repayment['size_per_security_cur'] ?? $repayment['size_cur'] ?? null;
        }

        return $amount !== null ? (string) $amount : null;
    }
}
