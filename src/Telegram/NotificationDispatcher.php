<?php

declare(strict_types=1);

namespace BondKeeper\Telegram;

use PDO;
use PDOException;

/**
 * Этап 4, Фаза 3 (см. docs/STAGE4_EVENT_ENGINE.md, раздел "Рассылка
 * уведомлений") — берёт уже созданные `events` (Фаза 1: `EventPublisher`)
 * и реально доставляет их подписчикам из `watchlist` в Telegram.
 *
 * Идемпотентность — двухуровневая, обе гарантированно нужны:
 *   1. Сам SELECT новых пар (user_id, event_id) уже исключает те, для
 *      которых строка в `notifications` существует (LEFT JOIN ... IS
 *      NULL) — обычный, "счастливый" путь, не даёт выбрать одно и то
 *      же дважды В ОДНОМ проходе.
 *   2. UNIQUE KEY (user_id, event_id, channel) в самой таблице — страховка
 *      на случай гонки (два параллельных прохода дispatchPending(),
 *      что не должно происходить при однопоточном демоне, но лучше не
 *      полагаться на это молча) — INSERT просто ловит нарушение ключа
 *      и трактует как "уже обработано", не ошибка.
 *
 * Порядок для КАЖДОЙ пары: сперва INSERT ... status='queued' (это и
 * есть точка идемпотентности #2), только потом sendMessage() — если
 * упасть между вставкой строки и отправкой, следующий проход увидит
 * status='queued' и решит, что делать (см. requeueStuckQueued()) —
 * не тихая потеря события.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly PDO $db,
        private readonly TelegramClientInterface $telegram,
    ) {
    }

    /** @return int сколько уведомлений реально отправлено в этом проходе */
    public function dispatchPending(): int
    {
        $sent = 0;
        foreach ($this->fetchPendingPairs() as $pair) {
            if ($this->dispatchOne((int) $pair['user_id'], (int) $pair['event_id'])) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @return array<int, array{user_id: int|string, event_id: int|string}> */
    private function fetchPendingPairs(): array
    {
        return $this->db->query(
            "SELECT DISTINCT w.user_id, e.id AS event_id
             FROM events e
             JOIN watchlist w ON w.issuer_id = e.issuer_id
             LEFT JOIN notifications n ON n.event_id = e.id AND n.user_id = w.user_id AND n.channel = 'telegram'
             JOIN event_types et ON et.code = e.event_type_code
             WHERE et.notify_client = 1 AND n.id IS NULL"
        )->fetchAll();
    }

    private function dispatchOne(int $userId, int $eventId): bool
    {
        try {
            $this->db->prepare(
                "INSERT INTO notifications (user_id, event_id, channel, status) VALUES (:user_id, :event_id, 'telegram', 'queued')"
            )->execute(['user_id' => $userId, 'event_id' => $eventId]);
        } catch (PDOException $e) {
            if ($this->isDuplicateKeyViolation($e)) {
                return false; // кто-то (или предыдущий проход) уже создал эту пару — не наша забота больше
            }
            throw $e;
        }

        $notificationId = (int) $this->db->lastInsertId();

        $user = $this->fetchUser($userId);
        if ($user === null) {
            // Не должно случаться (FK user_id -> users.id) — но лучше
            // честно провалить конкретное уведомление, чем упасть на
            // всём проходе из-за одной сиротской строки.
            $this->markFailed($notificationId, 'user not found');

            return false;
        }

        if ($user['telegram_bot_blocked']) {
            $this->markFailed($notificationId, 'user blocked bot (known already)');

            return false;
        }

        $event = $this->fetchEvent($eventId);
        if ($event === null) {
            $this->markFailed($notificationId, 'event not found');

            return false;
        }

        $text = $this->buildMessageText((string) $event['event_type_code'], $this->decodePayload($event['payload_json']), (string) $event['issuer_name']);

        if ($this->telegram->sendMessage($user['telegram_id'], $text)) {
            $this->markSent($notificationId);

            return true;
        }

        if ($this->telegram->lastSendWasBlockedByUser()) {
            $this->markUserBlocked($userId);
        }
        $this->markFailed($notificationId, 'sendMessage failed');

        return false;
    }

    /** @return array{telegram_id: int, telegram_bot_blocked: bool}|null */
    private function fetchUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT telegram_id, telegram_bot_blocked FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        return $row !== false
            ? ['telegram_id' => (int) $row['telegram_id'], 'telegram_bot_blocked' => (bool) $row['telegram_bot_blocked']]
            : null;
    }

    /** @return array{event_type_code: string, payload_json: ?string, issuer_name: string}|null */
    private function fetchEvent(int $eventId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT e.event_type_code, e.payload_json, i.short_name AS issuer_name
             FROM events e
             JOIN issuers i ON i.id = e.issuer_id
             WHERE e.id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch();

        return $row !== false
            ? ['event_type_code' => (string) $row['event_type_code'], 'payload_json' => $row['payload_json'], 'issuer_name' => (string) $row['issuer_name']]
            : null;
    }

    /** @return array<string, mixed> */
    private function decodePayload(?string $json): array
    {
        if ($json === null) {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Шаблоны — дословно из docs/STAGE4_EVENT_ENGINE.md, раздел
     * "Рассылка уведомлений". C5/E1 — единственные типы, которые сейчас
     * реально создаёт EventPublisher (Фаза 1); прочие event_types
     * (A1-D2 и т.д.) для этого MVP не заполняются вообще, но на всякий
     * случай — общая заглушка в default, а не падение.
     *
     * @param array<string, mixed> $payload
     */
    private function buildMessageText(string $eventTypeCode, array $payload, string $issuerName): string
    {
        return match ($eventTypeCode) {
            'C5' => $this->buildRatingActionText($payload, $issuerName),
            'E1' => $this->buildFnsBlockText($payload, $issuerName),
            default => "Новое событие по эмитенту «{$issuerName}».",
        };
    }

    /** @param array<string, mixed> $payload */
    private function buildRatingActionText(array $payload, string $issuerName): string
    {
        $agency = BotFormatting::agencyDisplayName((string) ($payload['agency'] ?? ''));
        $ratingFrom = $payload['rating_from'] ?? null;
        $ratingTo = (string) ($payload['rating_to'] ?? '');
        $outlookTo = $payload['outlook_to'] ?? null;
        $sourceUrl = $payload['source_url'] ?? null;

        // Не подавать "было X → стало X" при подтверждении без изменений
        // (см. EventPublisher::buildRatingStatusText() — та же логика,
        // тут отдельно, потому что шаблон уведомления и status_text
        // события — разные тексты с разным назначением).
        $change = ($ratingFrom !== null && $ratingFrom !== $ratingTo)
            ? "{$ratingFrom} → {$ratingTo}"
            : "рейтинг подтверждён на уровне {$ratingTo}";

        $text = "🔔 {$agency}: {$issuerName} — {$change}";
        if ($outlookTo !== null) {
            $text .= ", прогноз: {$outlookTo}";
        }
        if ($sourceUrl !== null) {
            $text .= ".\n{$sourceUrl}";
        }

        return $text;
    }

    /** @param array<string, mixed> $payload */
    private function buildFnsBlockText(array $payload, string $issuerName): string
    {
        $kind = (string) ($payload['kind'] ?? '');

        return match ($kind) {
            'started' => sprintf(
                '⚠️ %s: ФНС заблокировала счета (%d банк(ов)), сумма ~%s ₽. Дата решения ФНС: %s.%s',
                $issuerName,
                (int) ($payload['active_bank_count'] ?? 0),
                $payload['new_blocked_amount'] ?? 'не указана',
                BotFormatting::formatDate($payload['block_date'] ?? null),
                isset($payload['reason']) ? ' ' . $payload['reason'] : ''
            ),
            'amount_changed' => sprintf(
                '⚠️ %s: сумма блокировки ФНС изменилась — было %s ₽, стало %s ₽ (банков: %d).',
                $issuerName,
                $payload['old_blocked_amount'] ?? '—',
                $payload['new_blocked_amount'] ?? '—',
                (int) ($payload['active_bank_count'] ?? 0)
            ),
            'lifted' => sprintf(
                '✅ %s: блокировка счетов ФНС снята (по состоянию на нашу проверку от %s).',
                $issuerName,
                BotFormatting::formatDate($payload['block_date'] ?? null)
            ),
            default => "Изменение статуса блокировки ФНС по эмитенту «{$issuerName}».",
        };
    }

    private function markSent(int $notificationId): void
    {
        $this->db->prepare("UPDATE notifications SET status = 'sent', sent_at = NOW() WHERE id = :id")
            ->execute(['id' => $notificationId]);
    }

    private function markFailed(int $notificationId, string $reason): void
    {
        $this->db->prepare("UPDATE notifications SET status = 'failed', failure_reason = :reason WHERE id = :id")
            ->execute(['id' => $notificationId, 'reason' => mb_substr($reason, 0, 255)]);
    }

    private function markUserBlocked(int $userId): void
    {
        $this->db->prepare('UPDATE users SET telegram_bot_blocked = 1 WHERE id = :id')
            ->execute(['id' => $userId]);
    }

    /** MySQL/SQLite — тот же приём, что везде в проекте (см. BotCommandHandler::isDuplicateKeyViolation()). */
    private function isDuplicateKeyViolation(PDOException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Duplicate entry') || str_contains($message, 'UNIQUE constraint failed');
    }
}
