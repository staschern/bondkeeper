<?php

declare(strict_types=1);

namespace BondKeeper\Telegram;

use PDO;

/**
 * Сообщения бота, которые нужно отправить позже (таблица
 * bot_delayed_messages, миграция 034). Первое применение — предложение
 * ввести промокод через 10 секунд после «Знакомства» (13.10.2026).
 *
 * Засыпать в обработчике сообщения нельзя: бот — один процесс, и пауза
 * остановила бы его для всех пользователей. Поэтому сообщение записывается
 * в очередь со временем отправки, а цикл бота (bin/daemon_telegram_bot.php)
 * на каждом обороте отправляет то, чему пришло время, и не ждёт новых
 * сообщений от Telegram дольше, чем осталось до ближайшей отправки.
 *
 * Время — по часам PHP и при записи, и при сравнении: с часами MySQL оно
 * может расходиться на часовой пояс.
 */
final class DelayedMessages
{
    public const KIND_PROMO_PROMPT = 'promo_prompt';

    public function __construct(private readonly PDO $db)
    {
    }

    /** Поставить сообщение в очередь. Неотправленное сообщение того же вида у пользователя не дублируется. */
    public function schedule(int $userId, string $kind, int $delaySeconds): void
    {
        $exists = $this->db->prepare('SELECT 1 FROM bot_delayed_messages WHERE user_id = :user_id AND kind = :kind AND sent_at IS NULL LIMIT 1');
        $exists->execute(['user_id' => $userId, 'kind' => $kind]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $this->db->prepare('INSERT INTO bot_delayed_messages (user_id, kind, due_at) VALUES (:user_id, :kind, :due_at)')
            ->execute(['user_id' => $userId, 'kind' => $kind, 'due_at' => date('Y-m-d H:i:s', time() + max(0, $delaySeconds))]);
    }

    /** Снять неотправленные сообщения этого вида (например, промокод уже введён — предлагать нечего). */
    public function cancel(int $userId, string $kind): void
    {
        $this->db->prepare('UPDATE bot_delayed_messages SET sent_at = :now WHERE user_id = :user_id AND kind = :kind AND sent_at IS NULL')
            ->execute(['now' => date('Y-m-d H:i:s'), 'user_id' => $userId, 'kind' => $kind]);
    }

    /**
     * Сообщения, которым пришло время.
     *
     * @return array<int, array{id: int, user_id: int, kind: string}>
     */
    public function due(): array
    {
        $stmt = $this->db->prepare('SELECT id, user_id, kind FROM bot_delayed_messages WHERE sent_at IS NULL AND due_at <= :now ORDER BY due_at, id');
        $stmt->execute(['now' => date('Y-m-d H:i:s')]);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'kind' => (string) $row['kind']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function markSent(int $id): void
    {
        $this->db->prepare('UPDATE bot_delayed_messages SET sent_at = :now WHERE id = :id')->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);
    }

    /** Сколько секунд до ближайшего неотправленного сообщения: 0 — пора уже сейчас, null — очередь пуста. */
    public function secondsUntilNext(): ?int
    {
        $next = $this->db->query('SELECT MIN(due_at) FROM bot_delayed_messages WHERE sent_at IS NULL')->fetchColumn();
        if ($next === false || $next === null) {
            return null;
        }

        return max(0, (int) strtotime((string) $next) - time());
    }
}
