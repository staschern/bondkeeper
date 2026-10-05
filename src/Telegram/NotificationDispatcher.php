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
 *
 * === События по конкретной бумаге (Этап 5, выплаты, 04.10.2026) ===
 *
 * У событий о выплатах заполнен events.security_id. Строка watchlist без
 * security_id (отслеживается эмитент целиком — так бот добавляет сейчас)
 * получает все события эмитента, как и раньше; строка с security_id —
 * только события этой бумаги и события уровня эмитента (рейтинг, ФНС).
 * Это основа будущей настройки «уведомлять только по выбранным выпускам».
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
                 AND (w.security_id IS NULL OR e.security_id IS NULL OR w.security_id = e.security_id)
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
     * C5/E1 — рейтинговые действия и блокировки ФНС (Этап 4); R1/A2/A4/A6/
     * B1/B2/B4/B5/B2a — напоминания и события о выплатах (Этап 5, см.
     * docs/STAGE5_PAYMENTS.md; A3/A5/A7 "передано депонентам" клиенту не
     * рассылаются — notify_client=FALSE у event_types, сюда не доходят).
     * Прочие event_types (C1-D2 и т.д.) пока не заполняются вообще, но на
     * всякий случай — общая заглушка в default, а не падение.
     *
     * Каждый шаблон начинается с жирного заголовка темы ("🔔 Новости
     * рейтингов:"/"⚠️ Блокировки ФНC:"/"⏰/✅/🔴/🟡 Выплаты:") на отдельной
     * строке — по просьбе пользователя (4 октября 2026): видов уведомлений
     * будет больше, тема должна быть понятна с первого взгляда, не
     * вчитываясь в текст. parse_mode=HTML (см. dispatchOne()) — весь
     * внешний текст (заголовок новости, название эмитента/бумаги,
     * основание блокировки) экранируется BotFormatting::escapeHtml(),
     * сама разметка `<b>...</b>` — нет.
     *
     * @param array<string, mixed> $payload
     */
    private function buildMessageText(string $eventTypeCode, array $payload, string $issuerName, string $issuerInn, string $statusText): string
    {
        return match ($eventTypeCode) {
            'C5' => $this->buildRatingActionText($payload, $statusText),
            'E1' => $this->buildFnsBlockText($payload, $issuerName, $issuerInn),
            'R1' => $this->buildPaymentReminderText($payload, $issuerName),
            'A2', 'A4', 'A6', 'B1', 'B2', 'B4', 'B5' => $this->buildPaymentText($eventTypeCode, $payload, $issuerName),
            'B2a' => $this->buildNoReceiptText($payload, $issuerName),
            default => '<b>🔔 Новое событие:</b>' . "\n" . BotFormatting::escapeHtml("Эмитент «{$issuerName}»."),
        };
    }

    /**
     * R1 — напоминание накануне выплаты (Этап 5, docs/STAGE5_PAYMENTS.md).
     * Сутки считаются от даты по графику; если она выпала на выходной,
     * отдельно называется день, когда деньги должны поступить на деле.
     * Заголовок темы жирным, как у остальных видов уведомлений (см.
     * buildMessageText()); "Выплаты" — одна общая тема на весь Этап 5
     * (R1/A2-A6/B1-B5/B2a), смайл перед темой меняется по характеру
     * события (⏰ напоминание, ✅ получено, 🔴 просрочка, 🟡 предупреждение).
     *
     * @param array<string, mixed> $payload
     */
    private function buildPaymentReminderText(array $payload, string $issuerName): string
    {
        $parts = [];
        foreach ((array) ($payload['payments'] ?? []) as $payment) {
            $amount = $payment['amount_planned'] ?? null;
            $parts[] = self::paymentKindName((string) ($payment['kind'] ?? ''))
                . ' — ' . ($amount !== null ? BotFormatting::formatMoney((string) $amount) . ' на бумагу' : 'сумма пока не определена');
        }
        $paymentDate = (string) ($payload['payment_date'] ?? '');
        $effectiveDate = (string) ($payload['effective_date'] ?? $paymentDate);

        $body = 'Завтра, ' . BotFormatting::formatDate($paymentDate) . ', выплата: ' . BotFormatting::escapeHtml(implode('; ', $parts)) . '.';
        if ($effectiveDate !== $paymentDate) {
            $body .= ' Дата выпадает на выходной — деньги должны поступить ' . BotFormatting::formatDate($effectiveDate) . '.';
        }

        return '<b>⏰ Выплаты:</b>' . "\n" . self::bondHeader($payload, $issuerName) . "\n" . $body;
    }

    /**
     * Выплата по сообщению НРД: получена (A2/A4/A6), частично (B1), не в
     * срок (B2), доплата (B4), исполнена после просрочки (B5).
     *
     * @param array<string, mixed> $payload
     */
    private function buildPaymentText(string $eventTypeCode, array $payload, string $issuerName): string
    {
        $kind = (string) ($payload['kind'] ?? '');
        $ending = match ($kind) {
            'amortization' => 'а',
            'redemption' => 'о',
            default => '',
        };
        $what = self::mbUcfirst(self::paymentKindName($kind)) . ($kind === 'coupon' ? ' за ' : ' ')
            . BotFormatting::formatDate((string) ($payload['payment_date'] ?? ''));
        $planned = $payload['amount_planned'] ?? null;
        $actual = $payload['amount_actual'] ?? null;
        $ofPlanned = $planned !== null ? ' из ' . BotFormatting::formatMoney((string) $planned) : '';
        $deadline = '';
        if (($payload['full_default_date'] ?? null) !== null) {
            $deadline = ' Полный дефолт наступит, если долг не будет закрыт до ' . BotFormatting::formatDate((string) $payload['full_default_date']);
            if (($payload['working_days_to_default'] ?? null) !== null) {
                $deadline .= ' (осталось рабочих дней: ' . (int) $payload['working_days_to_default'] . ')';
            }
            $deadline .= '.';
        }

        [$emoji, $body] = match ($eventTypeCode) {
            'B1' => ['🔴', "{$what} выплачен{$ending} частично: получено "
                . ($actual !== null ? BotFormatting::formatMoney((string) $actual) : 'меньше положенного') . $ofPlanned . ' на бумагу.' . $deadline],
            'B2' => ['🔴', "{$what} не выплачен{$ending} в срок." . $deadline],
            'B4' => ['🟡', "{$what}: доплата"
                . (($payload['tranche_amount'] ?? null) !== null ? ' ' . BotFormatting::formatMoney((string) $payload['tranche_amount']) : '')
                . ($actual !== null ? ', всего получено ' . BotFormatting::formatMoney((string) $actual) . $ofPlanned . ' на бумагу' : '') . '.' . $deadline],
            'B5' => ['✅', "{$what} выплачен{$ending} полностью после просрочки"
                . ($actual !== null ? ': ' . BotFormatting::formatMoney((string) $actual) . ' на бумагу' : '') . '.'],
            default => ['✅', "{$what} получен{$ending} НРД"
                . ($actual !== null ? ': ' . BotFormatting::formatMoney((string) $actual) . ' на бумагу' : '') . '.'],
        };

        return "<b>{$emoji} Выплаты:</b>" . "\n" . self::bondHeader($payload, $issuerName) . "\n" . $body;
    }

    /**
     * B2a — к вечеру дня выплаты (и повторно утром) сообщения «получено
     * НРД» нет. Это ещё не невыплата: НРД вправе сообщить и на следующий
     * рабочий день — поэтому текст прямо это оговаривает.
     *
     * @param array<string, mixed> $payload
     */
    private function buildNoReceiptText(array $payload, string $issuerName): string
    {
        $kinds = implode(', ', array_map(
            static fn (array $payment): string => self::paymentKindName((string) ($payment['kind'] ?? '')),
            (array) ($payload['payments'] ?? []),
        ));
        $morning = ($payload['check'] ?? '') === 'morning';
        $still = $morning ? 'до сих пор не поступили' : 'пока не поступили';
        $caveat = $morning
            ? 'Это ещё не подтверждённая невыплата: сегодня НРД должен сообщить, исполнена выплата, исполнена частично или не исполнена.'
            : 'Это ещё не означает невыплату: сообщение о получении может выйти на следующий рабочий день.';

        $body = "Деньги от эмитента по выплате ({$kinds}) за " . BotFormatting::formatDate((string) ($payload['payment_date'] ?? ''))
            . " {$still} в НРД. {$caveat}";

        return '<b>🟡 Выплаты:</b>' . "\n" . self::bondHeader($payload, $issuerName) . "\n" . $body;
    }

    /** Название бумаги и ИНН/ISIN внешние (из нашей же БД, но исходно — с сайта Мосбиржи) — экранируются. @param array<string, mixed> $payload */
    private static function bondHeader(array $payload, string $issuerName): string
    {
        $name = BotFormatting::escapeHtml($issuerName);
        $security = BotFormatting::escapeHtml((string) ($payload['security_name'] ?? 'облигация'));
        $isin = BotFormatting::escapeHtml((string) ($payload['isin'] ?? '—'));

        return "{$name} · {$security} ({$isin})";
    }

    private static function paymentKindName(string $kind): string
    {
        return match ($kind) {
            'coupon' => 'купон',
            'amortization' => 'амортизация',
            'redemption' => 'погашение',
            default => 'выплата',
        };
    }

    private static function mbUcfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
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
