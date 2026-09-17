<?php

declare(strict_types=1);

/**
 * Разовое исправление (ПИШЕТ В БД, в отличие от
 * bin/debug_bond_redemption_ratings.php, который только читает) —
 * удаляет уже записанные ДО фикса 17 сентября 2026 ложные
 * rating_actions "отозван" от отзыва рейтинга ВЫПУСКА облигаций из-за
 * его погашения (не отзыва рейтинга самого эмитента), см.
 * docs/STAGE3_RATINGS.md и RatingsNormalizer::isBondIssueRedemptionWithdrawal().
 * Прямое решение пользователя (17 сентября 2026) — не чинить точечно, а
 * полностью удалить такие записи как чистый шум.
 *
 * Тот же запрос/фильтр, что и в bin/debug_bond_redemption_ratings.php —
 * запусти его СНАЧАЛА, чтобы увидеть, что именно будет удалено, прежде
 * чем запускать этот скрипт.
 *
 * === Что именно делается (два прохода, НЕ один проход на пару) ===
 *
 * Проход 1 — для каждой ложной строки rating_actions:
 *   - если у неё есть событие (event_id IS NOT NULL) — событие в events
 *     удаляется (notifications на него удалятся каскадом, FK ON DELETE
 *     CASCADE) — ложное "рейтинг отозван" не должно оставаться в истории
 *     событий эмитента, даже если уведомление уже было разослано;
 *   - сама строка rating_actions удаляется.
 * Каждая строка — отдельная транзакция (упадёт на одной — остальные всё
 * равно применятся, не всё-или-ничего на весь прогон).
 *
 * Проход 2 — ПОСЛЕ того, как ВСЕ ложные строки уже удалены (важно делать
 * отдельным проходом, не сразу внутри прохода 1: если у одного эмитента
 * несколько ложных строк подряд, пересчёт "из того, что осталось" сразу
 * после удаления первой мог бы временно подхватить ещё не удалённую
 * вторую ложную строку как "последнюю оставшуюся" — двухпроходная схема
 * этого избегает) — для каждой ЗАТРОНУТОЙ пары (issuer_id, agency)
 * current_ratings пересчитывается из ТЕПЕРЬ УЖЕ ЧИСТОЙ истории
 * rating_actions: берётся самое позднее по action_date оставшееся
 * действие; если не осталось ни одного — строка current_ratings
 * удаляется целиком (рейтинг для этой пары больше неизвестен).
 *
 * Идемпотентно: повторный запуск не найдёт уже удалённых строк (SELECT
 * тот же, что и раньше), безопасно перезапускать.
 *
 * Запуск:
 *   php bin/fix_bond_redemption_ratings.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\RatingsNormalizer;
use BondKeeper\Support\Logger;

$db = Database::connection();

$stmt = $db->query(
    "SELECT ra.id, ra.issuer_id, i.short_name, ra.agency, ra.source_title, ra.event_id
     FROM rating_actions ra
     JOIN issuers i ON i.id = ra.issuer_id
     WHERE ra.rating_to = 'отозван'
     ORDER BY ra.issuer_id, ra.agency, ra.action_date"
);
$rows = $stmt->fetchAll();

$falsePositives = array_values(array_filter(
    $rows,
    static fn (array $r): bool => (string) ($r['source_title'] ?? '') !== ''
        && RatingsNormalizer::isBondIssueRedemptionWithdrawal((string) $r['source_title'])
));

Logger::info('Найдено ложных записей (отзыв выпуска из-за погашения, не эмитента) для удаления: ' . count($falsePositives));

if ($falsePositives === []) {
    Logger::info('Нечего удалять. Готово.');
    exit(0);
}

// === Проход 1: удаление ложных строк (и их событий, если были) ===
$deletedActions = 0;
$deletedEvents = 0;
/** @var array<string, array{issuer_id: int, agency: string, short_name: string}> $affectedPairs ключ — "issuer_id:agency" */
$affectedPairs = [];

foreach ($falsePositives as $row) {
    $actionId = (int) $row['id'];
    $issuerId = (int) $row['issuer_id'];
    $agency = (string) $row['agency'];
    $pairKey = "{$issuerId}:{$agency}";
    $affectedPairs[$pairKey] = ['issuer_id' => $issuerId, 'agency' => $agency, 'short_name' => (string) $row['short_name']];

    $db->beginTransaction();
    try {
        if ($row['event_id'] !== null) {
            $db->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => (int) $row['event_id']]);
            $deletedEvents++;
        }

        $db->prepare('DELETE FROM rating_actions WHERE id = :id')->execute(['id' => $actionId]);
        $deletedActions++;

        $db->commit();
        Logger::info("Удалено: rating_actions.id={$actionId} (issuer_id={$issuerId} {$row['short_name']}, {$agency})");
    } catch (\Throwable $e) {
        $db->rollBack();
        Logger::warn("Ошибка при удалении rating_actions.id={$actionId}: {$e->getMessage()}");
    }
}

// === Проход 2: пересчёт current_ratings для каждой затронутой пары, уже из чистой истории ===
$recomputed = 0;
$currentRatingsRemoved = 0;

foreach ($affectedPairs as $pair) {
    $issuerId = $pair['issuer_id'];
    $agency = $pair['agency'];

    $db->beginTransaction();
    try {
        $latestStmt = $db->prepare(
            'SELECT rating_to, outlook_to, action_date FROM rating_actions
             WHERE issuer_id = :issuer_id AND agency = :agency
             ORDER BY action_date DESC, id DESC LIMIT 1'
        );
        $latestStmt->execute(['issuer_id' => $issuerId, 'agency' => $agency]);
        $latest = $latestStmt->fetch();

        if ($latest !== false) {
            $db->prepare(
                'UPDATE current_ratings
                 SET rating = :rating, outlook = :outlook, last_action_date = :last_action_date
                 WHERE issuer_id = :issuer_id AND agency = :agency'
            )->execute([
                'rating' => $latest['rating_to'],
                'outlook' => $latest['outlook_to'],
                'last_action_date' => $latest['action_date'],
                'issuer_id' => $issuerId,
                'agency' => $agency,
            ]);
            $recomputed++;
            Logger::info("issuer_id={$issuerId} ({$pair['short_name']}) / {$agency}: current_ratings пересчитан из оставшейся истории — rating={$latest['rating_to']}, дата={$latest['action_date']}");
        } else {
            $db->prepare('DELETE FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency')
                ->execute(['issuer_id' => $issuerId, 'agency' => $agency]);
            $currentRatingsRemoved++;
            Logger::info("issuer_id={$issuerId} ({$pair['short_name']}) / {$agency}: истории действий больше не осталось — current_ratings удалён целиком");
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        Logger::warn("Ошибка при пересчёте current_ratings для issuer_id={$issuerId}/{$agency}: {$e->getMessage()}");
    }
}

Logger::info('=== Отчёт ===');
Logger::info("Удалено rating_actions: {$deletedActions}");
Logger::info("Удалено events (вместе с каскадными notifications): {$deletedEvents}");
Logger::info('Затронуто уникальных пар (issuer_id, agency): ' . count($affectedPairs));
Logger::info("current_ratings пересчитан из оставшейся истории: {$recomputed}");
Logger::info("current_ratings удалён целиком (истории не осталось): {$currentRatingsRemoved}");
Logger::info('Готово.');
