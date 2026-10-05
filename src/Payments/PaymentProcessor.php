<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use BondKeeper\Events\EventPublisher;
use PDO;

/**
 * Ядро обработки выплаты (Этап 5, см. docs/STAGE5_PAYMENTS.md): по одному
 * нормализованному сообщению (PaymentMessage) —
 *   1. сохраняет сообщение источника в raw_messages (собственный архив:
 *      задним числом лента НРД сообщение может не показать) и отсекает
 *      повторы по source_ref;
 *   2. находит выплату в графике (coupons / amortizations / redemptions)
 *      по ISIN и ДАТЕ — номера купона в графике нет с миграции 006;
 *   3. записывает факт: actual_value_per_bond, status, при отклонении —
 *      full_default_date_planned (день исполнения + 10 рабочих дней);
 *   4. ведёт «историю» выплаты (event_stories): одна на выплату, серия
 *      сообщений (невыплата → транши → исполнение) — одна лента;
 *   5. создаёт событие нужного типа через EventPublisher.
 *
 * Какое событие создаётся (решения пользователя 04.10.2026):
 *   - получено НРД полностью, выплата шла штатно → A2 (купон) / A4
 *     (амортизация) / A6 (погашение) — выплата состоялась, клиент
 *     уведомляется, история закрыта;
 *   - передано депонентам → A3 / A5 / A7 — только отметка в истории
 *     (notify_client = FALSE в справочнике);
 *   - частичная выплата: первая → B1, следующие транши → B4;
 *   - не исполнена в срок → B2 (один раз на историю);
 *   - деньги пришли полностью после отклонения → B5, история закрыта.
 * Деньги считаются только по сообщениям «получено»: «передано» — те же
 * деньги на следующем шаге, второй раз их не прибавляем. Исключение —
 * если «передано» пришло первым (у НРД бывает «О получении и передаче»
 * одним сообщением): тогда оно же засчитывается как получение.
 *
 * Чистая логика поверх PDO, переносимый SQL — офлайн-тест на SQLite:
 * tests/test_payments.php (кейсы КЛВЗ, ВЗВТ, ЕвроТранс,
 * СибАвтоТранс, Нэппи Клаб из исследования пользователя).
 */
final class PaymentProcessor
{
    public const RESULT_PROCESSED = 'processed';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_UNMATCHED = 'unmatched';
    public const RESULT_NO_CHANGE = 'no_change';

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

    public function __construct(
        private readonly PDO $db,
        private readonly EventPublisher $publisher,
        private readonly WorkingCalendar $calendar,
    ) {
    }

