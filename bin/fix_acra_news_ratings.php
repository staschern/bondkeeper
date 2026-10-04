<?php

declare(strict_types=1);

/**
 * РАЗОВЫЙ скрипт (03.10.2026, сверка АКРА): уже записанные новости АКРА
 * разбираются заново исправленным кодом (см. AcraNewsRecheck):
 *   - новость про ипотечные ценные бумаги или без рейтинга в заголовке —
 *     удаляется вместе с событием;
 *   - рейтинг записан не тот, что в заголовке (предлог "С" вместо
 *     AAA(RU)) — исправляется, событие с неверным рейтингом удаляется.
 * Текущий рейтинг компании меняется, только если он всё ещё взят из этой
 * самой новости.
 *
 *   php bin/fix_acra_news_ratings.php            # посмотреть, что поменяется
 *   php bin/fix_acra_news_ratings.php --apply    # применить
 *
 * Повторный запуск безопасен: после применения менять будет нечего.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\AcraNewsRecheck;
use BondKeeper\Support\Logger;

$apply = in_array('--apply', $argv, true);

$recheck = new AcraNewsRecheck(Database::connection());
$plan = $recheck->plan();

Logger::info($apply ? '=== ПРИМЕНЯЕТСЯ ===' : '=== ПРОСМОТР: ничего не меняется, для применения добавьте --apply ===');
Logger::info('Новостей АКРА к исправлению: ' . count($plan));

foreach ($plan as $item) {
    $what = $item['action'] === 'drop' ? 'УДАЛИТЬ' : "ИСПРАВИТЬ РЕЙТИНГ {$item['stored']} → {$item['grade']}";
    Logger::info("rating_actions.id={$item['id']} | issuer_id={$item['issuer_id']} {$item['short_name']} | {$item['action_date']} | {$what} — {$item['reason']}");
    Logger::info("  Заголовок: {$item['source_title']}");
    Logger::info('  Ссылка: ' . ($item['source_url'] ?? '—'));

    $current = $item['current'] !== null
        ? "{$item['current']['rating']} от {$item['current']['last_action_date']} (источник: " . ($item['current']['source'] ?? 'не указан') . ')'
        : 'строки нет';
    if (!$item['active']) {
        Logger::info("  Текущий рейтинг: {$current} — не из этой новости, не трогаем");
    } elseif ($item['action'] === 'regrade') {
        Logger::info("  Текущий рейтинг: {$current} → {$item['grade']}");
    } elseif ($item['replacement'] !== null) {
        Logger::info("  Текущий рейтинг: {$current} → {$item['replacement']['rating']} от {$item['replacement']['action_date']} по новости «{$item['replacement']['source_title']}»");
    } else {
        Logger::info("  Текущий рейтинг: {$current} → других новостей нет, строка current_ratings удаляется");
    }
}

if (!$apply || $plan === []) {
    Logger::info($plan === [] ? 'Нечего исправлять. Готово.' : 'Это просмотр — ничего не записано в БД.');
    exit(0);
}

$stats = $recheck->apply($plan);
foreach ($stats['errors'] as $error) {
    Logger::warn("Ошибка: {$error}");
}

Logger::info('=== Отчёт ===');
Logger::info("Удалено новостей (rating_actions): {$stats['dropped']}");
Logger::info("Исправлен рейтинг в новостях: {$stats['regraded']}");
Logger::info("Удалено events (вместе с каскадными notifications): {$stats['deleted_events']}");
Logger::info("Текущий рейтинг исправлен: {$stats['current_updated']}");
Logger::info("Текущий рейтинг удалён (других новостей нет): {$stats['current_removed']}");
Logger::info("Текущий рейтинг не тронут (он не из этой новости): {$stats['current_untouched']}");
Logger::info('Готово.');
