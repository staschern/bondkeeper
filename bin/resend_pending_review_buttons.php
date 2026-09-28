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
 * Заодно чинит уже сохранённые строки, к которым успел прилипнуть старый
 * баг NkrImporter::describeRow() (та же дата — ссылка на пресс-релиз без
 * "https://" не распознавалась и терялась в тексте заголовка вместо
 * source_url, см. её докблок): repairLegacyNkrProposalUrls() ниже
 * находит такие строки по образцу "; пресс-релиз: <адрес>" в заголовке и
 * восстанавливает настоящую ссылку ДО отправки — реального перезапуска
 * НКР-импортёра ради этого не нужно.
 *
 *   php bin/resend_pending_review_buttons.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\NameMatchReviews;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\AdminNotifier;

/**
 * @return int сколько строк починено
 */
function repairLegacyNkrProposalUrls(PDO $db): int
{
    $stmt = $db->query(
        "SELECT id, source_title FROM issuer_name_match_reviews
         WHERE status = 'pending' AND agency = 'nkr' AND source_title LIKE '%; пресс-релиз: %'"
    );
    $rows = $stmt->fetchAll();

    $update = $db->prepare('UPDATE issuer_name_match_reviews SET source_title = :title, source_url = :url WHERE id = :id');
    $fixed = 0;
    foreach ($rows as $row) {
        if (!preg_match('~^(.*); пресс-релиз: ([a-z0-9.\-/:%]+)$~iu', (string) $row['source_title'], $m)) {
            continue;
        }
        $url = preg_match('~^https?://~i', $m[2]) ? $m[2] : ('https://' . $m[2]);
        $update->execute(['title' => $m[1], 'url' => $url, 'id' => (int) $row['id']]);
        Logger::info("Предложение #{$row['id']}: восстановлена ссылка на пресс-релиз — {$url}");
        $fixed++;
    }

    return $fixed;
}

$db = Database::connection();
$reviews = new NameMatchReviews($db);

$fixed = repairLegacyNkrProposalUrls($db);
if ($fixed > 0) {
    Logger::info("Починено строк со старой потерянной ссылкой: {$fixed}.");
}

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
