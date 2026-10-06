<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use BondKeeper\Events\EventPublisher;
use PDO;

/**
 * Обработка сообщения о выплате (Этап 5, см. docs/STAGE5_PAYMENTS.md):
 * купон, амортизация, погашение. На входе — PaymentMessage (сообщение
 * источника в нашем виде), на выходе — факт в графике, «история» выплаты
 * и события для клиентов.
 *
 * Что делает process():
 *   1. находит бумагу по ISIN. Чужая бумага (лента НРД — весь рынок) —
 *      не ошибка: ничего не сохраняется;
 *   2. сохраняет исходное сообщение в raw_messages (source = nsd) и
 *      отсекает повторы по source_ref;
 *   3. находит выплату в графике по ДАТЕ (номера купона в графике нет с
 *      миграции 006): точная дата, иначе единственная выплата с тем же
 *      днём исполнения в пределах ±7 дней (НРД называет дату с переносом
 *      на рабочий день, в графике она может быть выходным);
 *   4. записывает факт (actual_value_per_bond, status, при нарушении —
 *      full_default_date_planned = день исполнения + 10 рабочих дней);
 *   5. ведёт «историю» выплаты (event_stories): одна на выплату;
 *   6. создаёт события через EventPublisher.
 *
 * === Погашение в нашем графике ===
 * Биржа отдаёт выплату номинала в конце срока строкой amortizations, а
 * redemptions.value_per_bond считается как «номинал минус все
 * амортизации» — у 2932 бумаг из 3215 там ноль. Поэтому сообщение о
 * погашении ложится на строку redemptions, только если в ней сумма
 * больше нуля; иначе — на строку amortizations той же даты. Вид события
 * (A6/A7, текст «погашение») при этом берётся из сообщения.
 *
 * === Суммы ===
 * Полнота выплаты считается от плановой суммы ИЗ СООБЩЕНИЯ
 * (plannedPerBond, валюта выплаты), а не от нашей value_per_bond: у
 * валютного выпуска наша сумма — в валюте номинала (6,37 доллара), а
 * деньги приходят в рублях (537,25). Наша сумма — запасной вариант, когда
 * источник плановую не назвал.
 *
 * === Правила (переписаны 05.10.2026 по реальным сообщениям НРД) ===
 *   деньги пришли полностью и в срок        → A2 / A4 / A6, выплачено;
 *   деньги пришли полностью, но позже срока → B5 «выплачено после
 *       просрочки» (техдефолт был и закрыт), даже если о невыплате мы
 *       узнать не успели;
 *   пришла часть, срок уже наступил         → B1 (красное), статус
 *       partial, отсчёт до полного дефолта;
 *   пришла часть РАНЬШЕ срока               → B1a (жёлтое): срок ещё не
 *       нарушен, отсчёта до дефолта нет;
 *   следующая часть, долг не закрыт         → B4;
 *   остаток пришёл                          → A2 / A4 / A6, если срок не
 *       нарушался, иначе B5;
 *   объявление «не исполнено в срок»        → B2, один раз на выплату;
 *   объявление «дефолт»                     → C2, один раз на выплату;
 *   «передано депонентам» по уже учтённым деньгам → A3 / A5 / A7 (клиенту
 *       не рассылается); если получение мы не видели — засчитывается как
 *       получение;
 *   вторая выплата по той же строке купона от другого корпоративного
 *       действия (купонный доход + дополнительный «процентный доход» у
 *       структурных выпусков) → ещё одно A2 с пометкой.
 * Учтён ли перевод, определяется по дате поступления и сумме (они есть в
 * каждом сообщении НРД о деньгах), а не по порядку сообщений.
 *
 * $silent = true — «тихая» загрузка: события и статусы пишутся как обычно,
 * но клиентам не рассылаются (для первой загрузки истории). В той же
 * транзакции, что и событие, для каждого подписчика создаётся строка
 * notifications со статусом failed и причиной SILENT_REASON — рассыльщик
 * берёт только пары без строки и такие события пропускает.
 *
 * Переносимый SQL (MySQL и SQLite) — офлайн-тесты: tests/test_payments.php
 * (кейсы КЛВЗ, ВЗВТ, ЕвроТранс, СибАвтоТранс, Нэппи Клаб из исследования
 * пользователя) и tests/test_getnews_pipeline.php (реальные сообщения НРД).
 */
