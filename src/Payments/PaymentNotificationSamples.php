<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

/**
 * Образцы всех уведомлений о выплатах (Этап 5, docs/STAGE5_PAYMENTS.md) —
 * по одному на каждый вид и на каждый заметный вариант текста. Нужны,
 * чтобы посмотреть и поправить формулировки в Telegram, не дожидаясь
 * настоящих событий: bin/send_payment_samples.php отправляет их
 * администратору.
 *
 * Тексты клиентских уведомлений здесь НЕ записаны: образец — это только
 * вид события и данные (payload) в том виде, в каком их создают
 * PaymentWatch и PaymentProcessor, а текст строит тот же
 * NotificationDispatcher, что и для клиентов. Поэтому образец всегда
 * совпадает с настоящим уведомлением, и после правки шаблона образцы
 * меняются сами.
 *
 * Эмитент и бумага — вымышленные, чтобы образец нельзя было принять за
 * настоящее событие.
 */
final class PaymentNotificationSamples
{
    public const ISSUER = 'ООО «Образец»';
    public const ISSUER_INN = '7700000000';
    private const BOND = ['isin' => 'RU000A0SAMPL1', 'security_name' => 'Образец БО-01'];

    /**
     * Уведомления, которые получает клиент.
     *
     * @return array<int, array{code: string, title: string, payload: array<string, mixed>}>
     */
    public static function client(): array
    {
        $schedule = static fn (string $date, string $effective, array $payments, ?string $currency = 'RUB'): array => self::BOND + [
            'payment_date' => $date, 'effective_date' => $effective, 'currency' => $currency, 'payments' => $payments,
        ];
        $payment = static fn (string $kind, array $fields): array => self::BOND + $fields + [
            'kind' => $kind, 'payment_date' => '2026-10-15', 'effective_date' => '2026-10-15', 'message_date' => '2026-10-15',
            'currency' => 'RUB', 'amount_planned' => null, 'amount_actual' => null, 'tranche_amount' => null,
            'received_date' => null, 'late' => false, 'before_due' => false, 'extra' => false,
            'full_default_date' => null, 'working_days_to_default' => null, 'source_note' => null,
        ];

        return [
            ['code' => 'R1', 'title' => 'напоминание накануне: купон', 'payload' => $schedule('2026-10-15', '2026-10-15', [
                ['kind' => 'coupon', 'amount_planned' => '12.3300'],
            ])],
            ['code' => 'R1', 'title' => 'напоминание: купон и амортизация, дата выпала на выходной', 'payload' => $schedule('2026-10-17', '2026-10-19', [
                ['kind' => 'coupon', 'amount_planned' => '12.3300'], ['kind' => 'amortization', 'amount_planned' => '250.0000'],
            ])],
            ['code' => 'R1', 'title' => 'напоминание: купон и погашение', 'payload' => $schedule('2026-10-15', '2026-10-15', [
                ['kind' => 'coupon', 'amount_planned' => '12.3300'], ['kind' => 'redemption', 'amount_planned' => '1000.0000'],
            ])],
            ['code' => 'R1', 'title' => 'напоминание: валютный выпуск (сумма в валюте номинала)', 'payload' => $schedule('2026-10-15', '2026-10-15', [
                ['kind' => 'coupon', 'amount_planned' => '6.3700'],
            ], 'USD')],
            ['code' => 'R1', 'title' => 'напоминание: сумма купона биржей ещё не объявлена (плавающая ставка)', 'payload' => $schedule('2026-10-15', '2026-10-15', [
                ['kind' => 'coupon', 'amount_planned' => null],
            ])],

            ['code' => 'A2', 'title' => 'купон получен НРД', 'payload' => $payment('coupon', [
                'amount_planned' => '12.3300', 'amount_actual' => '12.3300', 'tranche_amount' => '12.3300', 'received_date' => '2026-10-14',
            ])],
            ['code' => 'A4', 'title' => 'амортизация получена НРД', 'payload' => $payment('amortization', [
                'amount_planned' => '250.0000', 'amount_actual' => '250.0000', 'tranche_amount' => '250.0000', 'received_date' => '2026-10-15',
            ])],
            ['code' => 'A6', 'title' => 'погашение получено НРД', 'payload' => $payment('redemption', [
                'amount_planned' => '1000.0000', 'amount_actual' => '1000.0000', 'tranche_amount' => '1000.0000', 'received_date' => '2026-10-15',
            ])],
            ['code' => 'A2', 'title' => 'вторая выплата НРД по тому же купону (структурные выпуски)', 'payload' => $payment('coupon', [
                'amount_planned' => '65.2100', 'amount_actual' => '65.3100', 'tranche_amount' => '65.2100', 'received_date' => '2026-10-14', 'extra' => true,
            ])],

            ['code' => 'B1a', 'title' => 'часть суммы пришла раньше срока', 'payload' => $payment('coupon', [
                'amount_planned' => '4.6800', 'amount_actual' => '2.8400', 'tranche_amount' => '2.8400', 'received_date' => '2026-10-14',
                'message_date' => '2026-10-15', 'before_due' => true, 'source_note' => 'Исполнена в неполном объеме до наступления срока',
            ])],
            ['code' => 'B1', 'title' => 'выплачено частично, срок уже наступил', 'payload' => $payment('redemption', [
                'amount_planned' => '1000.0000', 'amount_actual' => '269.1049', 'tranche_amount' => '269.1049', 'received_date' => '2026-10-15',
                'message_date' => '2026-10-16', 'full_default_date' => '2026-10-29', 'working_days_to_default' => 9,
                'source_note' => 'Исполнена ненадлежащим образом',
            ])],
            ['code' => 'B4', 'title' => 'доплата, долг ещё не закрыт', 'payload' => $payment('redemption', [
                'amount_planned' => '1000.0000', 'amount_actual' => '569.1049', 'tranche_amount' => '300.0000', 'received_date' => '2026-10-19',
                'message_date' => '2026-10-20', 'late' => true, 'full_default_date' => '2026-10-29', 'working_days_to_default' => 7,
            ])],
            ['code' => 'B5', 'title' => 'выплачено полностью после просрочки', 'payload' => $payment('coupon', [
                'amount_planned' => '12.3300', 'amount_actual' => '12.3300', 'tranche_amount' => '12.3300', 'received_date' => '2026-10-16',
                'message_date' => '2026-10-16', 'late' => true, 'source_note' => 'Не исполнена эмитентом в срок',
            ])],
            ['code' => 'B2', 'title' => 'не выплачено в срок (объявление НРД на следующий рабочий день)', 'payload' => $payment('coupon', [
                'amount_planned' => '12.3300', 'message_date' => '2026-10-16', 'full_default_date' => '2026-10-29', 'working_days_to_default' => 9,
            ])],
            ['code' => 'B2', 'title' => 'не выплачено в срок — узнали поздно, срок до полного дефолта уже истёк', 'payload' => $payment('coupon', [
                'amount_planned' => '12.3300', 'message_date' => '2026-11-02', 'full_default_date' => '2026-10-29', 'working_days_to_default' => 0,
            ])],
            ['code' => 'C2', 'title' => 'НРД объявил дефолт (11–12-й рабочий день после срока)', 'payload' => $payment('coupon', [
                'amount_planned' => '12.3300', 'message_date' => '2026-10-30', 'full_default_date' => '2026-10-29',
            ])],

            ['code' => 'B2a', 'title' => 'жёлтое: к 12:02 следующего рабочего дня НРД не сообщил о деньгах', 'payload' => $schedule('2026-10-15', '2026-10-15', [
                ['kind' => 'coupon', 'amount_planned' => '12.3300'], ['kind' => 'amortization', 'amount_planned' => '250.0000'],
            ]) + ['check' => 'noon', 'due_date' => '2026-10-15']],
        ];
    }

