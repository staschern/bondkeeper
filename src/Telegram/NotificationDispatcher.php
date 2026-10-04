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
 *
 * === Фильтр "не заваливать историей" (баг, найден 17 сентября 2026) ===
 *
 * fetchPendingPairs() дополнительно требует `w.added_at <= e.detected_at`:
 * без этого условия пользователь, ДОБАВИВШИЙ эмитента в список, тут же
 * получал ВСЕ его исторические события (любой E1/C5, когда-либо созданный
 * для этого эмитента другими прогонами задолго до того, как пользователь
 * вообще начал его отслеживать) — потому что для новой пары (user_id,
 * event_id) строки в `notifications` ещё нет ни для одного старого
 * события, весь бэклог считался "ещё не отправленным". Живой пример —
 * при добавлении ООО «КОНТРОЛ лизинг» пользователь получил 4 сообщения
 * разом (2 старых "заблокировано" с разными суммами + 2 старых "снято" с
 * разными датами проверки — накопленные за несколько дней ежедневного
 * крона ДО того, как этот пользователь начал отслеживание). С фильтром —
 * пользователь видит только события, случившиеся ПОСЛЕ того, как он сам
 * начал следить за эмитентом; за "здесь и сейчас" статус на момент
 * добавления отвечает отдельный, не через events/notifications, канал —
 * см. BotCommandHandler::checkFnsOnAdd().
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
             JOIN watchlist w ON w.issuer_id = e.issuer_id AND w.added_at <= e.detected_at
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

        $text = $this->buildMessageText(
            (string) $event['event_type_code'],
            $this->decodePayload($event['payload_json']),
            (string) $event['issuer_name'],
            (string) $event['issuer_inn'],
            (string) $event['status_text'],
        );

        // parse_mode=HTML — заголовки "🔔 Новости рейтингов:"/"⚠️ Блокировки
        // ФНC:" жирным; disable_web_page_preview — ссылка на пресс-релиз в
        // тексте C5 иначе разворачивается превью-карточкой на пол-экрана
        // (по просьбе пользователя, 4 октября 2026).
        if ($this->telegram->sendMessage($user['telegram_id'], $text, null, 'HTML', true)) {
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

    /** @return array{event_type_code: string, payload_json: ?string, issuer_name: string, issuer_inn: string, status_text: string}|null */
    private function fetchEvent(int $eventId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT e.event_type_code, e.payload_json, e.status_text, i.short_name AS issuer_name, i.inn AS issuer_inn
             FROM events e
             JOIN issuers i ON i.id = e.issuer_id
             WHERE e.id = :id'
        );
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch();

        return $row !== false
            ? [
                'event_type_code' => (string) $row['event_type_code'],
                'payload_json' => $row['payload_json'],
                'issuer_name' => (string) $row['issuer_name'],
                'issuer_inn' => (string) ($row['issuer_inn'] ?? ''),
                'status_text' => (string) ($row['status_text'] ?? ''),
            ]
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
     * C5/E1 — единственные типы, которые сейчас реально создаёт
     * EventPublisher (Фаза 1); прочие event_types (A1-D2 и т.д.) для
     * этого MVP не заполняются вообще, но на всякий случай — общая
     * заглушка в default, а не падение.
     *
     * Каждый шаблон начинается с жирного заголовка темы ("🔔 Новости
     * рейтингов:"/"⚠️ Блокировки ФНC:") на отдельной строке — по просьбе
     * пользователя (4 октября 2026): видов уведомлений будет больше, тема
     * должна быть понятна с первого взгляда, не вчитываясь в текст.
     * parse_mode=HTML (см. dispatchOne()) — весь внешний текст (заголовок
     * новости, название эмитента, основание блокировки) экранируется
     * BotFormatting::escapeHtml(), сама разметка `<b>...</b>` — нет.
     *
     * @param array<string, mixed> $payload
     */
    private function buildMessageText(string $eventTypeCode, array $payload, string $issuerName, string $issuerInn, string $statusText): string
    {
        return match ($eventTypeCode) {
            'C5' => $this->buildRatingActionText($payload, $statusText),
            'E1' => $this->buildFnsBlockText($payload, $issuerName, $issuerInn),
            default => '<b>🔔 Новое событие:</b>' . "\n" . BotFormatting::escapeHtml("Эмитент «{$issuerName}»."),
        };
    }

    /**
     * Заголовок новости дословно (events.status_text — уже готовый текст:
     * либо сам заголовок пресс-релиза, если он был у источника, либо
     * собранное из рейтинга/прогноза описание, см.
     * EventPublisher::publishRatingAction()/buildRatingStatusText()) — по
     * прямому запросу пользователя (4 октября 2026): раньше уведомление
     * пересобирало "агентство: эмитент — было → стало", теперь просто
     * показывает тот же заголовок, что и в карточке-превью ссылки.
     *
     * @param array<string, mixed> $payload
     */
    private function buildRatingActionText(array $payload, string $statusText): string
    {
        $sourceUrl = $payload['source_url'] ?? null;

        $text = '<b>🔔 Новости рейтингов:</b>' . "\n" . BotFormatting::escapeHtml($statusText);
        if ($sourceUrl !== null) {
            $text .= "\n" . BotFormatting::escapeHtml((string) $sourceUrl);
        }

        return $text;
    }

    /**
     * Форматы — дословно по правкам пользователя (17 сентября 2026,
     * обновлено 4 октября 2026): ИНН теперь и в lifted тоже (раньше
     * намеренно без него — пользователь явно попросил добавить). Заголовок
     * темы ("⚠️ Блокировки ФНC:"/"✅ Блокировки ФНC:" — смайл по kind)
     * жирным на отдельной строке (см. buildMessageText()), дальше — тот же
     * текст, что и раньше, но БЕЗ собственного смайла в начале (он ушёл в
     * заголовок темы). Суммы — через BotFormatting::formatMoney()
     * (разделитель разрядов + "₽", тот же формат, что и в разделе
     * "Статус"). 'count_changed' — см. EventPublisher::publishFnsBlockChange().
     *
     * @param array<string, mixed> $payload
     */
    private function buildFnsBlockText(array $payload, string $issuerName, string $issuerInn): string
    {
        $kind = (string) ($payload['kind'] ?? '');
        $emoji = $kind === 'lifted' ? '✅' : '⚠️';
        $header = "<b>{$emoji} Блокировки ФНC:</b>\n";
        $name = BotFormatting::escapeHtml($issuerName);
        $inn = BotFormatting::escapeHtml($issuerInn);

        return $header . match ($kind) {
            'started' => sprintf(
                '%s | ИНН %s: Блокировка счетов ФНС | Дата блокировки: %s | Количество заблокированных счетов: %d | Заблокированная сумма: %s | %s',
                $name,
                $inn,
                BotFormatting::formatDate($payload['block_date'] ?? null),
                (int) ($payload['active_bank_count'] ?? 0),
                BotFormatting::formatMoney($payload['new_blocked_amount'] ?? null),
                BotFormatting::escapeHtml((string) ($payload['reason'] ?? 'основание не указано'))
            ),
            'amount_changed' => sprintf(
                '%s | ИНН %s: сумма блокировки ФНС изменилась — было %s, стало %s (заблокированных счетов: %d).',
                $name,
                $inn,
                BotFormatting::formatMoney($payload['old_blocked_amount'] ?? null),
                BotFormatting::formatMoney($payload['new_blocked_amount'] ?? null),
                (int) ($payload['active_bank_count'] ?? 0)
            ),
            'count_changed' => sprintf(
                '%s | ИНН %s: количество заблокированных счетов ФНС изменилось — было %d, стало %d.',
                $name,
                $inn,
                (int) ($payload['old_active_bank_count'] ?? 0),
                (int) ($payload['active_bank_count'] ?? 0)
            ),
            'lifted' => sprintf(
                '%s | ИНН %s: блокировка счетов ФНС снята (по состоянию на %s).',
                $name,
                $inn,
                BotFormatting::formatDate($payload['block_date'] ?? null)
            ),
            default => "Изменение статуса блокировки ФНС по эмитенту «{$name}».",
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
