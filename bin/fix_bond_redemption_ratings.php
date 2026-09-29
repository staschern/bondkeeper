<?php

declare(strict_types=1);

/**
 * Разовое исправление (ПИШЕТ В БД, в отличие от
 * bin/debug_bond_redemption_ratings.php, который только читает) —
 * удаляет уже записанные ложные rating_actions "отозван" от отзыва
 * рейтинга ВЫПУСКА облигаций из-за его погашения (не отзыва рейтинга
 * самого эмитента), см. docs/STAGE3_RATINGS.md и
 * RatingsNormalizer::isBondIssueRedemptionWithdrawal(). Прямое решение
 * пользователя (17 сентября 2026) — не чинить точечно, а полностью
 * удалить такие записи как чистый шум.
 *
 * Запусти СНАЧАЛА bin/debug_bond_redemption_ratings.php — он покажет
 * ровно этот план (та же логика, BondRedemptionCleanup::plan()).
 *
 * === Что именно делается ===
 *
 * Проход 1 — для каждой ложной строки rating_actions: её событие в events
 * (если есть; notifications удалятся каскадом) и сама строка. Каждая
 * строка — отдельная транзакция.
 *
 * Проход 2 — текущий рейтинг (current_ratings) пересчитывается ТОЛЬКО
 * там, где он всё ещё тот самый ложный "отозван" (правка 29.09.2026):
 * раньше пересчитывались все затронутые пары, и значение из перезаписи
 * снимком или ручного ввода затиралось бы более старой новостью. Новое
 * значение — последняя оставшаяся новость (кроме тех, что теперь не
 * влияют на рейтинг компании); не осталось ни одной — строка
 * current_ratings удаляется. Подробно — докблок BondRedemptionCleanup.
 *
 * Идемпотентно: повторный запуск не найдёт уже удалённых строк.
 *
 * Запуск:
 *   php bin/fix_bond_redemption_ratings.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\BondRedemptionCleanup;
use BondKeeper\Support\Logger;

$cleanup = new BondRedemptionCleanup(Database::connection());
$plan = $cleanup->plan();

Logger::info('Найдено ложных записей (отзыв выпуска из-за погашения, не эмитента) для удаления: ' . count($plan['actions']));
if ($plan['actions'] === []) {
    Logger::info('Нечего удалять. Готово.');
    exit(0);
}

foreach ($plan['actions'] as $action) {
    Logger::info("Удаляется: rating_actions.id={$action['id']} (issuer_id={$action['issuer_id']} {$action['short_name']}, {$action['agency']}, {$action['action_date']})");
}
foreach ($plan['pairs'] as $pair) {
    $label = "issuer_id={$pair['issuer_id']} ({$pair['short_name']}) / {$pair['agency']}";
    if (!$pair['active']) {
        Logger::info("{$label}: текущий рейтинг не трогаем — сейчас " . ($pair['current'] !== null ? "{$pair['current']['rating']} от {$pair['current']['last_action_date']}" : 'строки нет'));
    } elseif ($pair['replacement'] !== null) {
        Logger::info("{$label}: текущий рейтинг «отозван» → {$pair['replacement']['rating']} от {$pair['replacement']['action_date']}");
    } else {
        Logger::info("{$label}: других новостей в истории нет — строка current_ratings удаляется");
    }
}

$stats = $cleanup->apply($plan);
foreach ($stats['errors'] as $error) {
    Logger::warn("Ошибка: {$error}");
}

Logger::info('=== Отчёт ===');
Logger::info("Удалено rating_actions: {$stats['deleted_actions']}");
Logger::info("Удалено events (вместе с каскадными notifications): {$stats['deleted_events']}");
Logger::info('Затронуто уникальных пар (issuer_id, agency): ' . count($plan['pairs']));
Logger::info("current_ratings пересчитан (был ложный «отозван»): {$stats['recomputed']}");
Logger::info("current_ratings удалён (других новостей не осталось): {$stats['removed']}");
Logger::info("current_ratings не тронут (там уже другое значение): {$stats['untouched']}");
Logger::info('Готово.');