final class PaymentProcessor
{
    public const RESULT_PROCESSED = 'processed';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_UNMATCHED = 'unmatched';
    public const RESULT_NO_CHANGE = 'no_change';
    /** Бумаги нет в нашей базе — сообщение не сохраняется. */
    public const RESULT_FOREIGN = 'foreign';

    public const SILENT_REASON = 'тихая загрузка: уведомление не отправлялось';

    private const SOURCE = 'nsd';
    /** Полный дефолт — при просрочке более 10 рабочих дней (правила листинга Мосбиржи). */
    private const DEFAULT_GRACE_WORKING_DAYS = 10;
    /** Если точной даты в графике нет, ищем выплату с тем же днём исполнения не дальше этого числа дней. */
    private const DATE_TOLERANCE_DAYS = 7;
    private const EPSILON = 0.00005;

    /** kind => [таблица, колонка даты, колонка в event_stories, код «получено», код «передано»] */
    private const KINDS = [
        PaymentMessage::KIND_COUPON => ['coupons', 'period_end_date', 'coupon_id', 'A2', 'A3'],
        PaymentMessage::KIND_AMORTIZATION => ['amortizations', 'payment_date_planned', 'amortization_id', 'A4', 'A5'],
        PaymentMessage::KIND_REDEMPTION => ['redemptions', 'payment_date_planned', 'redemption_id', 'A6', 'A7'],
    ];
    /** События, означающие, что срок выплаты нарушен. */
    private const VIOLATION_CODES = ['B1', 'B2', 'B4', 'C2'];

    public function __construct(
        private readonly PDO $db,
        private readonly EventPublisher $publisher,
        private readonly WorkingCalendar $calendar,
        private readonly bool $silent = false,
    ) {
    }

    /**
     * @return array{result: string, events: array<int, string>, note: ?string, retry?: bool}
     *         events — коды созданных событий по порядку; note — пояснение для лога;
     *         retry (только у unmatched) — это повторная попытка по сообщению, которое
     *         уже не удалось привязать раньше: администратору о нём сообщалось
     */
    public function process(PaymentMessage $message): array
    {
        $security = $this->findSecurity($message->isin);
        if ($security === null) {
            return ['result' => self::RESULT_FOREIGN, 'events' => [], 'note' => "бумага с ISIN {$message->isin} не найдена в securities"];
        }

        [$rawId, $isRetry] = $this->registerRawMessage($message);
        if ($rawId === null) {
            return ['result' => self::RESULT_DUPLICATE, 'events' => [], 'note' => 'сообщение уже обработано'];
        }

        $payment = $this->findPayment($message, (int) $security['id']);
        if ($payment === null) {
            $reason = 'выплата (' . PaymentMessage::kindLabel($message->kind) . " на {$message->paymentDate}) не найдена в графике бумаги {$message->isin}";
            $this->markRaw($rawId, 'failed', $reason);

            return ['result' => self::RESULT_UNMATCHED, 'events' => [], 'note' => $reason, 'retry' => $isRetry];
        }

        $this->db->beginTransaction();
        try {
            $result = $this->apply($message, $security, $payment, $rawId);
            $this->markRaw($rawId, 'processed', null);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->markRaw($rawId, 'failed', $e->getMessage());
            throw $e;
        }

        return $result;
    }

    /**
     * Куда ляжет сообщение, без записи в базу — для просмотра (--dry-run).
     *
     * @return array{security: ?array<string, mixed>, payment: ?array<string, mixed>}
     *         payment: строка графика с ключами table (в какой таблице) и match (exact | shifted)
     */
    public function locate(PaymentMessage $message): array
    {
        $security = $this->findSecurity($message->isin);

        return [
            'security' => $security,
            'payment' => $security !== null ? $this->findPayment($message, (int) $security['id']) : null,
        ];
    }

