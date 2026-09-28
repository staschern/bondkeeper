<?php

declare(strict_types=1);

/**
 * Подтверждение/отклонение предложений "сопоставить по названию"
 * (issuer_name_match_reviews, миграция 024). Решение пользователя
 * (сентябрь 2026): там, где сопоставление идёт по названию (точное или
 * "по корню"), в базу ничего не пишется без его подтверждения. Каждое
 * новое предложение приходит администратору в Telegram отдельным
 * сообщением — с названием у агентства и у нас, ИНН, заголовком новости,
 * ссылкой на пресс-релиз и готовыми командами этого скрипта.
 *
 *   php bin/review_matches.php --list           все ждущие решения
 *   php bin/review_matches.php --approve=ID     подтвердить
 *   php bin/review_matches.php --reject=ID      отклонить (больше не предлагать)
 *
 * Подтверждение источника с ИНН заводит связку issuer_spv_links (дальше
 * этот ИНН сопоставляется напрямую всеми импортёрами), без ИНН —
 * запоминает подтверждённое название. Отклонение снимает связку, если
 * она была, и удаляет строку current_ratings этой компании по этому
 * агентству, записанную старым сопоставлением "по корню".
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\NameMatchReviews;
use BondKeeper\Support\Logger;

$approveId = null;
$rejectId = null;
$list = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--approve=')) {
        $approveId = (int) substr($arg, strlen('--approve='));
    } elseif (str_starts_with($arg, '--reject=')) {
        $rejectId = (int) substr($arg, strlen('--reject='));
    } elseif ($arg === '--list') {
        $list = true;
    }
}

$modes = (int) ($approveId !== null) + (int) ($rejectId !== null) + (int) $list;
if ($modes !== 1 || $approveId === 0 || $rejectId === 0) {
    fwrite(STDERR, "Использование:\n"
        . "  php bin/review_matches.php --list\n"
        . "  php bin/review_matches.php --approve=ID\n"
        . "  php bin/review_matches.php --reject=ID\n");
    exit(1);
}

$db = Database::connection();
$reviews = new NameMatchReviews($db);

/** Что будет с новостью, которую пропустили, пока пара ждала решения. */
function nextStepHint(array $row): string
{
    $isSnapshotRow = str_starts_with((string) ($row['source_title'] ?? ''), 'Полная выгрузка')
        || str_starts_with((string) ($row['source_title'] ?? ''), 'Выгрузка АКРА');
    if ($isSnapshotRow) {
        return 'Строка полной выгрузки: попадёт в снимок при следующей сверке (bin/reconcile_ratings.php), '
            . 'в current_ratings — после перезаписи проверенным снимком.';
    }

    return match ($row['agency']) {
        'nra' => 'Новость НРА подхватится следующим прогоном --agency=nra (он читает всю историю).',
        'acra' => 'Новость подхватится следующим прогоном --agency=acra-news, если ещё в окне --days; иначе разово: php bin/seed_ratings.php --agency=acra-news --full',
        default => "Новость подхватится следующим прогоном --agency={$row['agency']}-news, если ещё в окне --days (глубокий проход — 14 дней); "
            . "старее — разово: php bin/seed_ratings.php --agency={$row['agency']}-news --full",
    };
}

if ($list) {
    $pending = $reviews->listPending();
    if ($pending === []) {
        Logger::info('Предложений, ждущих решения, нет.');
        exit(0);
    }
    foreach ($pending as $row) {
        echo NameMatchReviews::formatProposal($row) . "\n\n" . str_repeat('-', 60) . "\n\n";
    }
    Logger::info('Ждут решения: ' . count($pending));
    exit(0);
}

if ($approveId !== null) {
    $row = $reviews->approve($approveId);
    Logger::info("Предложение #{$approveId} подтверждено: «{$row['source_name']}» → {$row['issuer_short_name']} (issuer_id={$row['issuer_id']}).");
    Logger::info($row['source_key_type'] === 'inn'
        ? "Заведена связка issuer_spv_links: ИНН {$row['source_key']} → issuer_id={$row['issuer_id']}."
        : 'Название запомнено как подтверждённое (источник без ИНН).');
    Logger::info(nextStepHint($row));
    exit(0);
}

$row = $reviews->reject((int) $rejectId);
Logger::info("Предложение #{$rejectId} отклонено: «{$row['source_name']}» НЕ {$row['issuer_short_name']} (issuer_id={$row['issuer_id']}). Больше предлагаться не будет.");
if ($row['removed_current_ratings'] > 0) {
    Logger::info("Удалена строка current_ratings ({$row['agency']}), записанная раньше старым сопоставлением «по корню».");
}

$stmt = $db->prepare(
    'SELECT COUNT(*) FROM rating_actions WHERE issuer_id = :issuer_id AND agency = :agency AND matched_by_root_name = 1'
);
$stmt->execute(['issuer_id' => (int) $row['issuer_id'], 'agency' => $row['agency']]);
$legacyActions = (int) $stmt->fetchColumn();
if ($legacyActions > 0) {
    Logger::warn("У этой компании по этому агентству есть {$legacyActions} строк rating_actions, записанных старым сопоставлением «по корню» — "
        . 'проверьте их заголовки (SELECT id, action_date, rating_to, source_title FROM rating_actions WHERE issuer_id = '
        . (int) $row['issuer_id'] . " AND agency = '{$row['agency']}' AND matched_by_root_name = 1) и при необходимости удалите вместе с событиями.");
}
