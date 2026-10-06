<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

/**
 * Перевод сырого сообщения GetNews (API НРД) в наш PaymentMessage.
 *
 * Переписан 05.10.2026 по полной выгрузке тестового доступа (3798
 * сообщений, 18–30.09.2026) и сверке с графиком выплат из нашей базы.
 * Первая версия судила по data.state («Состоялось» → выплачено,
 * «Техн.дефолт» → не выплачено, «Не состоялось» → пропустить) — на данных
 * это оказалось неверно:
 *   - data.state — снимок состояния события на момент публикации, и
 *     «Не состоялось» стоит у 683 из 1521 сообщений о деньгах (у 325 из
 *     330 «О получении…»), хотя деньги получены. Из 550 выплат с датой
 *     18–23.09 первая версия не увидела бы 144 и ещё 55 увидела бы с
 *     опозданием на 1–4 дня;
 *   - «Техн.дефолт» на сообщении о деньгах означает «деньги пришли, но
 *     позже срока или не полностью», а не «не выплачено»;
 *   - сумму первая версия брала из полей data.coupon / data.repayment, а
 *     там плановая сумма — частичную выплату по ним не увидеть.
 *
 * === Как читаем сообщение ===
 *
 * 1. Вид выплаты — ca_type до косой черты: INTR — купон, PRED («Частичное
 *    досрочное погашение основного долга») — амортизация, REDM —
 *    погашение (у объявлений суффикс REDM/BN). Сверка с базой: даты PRED
 *    совпадают со строками amortizations. MCAL («Досрочное обязательное
 *    погашение облигации») — погашение, если выплачивается 100 % номинала,
 *    иначе амортизация: сквозной прогон на выгрузке базы показал, что у
 *    части выпусков (ВЭБ.РФ, ВТБ) так приходит выплата номинала, которая в
 *    нашем графике стоит обычной строкой. Оферты (BPUT) не обрабатываются —
 *    решение пользователя от 04.10.2026.
 *
 * 2. Вид сообщения — по заголовку:
 *      «О получении и передаче…» — деньги получены НРД и переданы
 *          депонентам, одно сообщение (70 % выплат);
 *      «О получении…»            — деньги получены заранее (или с
 *          опозданием), депонентам ещё не переданы;
 *      «О передаче…»             — вторая половина пары к «О получении»;
 *      «О корпоративном действии…» — объявление: денег не подтверждает.
 *    Клиенту сообщаем по первому сообщению, где есть получение.
 *
 * 3. Сообщение о деньгах: сумма перевода на бумагу и даты — из текста
 *    (GetNewsBody::currentPayment()), исполнение — по формулировке НРД из
 *    «Сведений о неисполнении» и сравнению суммы с плановой:
 *      есть «ненадлежащим образом» / «в неполном объеме» или сумма меньше
 *      плановой                         → частично;
 *      «Не исполнена эмитентом в срок»  → late (пришло позже срока);
 *      «…до наступления срока»          → beforeDue (часть пришла раньше
 *                                         срока, срок ещё не нарушен).
 *    Состояние T или D на таком сообщении тоже означает late.
 *
 * 4. Объявление в состоянии T («Техн.дефолт») — выплата не исполнена в
 *    срок: НРД публикует его на следующий рабочий день после даты выплаты
 *    (11 из 13 в выгрузке). В состоянии D («Дефолт») — полный дефолт, на
 *    11–12-й рабочий день. Прочие объявления (N, A, C) выплату не
 *    описывают и пропускаются.
 *
 * 5. Дата выплаты — data.action_date_plan (дата с переносом на рабочий
 *    день; data.action_date_calc — дата по условиям выпуска, может быть
 *    выходным). PaymentProcessor находит строку графика в обоих случаях.
 *
 * 6. content_id_out — ключ для отсечения повторов (уникален на всей
 *    выгрузке); action_id — идентификатор корпоративного действия.
 *
 * Если сообщение о деньгах не удалось прочитать (нет таблицы «Текущая
 * выплата по КД»), оно не угадывается, а возвращается с причиной
 * REASON_UNREADABLE — вызывающий код сообщает администратору: это сигнал,
 * что НРД поменял формат.
 */
