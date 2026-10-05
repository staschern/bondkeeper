<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use InvalidArgumentException;

/**
 * Сообщение о выплате в нашем внутреннем, нормализованном виде (Этап 5,
 * см. docs/STAGE5_PAYMENTS.md). Ядро обработки (PaymentProcessor) работает
 * только с ним и не знает, из какого источника и в каком формате пришло
 * исходное сообщение. Перевод «сообщение GetNews НРД → PaymentMessage» —
 * отдельный класс-переводчик, который пишется после получения
 * спецификации API и реальных примеров: именно там решается, по каким
 * полям НРД определять стадию и неисполнение.
 *
 * Смысл полей:
 *   kind        — вид выплаты: купон, амортизация, погашение;
 *   paymentDate — дата выплаты ПО ГРАФИКУ (ключ строки coupons /
 *                 amortizations / redemptions — с миграции 006 выплата
 *                 определяется датой, а не номером купона);
 *   stage       — «получено НРД» или «передано депонентам»;
 *   execution   — исполнение: полностью, частично, не исполнено в срок;
 *   amountPerBond — сумма на бумагу в ЭТОМ сообщении (для транша — размер
 *                 транша); null — источник сумму не назвал;
 *   sourceRef   — идентификатор сообщения в источнике, по нему отсекаются
 *                 повторы.
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
    ) {
        if (!in_array($kind, [self::KIND_COUPON, self::KIND_AMORTIZATION, self::KIND_REDEMPTION], true)) {
            throw new InvalidArgumentException("Неизвестный вид выплаты: {$kind}");
        }
        if (!in_array($stage, [self::STAGE_RECEIVED, self::STAGE_TRANSFERRED], true)) {
            throw new InvalidArgumentException("Неизвестная стадия выплаты: {$stage}");
        }
        if (!in_array($execution, [self::EXECUTION_FULL, self::EXECUTION_PARTIAL, self::EXECUTION_NONE], true)) {
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
