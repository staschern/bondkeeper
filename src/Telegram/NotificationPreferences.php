<?php

declare(strict_types=1);

namespace BondKeeper\Telegram;

use PDO;
use PDOException;

/**
 * Настройки уведомлений пользователя (ТЗ от 12.10.2026,
 * docs/BOT_UX_SPEC.md, раздел 10; миграция 032). Одно место, где читаются
 * и пишутся настройки: ими пользуются бот (раздел «Настройки ⚙️»),
 * рассыльщик (кому что отправлять) и скрипт напоминаний (какие интервалы
 * вообще нужны).
 *
 * Что настраивается:
 *   - напоминания можно отключить целиком или по отдельным эмитентам;
 *   - для купонов, амортизаций, погашений и оферт — свой интервал: за
 *     сколько календарных дней до даты напоминать. По умолчанию — за 1
 *     день о выплатах и за 30 дней об офертах;
 *   - с отдельных выпусков эмитента можно снять отметку — тогда по ним не
 *     приходит ничего: ни напоминания, ни уведомления о выплатах.
 *
 * «Напоминания» — это события R1 (выплата) и R2 (оферта). Остальные
 * уведомления (деньги получены, просрочка, рейтинг, блокировка счетов)
 * отключением напоминаний не затрагиваются.
 *
 * Запросы переносимые (MySQL и SQLite) — офлайн-тест:
 * tests/test_reminder_settings.php.
 */
final class NotificationPreferences
{
    public const KIND_COUPON = 'coupon';
    public const KIND_AMORTIZATION = 'amortization';
    public const KIND_REDEMPTION = 'redemption';
    public const KIND_OFFER = 'offer';

    /** За сколько дней напоминаем, если пользователь не задал своё. */
    public const DEFAULT_DAYS = [
        self::KIND_COUPON => 1,
        self::KIND_AMORTIZATION => 1,
        self::KIND_REDEMPTION => 1,
        self::KIND_OFFER => 30,
    ];
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 365;

    /** Причина, с которой рассыльщик помечает неотправленное уведомление. */
    public const SKIP_REMINDERS_OFF = 'не отправлено: напоминания отключены пользователем';
    public const SKIP_ISSUER_MUTED = 'не отправлено: напоминания по эмитенту отключены пользователем';
    public const SKIP_SECURITY_EXCLUDED = 'не отправлено: выпуск исключён пользователем';
    public const SKIP_OTHER_INTERVAL = 'не отправлено: у пользователя другой интервал напоминаний';