    /**
     * @param array{id: int|string, issuer_id: int|string, short_name: string, isin: string} $security
     * @param array<string, mixed> $payment строка графика из findPayment()
     * @return array{result: string, events: array<int, string>, note: ?string}
     */
    private function apply(PaymentMessage $message, array $security, array $payment, int $rawId): array
    {
        [, , , $receivedCode, $transferredCode] = self::KINDS[$message->kind];
        $status = (string) $payment['status'];
        $ourPlanned = $payment['value_per_bond'] !== null ? (float) $payment['value_per_bond'] : null;
        $planned = $message->plannedPerBond !== null ? (float) $message->plannedPerBond : $ourPlanned;
        $actual = $payment['actual_value_per_bond'] !== null ? (float) $payment['actual_value_per_bond'] : null;
        $amount = $message->amountPerBond !== null ? (float) $message->amountPerBond : null;
        $effectiveDate = $this->calendar->effectiveDate((string) $payment['payment_date']);
        $fullDefaultDate = $payment['full_default_date_planned'] ?? null;
        $wasDeviated = in_array($status, ['partial', 'not_paid', 'tech_default'], true);

        $storyId = $this->findOrOpenStory($security, $payment, $message->kind);
        $history = $this->storyEvents($storyId);
        $violated = array_intersect(array_column($history, 'code'), self::VIOLATION_CODES) !== [];

        $events = [];
        $newStatus = $status;
        $newActual = $actual;
        $resolution = null;
        $note = null;
        $extra = false;

        if ($message->execution === PaymentMessage::EXECUTION_DEFAULT) {
            if ($status === 'paid') {
                $note = 'выплата уже отмечена исполненной — объявление о дефолте не применено';
            } elseif (in_array('C2', array_column($history, 'code'), true)) {
                $note = 'о дефолте по этой выплате уже сообщалось';
            } else {
                $newStatus = $wasDeviated ? $status : 'not_paid';
                $fullDefaultDate ??= $this->calendar->addWorkingDays($effectiveDate, self::DEFAULT_GRACE_WORKING_DAYS);
                $resolution = 'defaulted';
                $events[] = 'C2';
            }
        } elseif ($message->execution === PaymentMessage::EXECUTION_NONE) {
            if ($status === 'paid') {
                $note = 'выплата уже отмечена исполненной — сообщение о неисполнении не применено';
            } elseif (in_array('B2', array_column($history, 'code'), true)) {
                $note = 'о неисполнении по этой выплате уже сообщалось';
            } else {
                $newStatus = $wasDeviated ? $status : 'not_paid';
                $fullDefaultDate ??= $this->calendar->addWorkingDays($effectiveDate, self::DEFAULT_GRACE_WORKING_DAYS);
                $events[] = 'B2';
            }
        } elseif ($this->alreadyCounted($message, $status, $history)) {
            // Те же деньги в следующем сообщении: «передано» — только отметка, повтор «получено» — ничего.
            if ($message->stage === PaymentMessage::STAGE_TRANSFERRED) {
                $events[] = $transferredCode;
            } else {
                $note = 'эти деньги уже учтены';
            }
        } elseif ($status === 'paid') {
            if ($this->isAnotherAction($message, $history)) {
                // Вторая выплата по той же строке графика от другого корпоративного действия.
                $newActual = ($actual ?? 0.0) + ($amount ?? 0.0);
                $extra = true;
                $events[] = $receivedCode;
            } else {
                $note = 'выплата уже отмечена исполненной';
            }
        } else {
            if ($message->execution === PaymentMessage::EXECUTION_FULL && !$wasDeviated) {
                $newActual = $amount ?? $planned;
                $complete = true;
            } else {
                $newActual = $amount !== null ? ($actual ?? 0.0) + $amount : ($planned ?? $actual);
                $complete = $planned === null || $newActual === null || $newActual + self::tolerance($planned) >= $planned;
            }

            if ($complete) {
                $newStatus = 'paid';
                $resolution = 'paid';
                $events[] = $violated || $message->late ? 'B5' : $receivedCode;
            } else {
                $newStatus = 'partial';
                if ($message->beforeDue && !$message->late && !$violated) {
                    // Часть пришла раньше срока: срок не нарушен, отсчёта до дефолта нет.
                    $events[] = $wasDeviated ? 'B4' : 'B1a';
                } else {
                    $fullDefaultDate ??= $this->calendar->addWorkingDays($effectiveDate, self::DEFAULT_GRACE_WORKING_DAYS);
                    $events[] = $violated ? 'B4' : 'B1';
                }
            }
            // «Передано» пришло первым и засчитано как получение — отмечаем и саму передачу.
            if ($message->stage === PaymentMessage::STAGE_TRANSFERRED) {
                $events[] = $transferredCode;
            }
        }

        if ($events === []) {
            return ['result' => self::RESULT_NO_CHANGE, 'events' => [], 'note' => $note];
        }

        $this->updatePayment((string) $payment['table'], (int) $payment['id'], $newActual, $newStatus, $fullDefaultDate);
        if ($resolution !== null) {
            $this->resolveStory($storyId, $resolution);
        }

        $payload = [
            'kind' => $message->kind,
            'isin' => (string) $security['isin'],
            'security_name' => (string) $security['short_name'],
            'payment_date' => (string) $payment['payment_date'],
            'effective_date' => $effectiveDate,
            'message_date' => $message->messageDate,
            'stage' => $message->stage,
            'execution' => $message->execution,
            'amount_planned' => self::money($planned),
            'amount_actual' => self::money($newActual),
            'tranche_amount' => self::money($amount),
            'currency' => $message->currency,
            'record_date' => $message->recordDate,
            'received_date' => $message->receivedDate,
            'transferred_date' => $message->transferredDate,
            'late' => $message->late,
            'before_due' => $message->beforeDue,
            'extra' => $extra,
            'full_default_date' => $newStatus === 'paid' ? null : $fullDefaultDate,
            'working_days_to_default' => $newStatus === 'paid' || $fullDefaultDate === null
                ? null
                : $this->calendar->workingDaysBetween($message->messageDate, $fullDefaultDate),
            'source' => self::SOURCE,
            'source_title' => $message->title,
            'source_note' => $message->sourceNote,
            'action_ref' => $message->actionRef,
        ];

        foreach ($events as $code) {
            $eventId = $this->publisher->publishPaymentEvent(
                eventTypeCode: $code,
                issuerId: (int) $security['issuer_id'],
                securityId: (int) $security['id'],
                eventDate: $message->messageDate,
                statusText: self::statusText($code, $message->kind, $payload),
                payload: $payload,
                storyId: $storyId,
                rawMessageId: $rawId,
                amountPlanned: self::money($planned),
                amountActual: self::money($newActual),
            );
            if ($this->silent) {
                $this->suppressNotifications($eventId, (int) $security['issuer_id']);
            }
        }

        return ['result' => self::RESULT_PROCESSED, 'events' => $events, 'note' => $note];
    }

