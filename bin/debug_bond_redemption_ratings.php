<?php

declare(strict_types=1);

/**
 * Диагностика (БЕЗ записи в БД) — найти строки, уже испорченные багом
 * "отзыв рейтинга ВЫПУСКА из-за погашения писался как отзыв рейтинга
 * ЭМИТЕНТА" (см. docs/STAGE3_RATINGS.md, раздел от 17 сентября 2026, и
 * RatingsNormalizer::isBondIssueRedemptionWithdrawal()). Сам фикс
 * защищает только БУДУЩИЕ прогоны — уже записанные до фикса строки
 * rating_actions/current_ratings он не трогает, эта диагностика находит
 * их для ручного решения, что с ними делать дальше.
 *
 * Логика: берём все rating_actions, где rating_to = 'отозван' (литерал,
 * которым всегда записывался отзыв), прогоняем их source_title (сырой
 * заголовок пресс-релиза, сохранённый на момент импорта) через ТОТ ЖЕ
 * самый метод, что теперь фильтрует такие строки на входе. Совпало —
 * значит эта строка ЛОЖНАЯ (отзыв выпуска, не эмитента). Дополнительно
 * сверяем с текущим значением current_ratings.rating для той же пары
 * (issuer_id, agency) — если оно тоже 'отозван' и его last_action_date
 * совпадает с датой этой самой строки, это АКТИВНОЕ заражение (прямо
 * сейчас показывается пользователям как "рейтинг отозван"); если
 * current_ratings уже другое (более позднее реальное действие
 * перезаписало) — это исторический след, никак не влияющий на
 * отображаемый статус сейчас.
 *
 * Запуск:
 *   php bin/debug_bond_redemption_ratings.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\RatingsNormalizer;

$db = Database::connection();

$stmt = $db->query(
    "SELECT ra.id, ra.issuer_id, i.short_name, i.inn, ra.agency, ra.action_date,
            ra.source_title, ra.source_url,
            cr.rating AS current_rating, cr.last_action_date AS current_last_action_date
     FROM rating_actions ra
     JOIN issuers i ON i.id = ra.issuer_id
     LEFT JOIN current_ratings cr ON cr.issuer_id = ra.issuer_id AND cr.agency = ra.agency
     WHERE ra.rating_to = 'отозван'
     ORDER BY ra.issuer_id, ra.agency, ra.action_date"
);
$rows = $stmt->fetchAll();

echo 'Всего строк rating_actions с rating_to=\'отозван\': ' . count($rows) . "\n\n";

$falsePositives = [];
foreach ($rows as $row) {
    $title = (string) ($row['source_title'] ?? '');
    if ($title === '' || !RatingsNormalizer::isBondIssueRedemptionWithdrawal($title)) {
        continue;
    }
    $falsePositives[] = $row;
}

echo 'Из них ложных (отзыв ВЫПУСКА из-за погашения, не эмитента): ' . count($falsePositives) . "\n";
echo str_repeat('=', 100) . "\n\n";

$activeCount = 0;
foreach ($falsePositives as $row) {
    $isActive = $row['current_rating'] === 'отозван' && $row['current_last_action_date'] === $row['action_date'];
    if ($isActive) {
        $activeCount++;
    }

    printf(
        "%s issuer_id=%d %s (ИНН %s) | агентство: %s | дата действия: %s\n  Заголовок: %s\n  Ссылка: %s\n  current_ratings сейчас: %s (last_action_date=%s)%s\n\n",
        $isActive ? '⚠️  АКТИВНОЕ ЗАРАЖЕНИЕ' : '   (историческое, уже перекрыто)',
        (int) $row['issuer_id'],
        (string) $row['short_name'],
        (string) $row['inn'],
        (string) $row['agency'],
        (string) $row['action_date'],
        (string) $row['source_title'],
        (string) $row['source_url'],
        $row['current_rating'] !== null ? (string) $row['current_rating'] : '—',
        $row['current_last_action_date'] !== null ? (string) $row['current_last_action_date'] : '—',
        $isActive ? "\n  rating_actions.id=" . (int) $row['id'] : ''
    );
}

echo str_repeat('=', 100) . "\n";
echo "Активно заражённых (прямо сейчас показывается ложное 'рейтинг отозван'): {$activeCount}\n";
echo 'Готово. Это только диагностика — ничего не записано в БД.' . "\n";
