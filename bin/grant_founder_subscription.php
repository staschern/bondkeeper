<?php

declare(strict_types=1);

/**
 * Разовая выдача безлимитной подписки 'founder' учредителям проекта
 * (прямой запрос пользователя, 17 сентября 2026): неограниченное число
 * отслеживаемых эмитентов и фактически неограниченное время действия.
 * Требует, чтобы уже была применена database/020_founder_tariff.sql.
 *
 * current_period_end = '9999-12-31' — тот же sentinel "бессрочно", что
 * был задуман в самой первой версии схемы (см. комментарий над
 * CREATE TABLE subscriptions в database/001_schema.sql) — витринный,
 * currentTariffLimit() его вообще не читает (см. её докблок в
 * BotCommandHandler.php), лимит снимается через max_tracked_issuers=NULL
 * у самого тарифа 'founder'.
 *
 * Идемпотентно, безопасно перезапускать: если у telegram_id уже активна
 * подписка 'founder' — эта запись пропускается, вторая строка не
 * создаётся. Если пользователь ещё ни разу не писал боту — строка в
 * users создаётся здесь же (единственное обязательное поле —
 * telegram_id). Если у пользователя уже была другая активная подписка
 * (обычно 'free') — она закрывается (status='canceled'), а не остаётся
 * висеть второй "активной" строкой — тот же принцип, что и у обычной
 * смены тарифа (см. докблок CREATE TABLE subscriptions: "status='active'
 * — ровно у одной, последней по времени строки пользователя").
 *
 * Запуск:
 *   php bin/grant_founder_subscription.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Support\Logger;

// Telegram ID учредителей — прямой запрос пользователя, 17 сентября 2026.
const FOUNDER_TELEGRAM_IDS = [1007481909, 235466123];

$db = Database::connection();

foreach (FOUNDER_TELEGRAM_IDS as $telegramId) {
    $userStmt = $db->prepare('SELECT id FROM users WHERE telegram_id = :telegram_id');
    $userStmt->execute(['telegram_id' => $telegramId]);
    $userId = $userStmt->fetchColumn();

    if ($userId === false) {
        $db->prepare('INSERT INTO users (telegram_id) VALUES (:telegram_id)')
            ->execute(['telegram_id' => $telegramId]);
        $userId = (int) $db->lastInsertId();
        Logger::info("telegram_id={$telegramId}: строки в users не было, создана (user_id={$userId})");
    } else {
        $userId = (int) $userId;
    }

    $activeStmt = $db->prepare(
        "SELECT id, tariff_code FROM subscriptions WHERE user_id = :user_id AND status = 'active' ORDER BY id DESC LIMIT 1"
    );
    $activeStmt->execute(['user_id' => $userId]);
    $active = $activeStmt->fetch();

    if ($active !== false && $active['tariff_code'] === 'founder') {
        Logger::info("telegram_id={$telegramId} (user_id={$userId}): уже на тарифе 'founder', пропущен");
        continue;
    }

    if ($active !== false) {
        $db->prepare(
            "UPDATE subscriptions SET status = 'canceled', canceled_at = NOW(), canceled_by = 'system' WHERE id = :id"
        )->execute(['id' => $active['id']]);
    }

    $db->prepare(
        "INSERT INTO subscriptions (user_id, tariff_code, status, current_period_end)
         VALUES (:user_id, 'founder', 'active', '9999-12-31 00:00:00')"
    )->execute(['user_id' => $userId]);

    Logger::info("telegram_id={$telegramId} (user_id={$userId}): выдан тариф 'founder' — безлимит эмитентов, бессрочно");
}

Logger::info('Готово.');