    /**
     * @return array{result: string, events: array<int, string>, note: ?string}
     *         events — коды созданных событий по порядку; note — пояснение для лога
     */
    public function process(PaymentMessage $message): array
    {
        $rawId = $this->registerRawMessage($message);
        if ($rawId === null) {
            return ['result' => self::RESULT_DUPLICATE, 'events' => [], 'note' => 'сообщение уже обработано'];
        }

        $security = $this->findSecurity($message->isin);
        if ($security === null) {
            return $this->fail($rawId, "бумага с ISIN {$message->isin} не найдена в securities");
        }
        $payment = $this->findPayment($message, (int) $security['id']);
        if ($payment === null) {
            return $this->fail($rawId, 'выплата (' . PaymentMessage::kindLabel($message->kind) . " на {$message->paymentDate}) не найдена в графике бумаги {$message->isin}");
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
     * @param array{id: int|string, issuer_id: int|string, short_name: string, isin: string} $security
     * @param array{id: int|string, payment_date: string, value_per_bond: ?string, actual_value_per_bond: ?string, full_default_date_planned: ?string, status: string} $payment
     * @return array{result: string, events: array<int, string>, note: ?string}
     */
    private function apply(PaymentMessage $message, array $security, array $payment, int $rawId): array
    {
        [, , , $receivedCode, $transferredCode] = self::KINDS[$message->kind];
        $status = (string) $payment['status'];
        $planned = $payment['value_per_bond'] !== null ? (float) $payment['value_per_bond'] : null;
        $actual = $payment['actual_value_per_bond'] !== null ? (float) $payment['actual_value_per_bond'] : null;
        $amount = $message->amountPerBond !== null ? (float) $message->amountPerBond : null;
        $effectiveDate = $this->calendar->effectiveDate((string) $payment['payment_date']);
        $fullDefaultDate = $payment['full_default_date_planned'] ?? null;
        $wasDeviated = in_array($status, ['partial', 'not_paid', 'tech_default'], true);

        $storyId = $this->findOrOpenStory($message->kind, $security, $payment);
        $events = [];
        $newStatus = $status;
        $newActual = $actual;
        $resolve = false;
        $note = null;

        $isTransferOnly = $message->stage === PaymentMessage::STAGE_TRANSFERRED && $status !== 'planned';

        if ($message->execution === PaymentMessage::EXECUTION_NONE) {
            if ($status === 'paid') {
                $note = 'выплата уже отмечена исполненной — сообщение о неисполнении не применено';
            } elseif ($this->storyHasEvent($storyId, 'B2')) {
                $note = 'о неисполнении по этой выплате уже сообщалось';
            } else {
                $newStatus = $wasDeviated ? $status : 'not_paid';
                $fullDefaultDate ??= $this->calendar->addWorkingDays($effectiveDate, self::DEFAULT_GRACE_WORKING_DAYS);
                $events[] = 'B2';
            }
        } elseif ($isTransferOnly) {
            // Те же деньги на следующем шаге — только отметка «передано депонентам».
            $events[] = $transferredCode;
        } elseif ($message->execution === PaymentMessage::EXECUTION_PARTIAL) {
            $newActual = ($actual ?? 0.0) + ($amount ?? 0.0);
            if ($planned !== null && $newActual + self::EPSILON >= $planned) {
                $newStatus = 'paid';
                $resolve = true;
                $events[] = $wasDeviated ? 'B5' : $receivedCode;
            } else {
                $newStatus = 'partial';
                $fullDefaultDate ??= $this->calendar->addWorkingDays($effectiveDate, self::DEFAULT_GRACE_WORKING_DAYS);
                $events[] = $wasDeviated ? 'B4' : 'B1';
            }
        } elseif ($status === 'paid') {
            $note = 'выплата уже отмечена исполненной';
        } elseif (!$wasDeviated) {
            // Штатная выплата: деньги получены НРД полностью.
            $newActual = $amount ?? $planned;
            $newStatus = 'paid';
            $resolve = true;
            $events[] = $receivedCode;
        } else {
            // Деньги после отклонения: либо закрывают долг, либо очередной транш.
            $newActual = $amount !== null ? ($actual ?? 0.0) + $amount : ($planned ?? $actual);
            if ($planned !== null && $newActual !== null && $newActual + self::EPSILON < $planned) {
                $newStatus = 'partial';
                $events[] = 'B4';
            } else {
                $newStatus = 'paid';
                $resolve = true;
                $events[] = 'B5';
            }
        }

        // «Передано» пришло первым и засчитано как получение — отмечаем и саму передачу.
        if ($message->stage === PaymentMessage::STAGE_TRANSFERRED && !$isTransferOnly && $events !== [] && $events[0] !== 'B2') {
            $events[] = $transferredCode;
        }

        if ($events === []) {
            return ['result' => self::RESULT_NO_CHANGE, 'events' => [], 'note' => $note];
        }

        $this->updatePayment($message->kind, (int) $payment['id'], $newActual, $newStatus, $fullDefaultDate);
        if ($resolve) {
            $this->resolveStory($storyId);
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
            'record_date' => $message->recordDate,
            'full_default_date' => $newStatus === 'paid' ? null : $fullDefaultDate,
            'working_days_to_default' => $newStatus === 'paid' || $fullDefaultDate === null
                ? null
                : $this->calendar->workingDaysBetween($message->messageDate, $fullDefaultDate),
            'source' => self::SOURCE,
            'source_title' => $message->title,
        ];

        foreach ($events as $code) {
            $this->publisher->publishPaymentEvent(
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
        }

        return ['result' => self::RESULT_PROCESSED, 'events' => $events, 'note' => $note];
    }

    /**
     * Регистрирует сообщение в архиве. NULL — оно уже обработано (повтор);
     * иначе id строки raw_messages (новой или прежней неудачной — её
     * пробуем заново).
     */
    private function registerRawMessage(PaymentMessage $message): ?int
    {
        $stmt = $this->db->prepare('SELECT id, processing_status FROM raw_messages WHERE source = :source AND source_ref = :source_ref ORDER BY id LIMIT 1');
        $stmt->execute(['source' => self::SOURCE, 'source_ref' => $message->sourceRef]);
        $existing = $stmt->fetch();
        if ($existing !== false) {
            if (in_array($existing['processing_status'], ['processed', 'ignored'], true)) {
                return null;
            }
            $this->db->prepare('UPDATE raw_messages SET retry_count = retry_count + 1, last_retry_at = :now WHERE id = :id')
                ->execute(['now' => date('Y-m-d H:i:s'), 'id' => (int) $existing['id']]);

            return (int) $existing['id'];
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

        return (int) $this->db->lastInsertId();
    }

    /** @return array{result: string, events: array<int, string>, note: ?string} */
    private function fail(int $rawId, string $reason): array
    {
        $this->markRaw($rawId, 'failed', $reason);

        return ['result' => self::RESULT_UNMATCHED, 'events' => [], 'note' => $reason];
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
     * Выплата в графике: сначала точная дата; если нет — единственная
     * выплата этой бумаги с тем же днём исполнения (источник мог назвать
     * дату с переносом с выходного, а в графике стоит дата без переноса).
     *
     * @return array{id: int|string, payment_date: string, value_per_bond: ?string, actual_value_per_bond: ?string, full_default_date_planned: ?string, status: string}|null
     */
    private function findPayment(PaymentMessage $message, int $securityId): ?array
    {
        [$table, $dateColumn] = self::KINDS[$message->kind];
        $extra = $message->kind === PaymentMessage::KIND_REDEMPTION ? " AND redemption_type = 'scheduled_maturity'" : '';
        $columns = "id, {$dateColumn} AS payment_date, value_per_bond, actual_value_per_bond, full_default_date_planned, status";

        $stmt = $this->db->prepare("SELECT {$columns} FROM {$table} WHERE security_id = :security_id AND {$dateColumn} = :payment_date{$extra}");
        $stmt->execute(['security_id' => $securityId, 'payment_date' => $message->paymentDate]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }

        $from = date('Y-m-d', strtotime($message->paymentDate . ' -' . self::DATE_TOLERANCE_DAYS . ' days'));
        $to = date('Y-m-d', strtotime($message->paymentDate . ' +' . self::DATE_TOLERANCE_DAYS . ' days'));
        $stmt = $this->db->prepare("SELECT {$columns} FROM {$table} WHERE security_id = :security_id AND {$dateColumn} BETWEEN :date_from AND :date_to{$extra}");
        $stmt->execute(['security_id' => $securityId, 'date_from' => $from, 'date_to' => $to]);
        $target = $this->calendar->effectiveDate($message->paymentDate);
        $matches = array_values(array_filter(
            $stmt->fetchAll(),
            fn (array $candidate): bool => $this->calendar->effectiveDate((string) $candidate['payment_date']) === $target,
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param array{id: int|string, short_name: string} $security
     * @param array{id: int|string, payment_date: string} $payment
     */
    private function findOrOpenStory(string $kind, array $security, array $payment): int
    {
        $column = self::KINDS[$kind][2];
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

    private function storyHasEvent(int $storyId, string $eventTypeCode): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM events WHERE story_id = :story_id AND event_type_code = :code LIMIT 1');
        $stmt->execute(['story_id' => $storyId, 'code' => $eventTypeCode]);

        return $stmt->fetchColumn() !== false;
    }

    private function resolveStory(int $storyId): void
    {
        $this->db->prepare("UPDATE event_stories SET status = 'resolved', resolution_type = 'paid', resolved_at = :now WHERE id = :id")
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $storyId]);
    }

    private function updatePayment(string $kind, int $paymentId, ?float $actual, string $status, ?string $fullDefaultDate): void
    {
        $table = self::KINDS[$kind][0];
        $this->db->prepare("UPDATE {$table} SET actual_value_per_bond = :actual, status = :status, full_default_date_planned = :full_default WHERE id = :id")
            ->execute(['actual' => self::money($actual), 'status' => $status, 'full_default' => $fullDefaultDate, 'id' => $paymentId]);
    }

    /** @param array<string, mixed> $payload */
    private static function statusText(string $code, string $kind, array $payload): string
    {
        $what = self::ucfirst(PaymentMessage::kindLabel($kind)) . ' ' . self::formatDate((string) $payload['payment_date']);

        return match ($code) {
            'A2', 'A4', 'A6' => "{$what}: получено НРД",
            'A3', 'A5', 'A7' => "{$what}: передано депонентам",
            'B1' => "{$what}: выплачено частично",
            'B2' => "{$what}: не исполнено в срок",
            'B4' => "{$what}: доплата",
            'B5' => "{$what}: исполнено после просрочки",
            default => $what,
        };
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