    /**
     * Сообщения, которые получает только администратор. Их текст собирается
     * в скриптах (bin/payment_checks.php, bin/poll_getnews.php), здесь —
     * те же формулировки на вымышленных данных.
     *
     * @return array<int, array{title: string, text: string}>
     */
    public static function admin(): array
    {
        $line = '• Образец БО-01 (RU000A0SAMPL1), ' . self::ISSUER . ': купон, дата по графику 15.10.2026';

        return [
            ['title' => 'вечером следующего рабочего дня от НРД нет вообще ничего', 'text' => "⚠️ По выплатам за 2026-10-15 от НРД нет никаких сообщений — ни о получении денег, ни о неисполнении (1). Возможен технический сбой, нужна проверка:\n{$line}"],
            ['title' => 'жёлтая проверка в режиме «только администратору»', 'text' => "🟡 Проверка 2026-10-16: по выплатам за 2026-10-15 НРД не сообщил о поступлении денег (1):\n{$line}"],
            ['title' => 'сообщение НРД по нашей бумаге не нашло выплату в графике', 'text' => "⚠️ GetNews: сообщения по нашим бумагам, которым не нашлось выплаты в графике (1). Стоит проверить график с Мосбиржи:\nRU000A0SAMPL1 (redemption, 2026-10-15)"],
            ['title' => 'сообщение НРД о деньгах не удалось прочитать', 'text' => "⚠️ GetNews: не удалось прочитать сообщения о деньгах (1) — возможно, НРД изменил формат:\n2026-10-15 10:14:02 (INTR) (Выплата купонного дохода) О получении и передаче головным депозитарием…"],
        ];
    }
}