    private const COLUMNS = [
        self::KIND_COUPON => 'coupon_days',
        self::KIND_AMORTIZATION => 'amortization_days',
        self::KIND_REDEMPTION => 'redemption_days',
        self::KIND_OFFER => 'offer_days',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Готова ли база к настройкам (применена ли миграция 032). null —
     * готова, иначе текст для разработчика.
     */
    public static function schemaProblem(PDO $db): ?string
    {
        try {
            $db->query('SELECT reminder_days FROM events LIMIT 1');
            $db->query('SELECT 1 FROM user_notification_settings LIMIT 1');
            $db->query('SELECT 1 FROM user_reminder_mutes LIMIT 1');
            $db->query('SELECT 1 FROM user_excluded_securities LIMIT 1');
        } catch (PDOException $e) {
            return 'В базе нет таблиц настроек уведомлений или колонки events.reminder_days — не применена миграция database/032_notification_settings.sql.';
        }

        return null;
    }

    /**
     * @return array{reminders_enabled: bool, days: array<string, int>, custom: array<string, ?int>}
     *         days — действующий интервал по каждому виду; custom — то, что задал сам пользователь (null — не задавал)
     */
    public function get(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT reminders_enabled, coupon_days, amortization_days, redemption_days, offer_days FROM user_notification_settings WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $custom = [];
        $days = [];
        foreach (self::COLUMNS as $kind => $column) {
            $custom[$kind] = $row !== false && $row[$column] !== null ? (int) $row[$column] : null;
            $days[$kind] = $custom[$kind] ?? self::DEFAULT_DAYS[$kind];
        }

        return ['reminders_enabled' => $row === false || (bool) $row['reminders_enabled'], 'days' => $days, 'custom' => $custom];
    }

    public function setRemindersEnabled(int $userId, bool $enabled): void
    {
        $this->ensureRow($userId);
        $this->db->prepare('UPDATE user_notification_settings SET reminders_enabled = :value WHERE user_id = :id')
            ->execute(['value' => $enabled ? 1 : 0, 'id' => $userId]);
    }

    /** Свой интервал напоминаний для вида выплаты; null — вернуть значение по умолчанию. */
    public function setDays(int $userId, string $kind, ?int $days): void
    {
        $column = self::COLUMNS[$kind] ?? throw new \InvalidArgumentException("Неизвестный вид напоминания: {$kind}");
        if ($days !== null && ($days < self::MIN_DAYS || $days > self::MAX_DAYS)) {
            throw new \InvalidArgumentException('Интервал напоминания — от ' . self::MIN_DAYS . ' до ' . self::MAX_DAYS . ' дней.');
        }
        $this->ensureRow($userId);
        $this->db->prepare("UPDATE user_notification_settings SET {$column} = :days WHERE user_id = :id")->execute(['days' => $days, 'id' => $userId]);
    }

    /** @return array<int, int> эмитенты, по которым пользователь отключил напоминания */
    public function mutedIssuerIds(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT issuer_id FROM user_reminder_mutes WHERE user_id = :id ORDER BY issuer_id');
        $stmt->execute(['id' => $userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function setIssuerMuted(int $userId, int $issuerId, bool $muted): void
    {
        $this->db->prepare('DELETE FROM user_reminder_mutes WHERE user_id = :user_id AND issuer_id = :issuer_id')
            ->execute(['user_id' => $userId, 'issuer_id' => $issuerId]);
        if ($muted) {
            $this->db->prepare('INSERT INTO user_reminder_mutes (user_id, issuer_id) VALUES (:user_id, :issuer_id)')
                ->execute(['user_id' => $userId, 'issuer_id' => $issuerId]);
        }
    }

    /** «Включить для всего списка»: общий включатель и все отключения по эмитентам. */
    public function enableAllReminders(int $userId): void
    {
        $this->setRemindersEnabled($userId, true);
        $this->db->prepare('DELETE FROM user_reminder_mutes WHERE user_id = :id')->execute(['id' => $userId]);
    }

    /** Есть ли у пользователя хоть что-то отключённое — от этого зависит ответ на «Включить напоминания ✅». */
    public function hasAnythingDisabled(int $userId): bool
    {
        return !$this->get($userId)['reminders_enabled'] || $this->mutedIssuerIds($userId) !== [];
    }

    /**
     * Включить напоминания по одному эмитенту. Если они были отключены для
     * всего списка — общий включатель возвращается, а остальные эмитенты
     * из списка пользователя остаются отключёнными поимённо: пользователь
     * включал только этого.
     *
     * @param array<int, int> $watchedIssuerIds эмитенты из списка пользователя
     */
    public function enableIssuer(int $userId, int $issuerId, array $watchedIssuerIds): void
    {
        if (!$this->get($userId)['reminders_enabled']) {
            $this->setRemindersEnabled($userId, true);
            foreach ($watchedIssuerIds as $other) {
                $this->setIssuerMuted($userId, $other, $other !== $issuerId);
            }

            return;
        }
        $this->setIssuerMuted($userId, $issuerId, false);
    }

    /** @return array<int, int> выпуски, с которых пользователь снял отметку (все или одного эмитента) */
    public function excludedSecurityIds(int $userId, ?int $issuerId = null): array
    {
        $sql = 'SELECT x.security_id FROM user_excluded_securities x';
        $params = ['user_id' => $userId];
        if ($issuerId !== null) {
            $sql .= ' JOIN securities s ON s.id = x.security_id AND s.issuer_id = :issuer_id';
            $params['issuer_id'] = $issuerId;
        }
        $stmt = $this->db->prepare($sql . ' WHERE x.user_id = :user_id ORDER BY x.security_id');
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function setSecurityExcluded(int $userId, int $securityId, bool $excluded): void
    {
        $this->db->prepare('DELETE FROM user_excluded_securities WHERE user_id = :user_id AND security_id = :security_id')
            ->execute(['user_id' => $userId, 'security_id' => $securityId]);
        if ($excluded) {
            $this->db->prepare('INSERT INTO user_excluded_securities (user_id, security_id) VALUES (:user_id, :security_id)')
                ->execute(['user_id' => $userId, 'security_id' => $securityId]);
        }
    }

    /**
     * Отметить или снять отметку со всех выпусков эмитента разом.
     *
     * @param array<int, int> $securityIds выпуски эмитента, показанные пользователю
     */
    public function setAllSecuritiesExcluded(int $userId, array $securityIds, bool $excluded): void
    {
        foreach ($securityIds as $securityId) {
            $this->setSecurityExcluded($userId, (int) $securityId, $excluded);
        }
    }

    /** Эмитент убран из списка отслеживания — его настройки больше не нужны: при повторном добавлении всё начнётся с чистого листа. */
    public function forgetIssuer(int $userId, int $issuerId): void
    {
        $this->setIssuerMuted($userId, $issuerId, false);
        $this->db->prepare(
            'DELETE FROM user_excluded_securities WHERE user_id = :user_id AND security_id IN (SELECT id FROM securities WHERE issuer_id = :issuer_id)'
        )->execute(['user_id' => $userId, 'issuer_id' => $issuerId]);
    }

    /**
     * Интервалы, которые пользователи задали сами: вид → число дней →
     * эмитенты, за которыми следят такие пользователи. По ним скрипт
     * напоминаний понимает, какие напоминания кроме обычных нужно создать и
     * по каким эмитентам. Пользователи с отключёнными напоминаниями не
     * учитываются.
     *
     * @return array<string, array<int, array<int, int>>>
     */
    public function customIntervals(): array
    {
        $result = [];
        foreach (self::COLUMNS as $kind => $column) {
            $rows = $this->db->query(
                "SELECT DISTINCT s.{$column} AS days, w.issuer_id
                 FROM user_notification_settings s
                 JOIN watchlist w ON w.user_id = s.user_id
                 WHERE s.reminders_enabled = 1 AND s.{$column} IS NOT NULL AND s.{$column} <> " . self::DEFAULT_DAYS[$kind]
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $result[$kind][(int) $row['days']][] = (int) $row['issuer_id'];
            }
        }

        return $result;
    }

    /**
     * Нужно ли отправлять это событие этому пользователю. null — да;
     * иначе причина, с которой уведомление помечается неотправленным.
     * Отметка нужна обязательно: без неё событие осталось бы «ещё не
     * отправленным» и ушло бы пользователю задним числом, как только он
     * вернёт настройку.
     *
     * @param array{event_type_code: string, issuer_id: int, security_id: ?int, reminder_days: ?int, payload: array<string, mixed>} $event
     */
    public function skipReason(int $userId, array $event): ?string
    {
        if ($event['security_id'] !== null && in_array($event['security_id'], $this->excludedSecurityIds($userId), true)) {
            return self::SKIP_SECURITY_EXCLUDED;
        }
        if (!in_array($event['event_type_code'], ['R1', 'R2'], true)) {
            return null;
        }

        $settings = $this->get($userId);
        if (!$settings['reminders_enabled']) {
            return self::SKIP_REMINDERS_OFF;
        }
        if (in_array($event['issuer_id'], $this->mutedIssuerIds($userId), true)) {
            return self::SKIP_ISSUER_MUTED;
        }

        return $this->reminderPayload($userId, $event) === null ? self::SKIP_OTHER_INTERVAL : null;
    }

    /**
     * Данные напоминания в том виде, в каком его должен увидеть этот
     * пользователь: из выплат остаются только те виды, для которых его
     * интервал совпадает с интервалом события (у одного — купоны за 3 дня,
     * а амортизация за день; событие «за 3 дня» покажет ему только
     * купон). null — в этом событии для пользователя нет ничего.
     *
     * @param array{event_type_code: string, reminder_days: ?int, payload: array<string, mixed>} $event
     * @return array<string, mixed>|null
     */
    public function reminderPayload(int $userId, array $event): ?array
    {
        $days = $this->get($userId)['days'];
        $payload = $event['payload'];

        if ($event['event_type_code'] === 'R2') {
            return ($event['reminder_days'] ?? self::DEFAULT_DAYS[self::KIND_OFFER]) === $days[self::KIND_OFFER] ? $payload : null;
        }

        // У напоминаний, созданных до появления настроек, интервала нет — они создавались за день.
        $eventDays = $event['reminder_days'] ?? self::DEFAULT_DAYS[self::KIND_COUPON];
        $payments = array_values(array_filter(
            (array) ($payload['payments'] ?? []),
            static fn (array $payment): bool => ($days[(string) ($payment['kind'] ?? '')] ?? null) === $eventDays,
        ));
        if ($payments === []) {
            return null;
        }
        $payload['payments'] = $payments;

        return $payload;
    }

    /**
     * Пользователь вернул настройку (включил напоминания, вернул отметку
     * выпуску, сменил интервал): напоминания о ещё не наступивших датах,
     * которые из-за прежней настройки были помечены неотправленными,
     * снова становятся «ещё не отправленными» — рассыльщик оценит их по
     * новым настройкам. Иначе, например, напоминание об оферте, созданное
     * за 30 дней, пока напоминания были выключены, не пришло бы уже
     * никогда. Прошедшие даты и остальные уведомления не трогаются.
     *
     * @return int сколько напоминаний возвращено на оценку
     */
    public function reviveSuppressedReminders(int $userId, string $today): int
    {
        $reasons = [self::SKIP_REMINDERS_OFF, self::SKIP_ISSUER_MUTED, self::SKIP_SECURITY_EXCLUDED, self::SKIP_OTHER_INTERVAL];
        $marks = implode(',', array_fill(0, count($reasons), '?'));
        $stmt = $this->db->prepare(
            "SELECT n.id FROM notifications n
             JOIN events e ON e.id = n.event_id
             WHERE n.user_id = ? AND n.status = 'failed' AND n.failure_reason IN ({$marks})
               AND e.event_type_code IN ('R1', 'R2') AND e.event_date > ?"
        );
        $stmt->execute(array_merge([$userId], $reasons, [$today]));
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $delete = $this->db->prepare('DELETE FROM notifications WHERE id = :id');
        foreach ($ids as $id) {
            $delete->execute(['id' => $id]);
        }

        return count($ids);
    }

    private function ensureRow(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT 1 FROM user_notification_settings WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        if ($stmt->fetchColumn() === false) {
            $this->db->prepare('INSERT INTO user_notification_settings (user_id) VALUES (:id)')->execute(['id' => $userId]);
        }
    }
}