    /**
     * Учтены ли уже деньги из этого сообщения. В сообщении НРД есть дата
     * поступления и сумма перевода — ищем в истории выплаты событие с теми
     * же датой, суммой и корпоративным действием. Если источник дату
     * поступления не назвал, остаётся прежнее правило: «передано» по
     * выплате, которая уже не planned, — это те же деньги.
     *
     * @param array<int, array{code: string, payload: array<string, mixed>}> $history
     */
    private function alreadyCounted(PaymentMessage $message, string $status, array $history): bool
    {
        if ($message->receivedDate === null || $message->amountPerBond === null) {
            return $message->stage === PaymentMessage::STAGE_TRANSFERRED && $status !== 'planned';
        }

        foreach ($history as $event) {
            $payload = $event['payload'];
            if (in_array($event['code'], ['A3', 'A5', 'A7', 'B2', 'C2'], true) || ($payload['tranche_amount'] ?? null) === null) {
                continue;
            }
            if (($payload['received_date'] ?? null) === $message->receivedDate
                && ($payload['action_ref'] ?? null) === $message->actionRef
                && abs((float) $payload['tranche_amount'] - (float) $message->amountPerBond) < self::tolerance((float) $message->amountPerBond)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Деньги по уже выплаченной строке от ДРУГОГО корпоративного действия
     * (у НРД на одну дату по одной бумаге бывают «Выплата купонного
     * дохода» и «Выплата процентного дохода» — в нашем графике это одна
     * строка купона).
     *
     * @param array<int, array{code: string, payload: array<string, mixed>}> $history
     */
    private function isAnotherAction(PaymentMessage $message, array $history): bool
    {
        if ($message->actionRef === null) {
            return false;
        }
        $known = [];
        foreach ($history as $event) {
            if (($event['payload']['action_ref'] ?? null) !== null) {
                $known[] = (string) $event['payload']['action_ref'];
            }
        }

        return $known !== [] && !in_array($message->actionRef, $known, true);
    }

    /**
     * Регистрирует сообщение в архиве. id = NULL — оно уже обработано
     * (повтор); иначе id строки raw_messages — новой или прежней неудачной
     * (её пробуем заново, второй элемент — true).
     *
     * @return array{0: ?int, 1: bool}
     */
    private function registerRawMessage(PaymentMessage $message): array
    {
        $stmt = $this->db->prepare('SELECT id, processing_status FROM raw_messages WHERE source = :source AND source_ref = :source_ref ORDER BY id LIMIT 1');
        $stmt->execute(['source' => self::SOURCE, 'source_ref' => $message->sourceRef]);
        $existing = $stmt->fetch();
        if ($existing !== false) {
            if (in_array($existing['processing_status'], ['processed', 'ignored'], true)) {
                return [null, false];
            }
            $this->db->prepare('UPDATE raw_messages SET retry_count = retry_count + 1, last_retry_at = :now WHERE id = :id')
                ->execute(['now' => date('Y-m-d H:i:s'), 'id' => (int) $existing['id']]);

            return [(int) $existing['id'], true];
        }

        $this->db->prepare(
            "INSERT INTO raw_messages (source, source_ref, isin, raw_payload, processing_status)
             VALUES (:source, :source_ref, :isin, :raw_payload, 'new')"
        )->execute([
            'source' => self::SOURCE,
            'source_ref' => mb_substr($message->sourceRef, 0, 255),
            'isin' => mb_substr($message->isin, 0, 12),
            'raw_payload' => json_encode($message->raw, JSON_UNESCAPED_UNICODE),
        ]);

        return [(int) $this->db->lastInsertId(), false];
    }

    private function markRaw(int $rawId, string $status, ?string $error): void
    {
        $this->db->prepare('UPDATE raw_messages SET processing_status = :status, processing_error = :error, processed_at = :now WHERE id = :id')
            ->execute(['status' => $status, 'error' => $error, 'now' => date('Y-m-d H:i:s'), 'id' => $rawId]);
    }

    /** @return array{id: int|string, issuer_id: int|string, short_name: string, isin: string}|null */
    private function findSecurity(string $isin): ?array
    {
        $stmt = $this->db->prepare('SELECT id, issuer_id, short_name, isin FROM securities WHERE isin = :isin');
        $stmt->execute(['isin' => $isin]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Строка графика для сообщения. Погашение: строка redemptions, если в
     * ней есть сумма; иначе — строка amortizations той же даты (см.
     * докблок класса, «Погашение в нашем графике»).
     *
     * @return array<string, mixed>|null строка + table, story_column, match (exact | shifted)
     */
    private function findPayment(PaymentMessage $message, int $securityId): ?array
    {
        $row = $this->findRow($message->kind, $securityId, $message->paymentDate);
        if ($message->kind === PaymentMessage::KIND_REDEMPTION && ($row === null || (float) ($row['value_per_bond'] ?? 0) < self::EPSILON)) {
            $final = $this->findRow(PaymentMessage::KIND_AMORTIZATION, $securityId, $message->paymentDate);
            if ($final !== null) {
                return $final;
            }
        }

        return $row;
    }

    /**
     * Сначала точная дата; если нет — единственная выплата этой бумаги с
     * тем же днём исполнения (источник называет дату с переносом с
     * выходного, а в графике стоит дата без переноса).
     *
     * @return array<string, mixed>|null
     */
    private function findRow(string $kind, int $securityId, string $paymentDate): ?array
    {
        [$table, $dateColumn, $storyColumn] = self::KINDS[$kind];
        $extra = $kind === PaymentMessage::KIND_REDEMPTION ? " AND redemption_type = 'scheduled_maturity'" : '';
        $columns = "id, {$dateColumn} AS payment_date, value_per_bond, actual_value_per_bond, full_default_date_planned, status";
        $meta = ['table' => $table, 'story_column' => $storyColumn];

        $stmt = $this->db->prepare("SELECT {$columns} FROM {$table} WHERE security_id = :security_id AND {$dateColumn} = :payment_date{$extra}");
        $stmt->execute(['security_id' => $securityId, 'payment_date' => $paymentDate]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row + $meta + ['match' => 'exact'];
        }

        $from = date('Y-m-d', strtotime($paymentDate . ' -' . self::DATE_TOLERANCE_DAYS . ' days'));
        $to = date('Y-m-d', strtotime($paymentDate . ' +' . self::DATE_TOLERANCE_DAYS . ' days'));
        $stmt = $this->db->prepare("SELECT {$columns} FROM {$table} WHERE security_id = :security_id AND {$dateColumn} BETWEEN :date_from AND :date_to{$extra}");
        $stmt->execute(['security_id' => $securityId, 'date_from' => $from, 'date_to' => $to]);
        $target = $this->calendar->effectiveDate($paymentDate);
        $matches = array_values(array_filter(
            $stmt->fetchAll(),
            fn (array $candidate): bool => $this->calendar->effectiveDate((string) $candidate['payment_date']) === $target,
        ));

        return count($matches) === 1 ? $matches[0] + $meta + ['match' => 'shifted'] : null;
    }

    /**
     * @param array{id: int|string, short_name: string} $security
     * @param array<string, mixed> $payment
     */
    private function findOrOpenStory(array $security, array $payment, string $kind): int
    {
        $column = (string) $payment['story_column'];
        $stmt = $this->db->prepare("SELECT id FROM event_stories WHERE {$column} = :payment_id ORDER BY id DESC LIMIT 1");
        $stmt->execute(['payment_id' => (int) $payment['id']]);
        $storyId = $stmt->fetchColumn();
        if ($storyId !== false) {
            return (int) $storyId;
        }

        $title = self::ucfirst(PaymentMessage::kindLabel($kind)) . ' ' . self::formatDate((string) $payment['payment_date']) . ': ' . $security['short_name'];
        $this->db->prepare("INSERT INTO event_stories (security_id, {$column}, title, status) VALUES (:security_id, :payment_id, :title, 'open')")
            ->execute(['security_id' => (int) $security['id'], 'payment_id' => (int) $payment['id'], 'title' => mb_substr($title, 0, 255)]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * События истории выплаты с разобранным payload.
     *
     * @return array<int, array{code: string, payload: array<string, mixed>}>
     */
    private function storyEvents(int $storyId): array
    {
        $stmt = $this->db->prepare('SELECT event_type_code, payload_json FROM events WHERE story_id = :story_id ORDER BY id');
        $stmt->execute(['story_id' => $storyId]);

        $events = [];
        foreach ($stmt->fetchAll() as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $events[] = ['code' => (string) $row['event_type_code'], 'payload' => is_array($payload) ? $payload : []];
        }

        return $events;
    }

    private function resolveStory(int $storyId, string $resolution): void
    {
        $this->db->prepare("UPDATE event_stories SET status = 'resolved', resolution_type = :resolution, resolved_at = :now WHERE id = :id")
            ->execute(['resolution' => $resolution, 'now' => date('Y-m-d H:i:s'), 'id' => $storyId]);
    }

    private function updatePayment(string $table, int $paymentId, ?float $actual, string $status, ?string $fullDefaultDate): void
    {
        $this->db->prepare("UPDATE {$table} SET actual_value_per_bond = :actual, status = :status, full_default_date_planned = :full_default WHERE id = :id")
            ->execute(['actual' => self::money($actual), 'status' => $status, 'full_default' => $fullDefaultDate, 'id' => $paymentId]);
    }

    /**
     * «Тихая» загрузка: помечает событие как не подлежащее рассылке для
     * всех, кто сейчас отслеживает эмитента (см. докблок класса).
     */
    private function suppressNotifications(int $eventId, int $issuerId): void
    {
        $users = $this->db->prepare('SELECT DISTINCT user_id FROM watchlist WHERE issuer_id = :issuer_id');
        $users->execute(['issuer_id' => $issuerId]);
        $insert = $this->db->prepare(
            "INSERT INTO notifications (user_id, event_id, channel, status, failure_reason) VALUES (:user_id, :event_id, 'telegram', 'failed', :reason)"
        );
        foreach ($users->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $insert->execute(['user_id' => (int) $userId, 'event_id' => $eventId, 'reason' => self::SILENT_REASON]);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function statusText(string $code, string $kind, array $payload): string
    {
        $what = self::ucfirst(PaymentMessage::kindLabel($kind)) . ' ' . self::formatDate((string) $payload['payment_date']);

        return match ($code) {
            'A2', 'A4', 'A6' => "{$what}: получено НРД",
            'A3', 'A5', 'A7' => "{$what}: передано депонентам",
            'B1' => "{$what}: выплачено частично",
            'B1a' => "{$what}: до срока получена часть суммы",
            'B2' => "{$what}: не исполнено в срок",
            'B4' => "{$what}: доплата",
            'B5' => "{$what}: исполнено после просрочки",
            'C2' => "{$what}: НРД объявил дефолт",
            default => $what,
        };
    }

    /**
     * Допуск при сравнении сумм. В базе сумма хранится с 4 знаками, НРД
     * называет части выплаты с 9 знаками (269,104907468 + 730,895092532 =
     * 1000) — после округления сумма частей может не дотянуть до плановой
     * на доли копейки.
     */
    private static function tolerance(float $amount): float
    {
        return max(0.0002, abs($amount) * 1e-6);
    }

    private static function money(?float $value): ?string
    {
        return $value !== null ? number_format($value, 4, '.', '') : null;
    }

    private static function formatDate(string $date): string
    {
        return date('d.m.Y', (int) strtotime($date));
    }

    private static function ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
