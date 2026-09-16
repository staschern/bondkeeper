<?php

declare(strict_types=1);

namespace BondKeeper\Events;

use PDO;

/**
 * Этап 4 (см. docs/STAGE4_EVENT_ENGINE.md) — единственная точка, где
 * рождаются строки `events`/`raw_messages` для MVP событийного движка.
 * Схема (`event_types`, `raw_messages`, `events`, ...) существует в БД с
 * САМОГО ПЕРВОГО дня проекта (миграция 001) — этот класс не придумывает
 * новую архитектуру, а наконец подключает код к уже готовой.
 *
 * Событие создаётся ТУТ ЖЕ, в момент, когда вызывающий код и так уже
 * знает "было/стало" (переданными параметрами) — не отдельным
 * опросом-диффом снапшотов. Вызывается ТОЛЬКО из импортёров уровня
 * "действие" (RatingActionsWriter, FnsBlocksImporter) — НЕ из полных
 * периодических выгрузок current_ratings (NkrImporter/ExpertRaImporter/
 * AcraImporter/ManualRatingsImporter) — иначе один и тот же реальный
 * релиз задвоился бы событием от обоих путей записи (см. докблок в
 * STAGE4).
 *
 * Фильтр существенности — решение пользователя (4 сентября 2026, ЧЕТВЁРТЫЙ
 * триггер E1 про число банков добавлен 17 сентября 2026, см. ниже):
 *   - C5 (рейтинговое действие) — фильтра НЕТ, событие ВСЕГДА, включая
 *     "подтверждено без изменений".
 *   - E1 (блокировка ФНС) — 4 триггера: начало блокировки, изменение
 *     СУММЫ, изменение ЧИСЛА банков (без изменения суммы — раньше, 4
 *     сентября, было сознательно проигнорировано как неважное для
 *     клиента; решение пересмотрено пользователем 17 сентября — число
 *     заблокированных счетов всё же стоит отдельного уведомления), полное
 *     снятие. Ни один из четырёх не наступил — событие не создаётся
 *     (publishFnsBlockChange() вернёт null).
 */
final class EventPublisher
{
    /** @var array<string, string> event_type_code => default_priority, кэш на инстанс */
    private array $priorityCache = [];

    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /**
     * C5. Вызывается из RatingActionsWriter::upsert() СРАЗУ ПОСЛЕ
     * успешного апсерта rating_actions, теми же значениями — ничего
     * заново не читать из БД. Дату публикации (время суток) агентства
     * НЕ дают (см. вся история этого проекта с НКР/НРА/Эксперт РА) —
     * events.published_at честно остаётся NULL, а не выдумывается как
     * полночь; events.event_date — дата действия, она есть всегда.
     */
    public function publishRatingAction(
        int $issuerId,
        string $agency,
        string $actionDate,
        ?string $ratingFrom,
        string $ratingTo,
        ?string $outlookFrom,
        ?string $outlookTo,
        ?string $sourceUrl,
        ?string $sourceTitle,
    ): int {
        $rawMessageId = $this->insertRawMessage('rating_agency', $sourceUrl, [
            'agency' => $agency,
            'action_date' => $actionDate,
            'rating_from' => $ratingFrom,
            'rating_to' => $ratingTo,
            'outlook_from' => $outlookFrom,
            'outlook_to' => $outlookTo,
            'source_url' => $sourceUrl,
            'source_title' => $sourceTitle,
        ]);

        $statusText = $sourceTitle ?? $this->buildRatingStatusText($ratingFrom, $ratingTo, $outlookTo);

        return $this->insertEvent(
            issuerId: $issuerId,
            eventTypeCode: 'C5',
            eventDate: $actionDate,
            publishedAt: null,
            statusText: $statusText,
            amountActual: null,
            payload: [
                'agency' => $agency,
                'rating_from' => $ratingFrom,
                'rating_to' => $ratingTo,
                'outlook_from' => $outlookFrom,
                'outlook_to' => $outlookTo,
                'source_url' => $sourceUrl,
            ],
            rawMessageId: $rawMessageId,
        );
    }

