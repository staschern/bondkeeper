<?php

declare(strict_types=1);

/**
 * Диагностика (БЕЗ записи в БД) — найти строки, уже испорченные багом
 * "отзыв рейтинга ВЫПУСКА из-за погашения писался как отзыв рейтинга
 * ЭМИТЕНТА" (см. docs/STAGE3_RATINGS.md, раздел от 17 сентября 2026, и
 * RatingsNormalizer::isBondIssueRedemptionWithdrawal()). Сам фикс
 * защищает только БУДУЩИЕ прогоны — уже записанные строки
 * rating_actions/current_ratings он не трогает; их удаляет
 * bin/fix_bond_redemption_ratings.php, а этот скрипт показывает ровно его
 * план (BondRedemptionCleanup::plan()).
 *
 * По каждой паре (компания, агентство):
 *   - АКТИВНОЕ заражение — текущий рейтинг 'отозван' с датой одной из
 *     ложных строк (прямо сейчас показывается "рейтинг отозван"); скрипт
 *     исправления заменит его последней оставшейся новостью или удалит
 *     строку, если новостей не осталось;
 *   - историческое — текущий рейтинг уже другой (перезапись снимком,
 *     ручной ввод, более поздняя новость); удаляется только строка
 *     истории, текущий рейтинг не трогается (правка 29.09.2026).
 *
 * Запуск:
 *   php bin/debug_bond_redemption_ratings.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\BondRedemptionCleanup;

$plan = (new BondRedemptionCleanup(Database::connection()))->plan();

echo 'Ложных строк rating_actions (отзыв ВЫПУСКА из-за погашения, не эмитента): ' . count($plan['actions']) . "\n";
echo str_repeat('=', 100) . "\n\n";

foreach ($plan['actions'] as $action) {
    printf(
        "rating_actions.id=%d | issuer_id=%d %s | %s | %s\n  Заголовок: %s\n  Ссылка: %s\n\n",
        $action['id'],
        $action['issuer_id'],
        $action['short_name'],
        $action['agency'],
        $action['action_date'],
        $action['source_title'],
        $action['source_url'] ?? '—'
    );
}

echo str_repeat('=', 100) . "\nТекущий рейтинг по затронутым парам:\n\n";
$activeCount = 0;
foreach ($plan['pairs'] as $pair) {
    $current = $pair['current'] !== null
        ? "{$pair['current']['rating']} от {$pair['current']['last_action_date']} (источник: " . ($pair['current']['source'] ?? 'не указан') . ')'
        : 'строки нет';
    if ($pair['active']) {
        $activeCount++;
        $after = $pair['replacement'] !== null
            ? "станет {$pair['replacement']['rating']} от {$pair['replacement']['action_date']} по новости «{$pair['replacement']['source_title']}»"
            : 'других новостей нет — строка current_ratings будет удалена';
        echo "⚠️  АКТИВНОЕ ЗАРАЖЕНИЕ issuer_id={$pair['issuer_id']} {$pair['short_name']} | {$pair['agency']}\n  сейчас: {$current}\n  {$after}\n\n";
    } else {
        echo "   (историческое) issuer_id={$pair['issuer_id']} {$pair['short_name']} | {$pair['agency']}\n  сейчас: {$current} — не трогаем\n\n";
    }
}

echo str_repeat('=', 100) . "\n";
echo "Активно заражённых (прямо сейчас показывается ложное 'рейтинг отозван'): {$activeCount}\n";
echo 'Готово. Это только диагностика — ничего не записано в БД.' . "\n";