final class GetNewsMessageMapper
{
    public const TYPE_RECEIVED = 'received';
    public const TYPE_RECEIVED_TRANSFERRED = 'received_transferred';
    public const TYPE_TRANSFERRED = 'transferred';
    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const REASON_OTHER_ACTION = 'другой вид события';
    public const REASON_ANNOUNCEMENT = 'объявление без сведений о выплате';
    public const REASON_OTHER_MESSAGE = 'сообщение другого вида';
    public const REASON_NO_KEY_FIELDS = 'нет ISIN, даты или идентификатора';
    public const REASON_UNREADABLE = 'сообщение о деньгах не прочитано';

    /** Поля, которые не нужны ни для разбора, ни для архива: английские копии текста — треть объёма сообщения. */
    private const DROPPED_FIELDS = ['body_en', 'title_en', 'announce_en'];

    /**
     * @param array<string, mixed> $rawMessage один элемент ответа GetNewsClient::fetchNews()
     */
    public static function map(array $rawMessage): ?PaymentMessage
    {
        return self::classify($rawMessage)['message'];
    }

    /**
     * То же, что map(), но с причиной, по которой сообщение пропущено.
     *
     * @param array<string, mixed> $rawMessage
     * @return array{message: ?PaymentMessage, reason: ?string}
     */
    public static function classify(array $rawMessage): array
    {
        $data = is_array($rawMessage['data'] ?? null) ? $rawMessage['data'] : [];
        $kind = self::kindFromCaType((string) ($rawMessage['ca_type'] ?? ''), $data);
        if ($kind === null) {
            return ['message' => null, 'reason' => self::REASON_OTHER_ACTION];
        }

        $title = (string) ($rawMessage['title_ru'] ?? $rawMessage['announce_ru'] ?? '');
        $type = self::messageType($title);
        if ($type === null) {
            return ['message' => null, 'reason' => self::REASON_OTHER_MESSAGE];
        }

        $stateCode = (string) ($data['state']['code'] ?? '');
        if ($type === self::TYPE_ANNOUNCEMENT && !in_array($stateCode, ['T', 'D'], true)) {
            return ['message' => null, 'reason' => self::REASON_ANNOUNCEMENT];
        }

        $securities = is_array($data['securities'] ?? null) ? $data['securities'] : [];
        $security = is_array($securities[0] ?? null) ? $securities[0] : [];
        $isin = (string) ($security['isin'] ?? '');
        $paymentDate = (string) ($data['action_date_plan'] ?? $data['action_date_calc'] ?? '');
        $messageDate = substr((string) ($rawMessage['pub_date'] ?? ''), 0, 10);
        $sourceRef = (string) ($rawMessage['content_id_out'] ?? $rawMessage['id'] ?? '');
        if ($isin === '' || $sourceRef === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $messageDate) !== 1) {
            return ['message' => null, 'reason' => self::REASON_NO_KEY_FIELDS];
        }

        [$planned, $currency] = self::plannedAmount($kind, $data);
        $common = [
            'sourceRef' => $sourceRef,
            'isin' => $isin,
            'kind' => $kind,
            'paymentDate' => $paymentDate,
            'messageDate' => $messageDate,
            'recordDate' => isset($data['record_date_plan']) ? (string) $data['record_date_plan'] : null,
            'title' => $title !== '' ? $title : null,
            'raw' => array_diff_key($rawMessage, array_flip(self::DROPPED_FIELDS)),
            'plannedPerBond' => $planned,
            'currency' => $currency,
            'actionRef' => isset($rawMessage['action_id']) ? (string) $rawMessage['action_id'] : null,
        ];

        if ($type === self::TYPE_ANNOUNCEMENT) {
            return ['message' => new PaymentMessage(...$common, ...[
                'stage' => PaymentMessage::STAGE_RECEIVED,
                'execution' => $stateCode === 'D' ? PaymentMessage::EXECUTION_DEFAULT : PaymentMessage::EXECUTION_NONE,
                'amountPerBond' => null,
            ]), 'reason' => null];
        }

        $payment = GetNewsBody::currentPayment((string) ($rawMessage['body_ru'] ?? ''));
        if ($payment['amount'] === null) {
            return ['message' => null, 'reason' => self::REASON_UNREADABLE];
        }