    /**
     * E1. Вызывается из FnsBlocksImporter::applyResult() — на входе уже
     * известны И старое состояние (прочитанное ДО апсерта той же
     * строки fns_blocks), И новое (то, что вот-вот запишется). Суммы —
     * строками (тот же вид, что FnsBlocksImporter::parseAmount() кладёт
     * в БД: DECIMAL(15,2), сравниваются как числа через amountsDiffer(),
     * не как сырые строки — форматирование источника vs то, что вернул
     * SELECT из уже существующей строки, не гарантированно побайтово
     * совпадает даже при той же сумме).
     *
     * Возвращает null, если ни один из 4 триггеров не наступил (сумма и
     * число банков те же, блокировка не появилась и не пропала) —
     * событие НЕ создаётся. Различие "какой именно" — в payload_json.kind
     * и в тексте status_text, event_type_code у всех один — 'E1' (в
     * event_types нет отдельных кодов на каждый подслучай, это осознанно,
     * см. STAGE4). Приоритет при одновременном изменении суммы И числа
     * банков — 'amount_changed' (сумма информативнее для клиента, см.
     * буквенный порядок match ниже) — 'count_changed' срабатывает, только
     * когда сумма НЕ изменилась, иначе на одном и том же реальном событии
     * ушли бы два разных уведомления подряд.
     */
    public function publishFnsBlockChange(
        int $issuerId,
        bool $oldBlocked,
        ?string $oldBlockedAmount,
        int $oldActiveBankCount,
        bool $newBlocked,
        ?string $newBlockedAmount,
        int $newActiveBankCount,
        string $blockDate,
        ?string $reason,
        ?string $sourceReference,
    ): ?int {
        $kind = match (true) {
            !$oldBlocked && $newBlocked => 'started',
            $oldBlocked && !$newBlocked => 'lifted',
            $oldBlocked && $newBlocked && $this->amountsDiffer($oldBlockedAmount, $newBlockedAmount) => 'amount_changed',
            $oldBlocked && $newBlocked && $oldActiveBankCount !== $newActiveBankCount => 'count_changed',
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        // "Снято" фиксируем датой, когда МЫ это заметили (та же дата,
        // что уходит в fns_blocks.unblock_date=CURDATE() — см. докблок
        // FnsBlocksImporter и честную оговорку про приблизительность в
        // STAGE4), а не датой решения из предыдущей активной блокировки.
        $eventDate = $kind === 'lifted' ? date('Y-m-d') : $blockDate;

        $rawMessageId = $this->insertRawMessage('fns', $sourceReference, [
            'kind' => $kind,
            'old_blocked' => $oldBlocked,
            'old_blocked_amount' => $oldBlockedAmount,
            'old_active_bank_count' => $oldActiveBankCount,
            'new_blocked' => $newBlocked,
            'new_blocked_amount' => $newBlockedAmount,
            'active_bank_count' => $newActiveBankCount,
            'block_date' => $blockDate,
            'reason' => $reason,
        ]);

        $statusText = match ($kind) {
            'started' => "Новая блокировка счетов, банков: {$newActiveBankCount}",
            'amount_changed' => "Сумма блокировки изменена: {$oldBlockedAmount} → {$newBlockedAmount}",
            'count_changed' => "Число заблокированных счетов изменено: {$oldActiveBankCount} → {$newActiveBankCount}",
            'lifted' => 'Блокировка счетов снята',
        };

        return $this->insertEvent(
            issuerId: $issuerId,
            eventTypeCode: 'E1',
            eventDate: $eventDate,
            publishedAt: null,
            statusText: $statusText,
            amountActual: $kind === 'lifted' ? null : $newBlockedAmount,
            payload: [
                'kind' => $kind,
                'old_blocked_amount' => $oldBlockedAmount,
                'new_blocked_amount' => $newBlockedAmount,
                'active_bank_count' => $newActiveBankCount,
                'block_date' => $blockDate,
                'reason' => $reason,
            ],
            rawMessageId: $rawMessageId,
        );
    }

    /**
     * DECIMAL(15,2) в БД — сравниваем как числа с округлением до 2
     * знаков, не как сырые строки: значение "только что распарсенное"
     * (FnsBlocksImporter::parseAmount(), тримленная строка из ответа
     * ФНС) и значение "прочитанное обратно из БД" (уже нормализовано
     * MySQL) не обязаны совпадать побайтово даже при той же сумме
     * (например, разное число незначащих нулей).
     */
    private function amountsDiffer(?string $a, ?string $b): bool
    {
        if ($a === null && $b === null) {
            return false;
        }
        if ($a === null || $b === null) {
            return true;
        }

        return number_format((float) $a, 2, '.', '') !== number_format((float) $b, 2, '.', '');
    }

    private function buildRatingStatusText(?string $ratingFrom, string $ratingTo, ?string $outlookTo): string
    {
        $text = ($ratingFrom !== null && $ratingFrom !== $ratingTo)
            ? "{$ratingFrom} → {$ratingTo}"
            : "подтверждён на уровне {$ratingTo}";

        return $outlookTo !== null ? "{$text}, прогноз: {$outlookTo}" : $text;
    }

    /** @param array<string, mixed> $payload */
    private function insertRawMessage(string $source, ?string $sourceRef, array $payload): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO raw_messages (source, source_ref, raw_payload, processing_status, processed_at)
             VALUES (:source, :source_ref, :raw_payload, \'processed\', NOW())'
        );
        $stmt->execute([
            'source' => $source,
            'source_ref' => $sourceRef !== null ? mb_substr($sourceRef, 0, 255) : null,
            'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $payload */
    private function insertEvent(
        int $issuerId,
        string $eventTypeCode,
        string $eventDate,
        ?string $publishedAt,
        string $statusText,
        ?string $amountActual,
        array $payload,
        int $rawMessageId,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO events
                (issuer_id, event_type_code, raw_message_id, event_date, published_at,
                 status_text, amount_actual, priority, payload_json)
             VALUES
                (:issuer_id, :event_type_code, :raw_message_id, :event_date, :published_at,
                 :status_text, :amount_actual, :priority, :payload_json)'
        );
        $stmt->execute([
            'issuer_id' => $issuerId,
            'event_type_code' => $eventTypeCode,
            'raw_message_id' => $rawMessageId,
            'event_date' => $eventDate,
            'published_at' => $publishedAt,
            'status_text' => mb_substr($statusText, 0, 255),
            'amount_actual' => $amountActual,
            'priority' => $this->defaultPriority($eventTypeCode),
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * event_types.default_priority — читаем из справочника, а не
     * хардкодим 'yellow' в коде: таксономия и так уже засеяна в
     * миграции 001 (см. STAGE4_EVENT_ENGINE.md), пусть БД остаётся
     * единственным источником правды на этот счёт. Событие-специфичная
     * эскалация приоритета (например, отзыв рейтинга — красный, а не
     * жёлтый по умолчанию) — сознательно вне MVP, см. STAGE4.
     */
    private function defaultPriority(string $eventTypeCode): string
    {
        if (!isset($this->priorityCache[$eventTypeCode])) {
            $stmt = $this->db->prepare('SELECT default_priority FROM event_types WHERE code = :code');
            $stmt->execute(['code' => $eventTypeCode]);
            $priority = $stmt->fetchColumn();
            $this->priorityCache[$eventTypeCode] = $priority !== false ? (string) $priority : 'info';
        }

        return $this->priorityCache[$eventTypeCode];
    }
}
