<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use InvalidArgumentException;

/**
 * Сообщение о выплате в нашем внутреннем, нормализованном виде (Этап 5,
 * см. docs/STAGE5_PAYMENTS.md). Ядро обработки (PaymentProcessor) работает
 * только с ним и не знает, из какого источника и в каком формате пришло
 * исходное сообщение. Перевод «сообщение GetNews НРД → PaymentMessage» —
 * GetNewsMessageMapper: именно там решается, по каким признакам НРД
 * определять стадию и неисполнение.
 *
 * Смысл полей:
 *   kind        — вид выплаты: купон, амортизация, погашение;
 *   paymentDate — дата выплаты (по графику или с переносом на рабочий
 *                 день — PaymentProcessor найдёт строку в обоих случаях;
 *                 с миграции 006 выплата определяется датой, а не номером
 *                 купона);
 *   stage       — «получено НРД» или «передано депонентам»;
 *   execution   — исполнение: полностью, частично, не исполнено в срок,
 *                 объявлен дефолт;
 *   amountPerBond — сумма на бумагу в ЭТОМ сообщении (при выплате частями —
 *                 размер части), в валюте выплаты; null — источник сумму
 *                 не назвал;
 *   sourceRef   — идентификатор сообщения в источнике, по нему отсекаются
 *                 повторы.
 *
 * Добавлено 05.10.2026 по реальным сообщениям НРД (все необязательные):
 *   plannedPerBond — плановая сумма выплаты ПО ДАННЫМ ИСТОЧНИКА, в валюте
 *                 выплаты. Нужна, потому что наша value_per_bond хранится
 *                 в валюте номинала (купон валютного выпуска — 6,37
 *                 доллара), а НРД сообщает выплату в рублях (537,25) —
 *                 сравнивать фактическую сумму с нашей плановой нельзя;
 *   currency    — валюта выплаты (RUB, USD, CNY…);
 *   late        — источник пометил: «не исполнена эмитентом в срок»
 *                 (деньги пришли позже срока);
 *   beforeDue   — источник пометил: «исполнена в неполном объеме до
 *                 наступления срока» (часть пришла раньше срока — срок
 *                 ещё не нарушен);
 *   receivedDate / transferredDate — даты поступления денег в НРД и
 *                 передачи депонентам;
 *   sourceNote  — формулировка источника о неисполнении дословно;
 *   actionRef   — идентификатор корпоративного действия у источника: на
 *                 одну дату по одной бумаге их бывает два (купонный доход
 *                 и дополнительный «процентный доход» у структурных
 *                 выпусков), и это две разные выплаты.
 */
final class PaymentMessage
{
    public const KIND_COUPON = 'coupon';
    public const KIND_AMORTIZATION = 'amortization';
    public const KIND_REDEMPTION = 'redemption';

    public const STAGE_RECEIVED = 'received';
    public const STAGE_TRANSFERRED = 'transferred';

    public const EXECUTION_FULL = 'full';
    public const EXECUTION_PARTIAL = 'partial';
    public const EXECUTION_NONE = 'none';
    /** Источник объявил дефолт по выплате (у НРД — состояние «Дефолт», на 11–12-й рабочий день после срока). */
    public const EXECUTION_DEFAULT = 'default';

    /** @param array<string, mixed> $raw исходное сообщение источника — сохраняется в raw_messages как есть */
    public function __construct(
        public readonly string $sourceRef,
        public readonly string $isin,
        public readonly string $kind,
        public readonly string $paymentDate,
        public readonly string $stage,
        public readonly string $execution,
        public readonly ?string $amountPerBond,
        public readonly string $messageDate,
        public readonly ?string $recordDate = null,
        public readonly ?string $title = null,
        public readonly array $raw = [],
        public readonly ?string $plannedPerBond = null,
        public readonly ?string $currency = null,
        public readonly bool $late = false,
        public readonly bool $beforeDue = false,
        public readonly ?string $receivedDate = null,
        public readonly ?string $transferredDate = null,
        public readonly ?string $sourceNote = null,
        public readonly ?string $actionRef = null,
    ) {
        if (!in_array($kind, [self::KIND_COUPON, self::KIND_AMORTIZATION, self::KIND_REDEMPTION], true)) {
            throw new InvalidArgumentException("Неизвестный вид выплаты: {$kind}");
        }
        if (!in_array($stage, [self::STAGE_RECEIVED, self::STAGE_TRANSFERRED], true)) {
            throw new InvalidArgumentException("Неизвестная стадия выплаты: {$stage}");
        }
        if (!in_array($execution, [self::EXECUTION_FULL, self::EXECUTION_PARTIAL, self::EXECUTION_NONE, self::EXECUTION_DEFAULT], true)) {
            throw new InvalidArgumentException("Неизвестный признак исполнения: {$execution}");
        }
        foreach (['paymentDate' => $paymentDate, 'messageDate' => $messageDate] as $name => $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new InvalidArgumentException("{$name}: ожидается дата ГГГГ-ММ-ДД, получено «{$date}»");
            }
        }
        if ($sourceRef === '' || $isin === '') {
            throw new InvalidArgumentException('sourceRef и isin обязательны');
        }
    }

    /** Название вида выплаты для текстов: «купон», «амортизация», «погашение». */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_COUPON => 'купон',
            self::KIND_AMORTIZATION => 'амортизация',
            self::KIND_REDEMPTION => 'погашение',
            default => $kind,
        };
    }
}
