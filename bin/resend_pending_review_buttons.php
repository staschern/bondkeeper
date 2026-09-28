<?php

declare(strict_types=1);

/**
 * РАЗОВЫЙ скрипт (28 сентября 2026): переслать уже отправленные, но ещё
 * не решённые предложения сопоставления по названию — С КНОПКАМИ
 * "Подтвердить"/"Отклонить" (issuer_name_match_reviews, миграция 024,
 * кнопки — см. docs/STAGE3_RATINGS.md, раздел "Кнопки прямо в
 * Telegram"). Нужен один раз — для предложений, отправленных ДО того,
 * как кнопки появились (notifyNewProposals() их не пришлёт снова: она
 * шлёт только notified_at IS NULL, а эти уже отправлялись, просто
 * текстом с CLI-командой без кнопок).
 *
 * notified_at НЕ трогаем — это пересылка, не первое уведомление.
 * Повторный запуск безопасен: решённые (approved/rejected) предложения
 * listPending() не вернёт, шлёт только то, что реально ещё висит.
 *
 *   php bin/resend_pending_review_buttons.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\NameMatchReviews;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\AdminNotifier;

$db = Database::connection();
$reviews = new NameMatchReviews($db);

$pending = $reviews->listPending();
if ($pending === []) {
    Logger::info('Предложений, ждущих решения, нет.');
    exit(0);
}

$sent = 0;
foreach ($pending as $row) {
    $ok = AdminNotifier::send(NameMatchReviews::formatProposal($row), NameMatchReviews::proposalKeyboard((int) $row['id']));
    if ($ok) {
        $sent++;
    } else {
        Logger::warn("Предложение #{$row['id']}: отправить не удалось, см. WARN выше.");
    }
}

Logger::info("Переслано с кнопками: {$sent} из " . count($pending) . '.');