        $note = $payment['note'];
        $noteLower = $note !== null ? mb_strtolower($note) : '';
        $shortOfPlanned = $planned !== null && (float) $payment['amount'] + self::tolerance((float) $planned) < (float) $planned;
        $partial = $shortOfPlanned || str_contains($noteLower, 'ненадлежащ') || preg_match('/неполном объ[её]ме/u', $noteLower) === 1;

        return ['message' => new PaymentMessage(...$common, ...[
            'stage' => $type === self::TYPE_TRANSFERRED ? PaymentMessage::STAGE_TRANSFERRED : PaymentMessage::STAGE_RECEIVED,
            'execution' => $partial ? PaymentMessage::EXECUTION_PARTIAL : PaymentMessage::EXECUTION_FULL,
            'amountPerBond' => $payment['amount'],
            'late' => str_contains($noteLower, 'не исполнена эмитентом в срок') || in_array($stateCode, ['T', 'D'], true),
            'beforeDue' => str_contains($noteLower, 'до наступления срока'),
            'receivedDate' => $payment['received'],
            'transferredDate' => $payment['transferred'],
            'sourceNote' => $note,
        ]), 'reason' => null];
    }

    /** Вид сообщения по заголовку; null — не про выплату (например, «Об отмене корпоративного действия»). */
    public static function messageType(string $title): ?string
    {
        if (preg_match('/о получении и передаче/ui', $title) === 1) {
            return self::TYPE_RECEIVED_TRANSFERRED;
        }
        if (preg_match('/о получении/ui', $title) === 1) {
            return self::TYPE_RECEIVED;
        }
        if (preg_match('/о передаче/ui', $title) === 1) {
            return self::TYPE_TRANSFERRED;
        }
        if (preg_match('/о корпоративном действии/ui', $title) === 1) {
            return self::TYPE_ANNOUNCEMENT;
        }

        return null;
    }

    /**
     * MCAL («Досрочное обязательное погашение облигации») — по доле
     * номинала: 100 % и больше — погашение, меньше — амортизация. Так НРД
     * оформляет выплату номинала у выпусков, где она в нашем графике стоит
     * обычной строкой на эту дату (ВЭБ.РФ ПБО-002Р-К…, ВТБ Б-1-…): без
     * MCAL по ним каждый раз срабатывала бы проверка «денег нет».
     *
     * @param array<string, mixed> $data
     */
    private static function kindFromCaType(string $caType, array $data): ?string
    {
        return match (explode('/', $caType)[0]) {
            'INTR' => PaymentMessage::KIND_COUPON,
            'PRED' => PaymentMessage::KIND_AMORTIZATION,
            'REDM' => PaymentMessage::KIND_REDEMPTION,
            'MCAL' => (float) ($data['repayment']['size_percent'] ?? 100) >= 100
                ? PaymentMessage::KIND_REDEMPTION
                : PaymentMessage::KIND_AMORTIZATION,
            default => null,
        };
    }

    /**
     * Плановая сумма на бумагу и валюта ВЫПЛАТЫ по данным НРД. У валютного
     * выпуска size — в валюте номинала, payment_size /
     * size_per_security_cur — в валюте выплаты (обычно рубли).
     *
     * @param array<string, mixed> $data
     * @return array{0: ?string, 1: ?string}
     */
    private static function plannedAmount(string $kind, array $data): array
    {
        if ($kind === PaymentMessage::KIND_COUPON) {
            $block = is_array($data['coupon'] ?? null) ? $data['coupon'] : [];
            $amount = $block['payment_size'] ?? $block['size'] ?? null;
            $currency = $block['payment_currency']['code'] ?? $block['currency']['code'] ?? null;
        } else {
            $block = is_array($data['repayment'] ?? null) ? $data['repayment'] : [];
            $amount = $block['size_per_security_cur'] ?? $block['size_cur'] ?? null;
            $currency = $block['currency_of_payment']['code'] ?? $block['currency']['code'] ?? null;
        }

        return [
            $amount !== null && is_numeric($amount) ? (string) $amount : null,
            $currency !== null ? (string) $currency : null,
        ];
    }

    /** Допуск при сравнении сумм: копейка или миллионная доля суммы — НРД округляет части до 9 знаков. */
    private static function tolerance(float $planned): float
    {
        return max(0.005, abs($planned) * 1e-6);
    }
}
