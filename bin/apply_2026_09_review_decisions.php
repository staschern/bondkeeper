<?php

declare(strict_types=1);

/**
 * РАЗОВЫЙ скрипт: решения по разбору сверки рейтингов (20-26 сентября
 * 2026). Запускать один раз, сразу после миграций 024 и 025, до первого
 * прогона новых импортёров. По умолчанию — только показывает, что будет
 * сделано (dry-run); применить — с --apply.
 *
 *   php bin/apply_2026_09_review_decisions.php            # посмотреть
 *   php bin/apply_2026_09_review_decisions.php --apply    # применить
 *
 * Повторный запуск безопасен: уже сделанное пропускается.
 *
 * Что делает:
 *   1. Связки issuer_spv_links (П4): ИНН компании, которой нет в issuers
 *      (у агентства рейтинг у неё), → наша компания. Существующая связка
 *      того же ИНН на ДРУГУЮ компанию не перезаписывается — выводится
 *      предупреждение.
 *   2. Отклонённые пары (П11): ОЗОН Капитал и ОЗОН Банк ≠ фармацевтическая
 *      ООО «Озон» (1442); липецкое АО «ПРОГРЕСС» ≠ курское ЗАО «Прогресс»
 *      (3382). Записываются в issuer_name_match_reviews со статусом
 *      rejected — больше не предлагаются.
 *   3. Чистка чужих рейтингов (П12): у 1442 и 3382 удаляются строки
 *      current_ratings и rating_actions с matched_by_root_name=1 и события
 *      этих действий (уведомления удаляются каскадом). Только если ИНН
 *      этих компаний в issuers совпадает с ожидаемым — защита от
 *      перепутанного issuer_id.
 *   4. Метка source='manual' (П3) на строках current_ratings из ручного
 *      xlsx от 02.09.2026 (updated_at = 2026-09-02 16:30:49, source ещё не
 *      проставлен). Если найденные строки не совпадут с ожидаемыми семью —
 *      скрипт это покажет; альтернатива — заново загрузить тот же xlsx
 *      (php bin/seed_ratings.php --agency=manual --file=...), теперь он
 *      сам пишет source='manual'.
 *   5. Показывает оставшиеся строки со старым флагом matched_by_root_name=1
 *      (П13) — по ним придут предложения на подтверждение.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Support\Logger;

$apply = in_array('--apply', $argv, true);

$db = Database::connection();
$matcher = new IssuerMatcher($db);

Logger::info($apply ? '=== ПРИМЕНЕНИЕ решений по разбору сверки ===' : '=== DRY-RUN: ничего не меняется, для применения добавьте --apply ===');

/**
 * Цель связки — по issuer_id (проверенному при разборе) или по ИНН.
 * @var array<int, array{spv_inn: string, spv_name: string, issuer_id?: int, issuer_inn?: string, note: string}> $links
 */
$links = [
    ['spv_inn' => '3900015862', 'spv_name' => 'МКПАО «ВК»', 'issuer_id' => 493, 'note' => 'П4: НКР рейтингует мать; облигации — Мэйл.Ру Финанс'],
    ['spv_inn' => '7715265054', 'spv_name' => 'ООО «ПК «Борец»', 'issuer_id' => 1306, 'note' => 'П4: НКР рейтингует мать; облигации — Борец Капитал'],
    ['spv_inn' => '9722079341', 'spv_name' => 'ООО «Корпоративный центр ИКС 5»', 'issuer_id' => 1536, 'note' => 'П4: НКР рейтингует мать; облигации — ИКС 5 ФИНАНС'],
    ['spv_inn' => '6623000680', 'spv_name' => 'ПАО «ЕВРАЗ» (бывш. АО «ЕВРАЗ НТМК»)', 'issuer_id' => 1790, 'note' => 'П4: НКР рейтингует мать; облигации — ЕвразХолдинг Финанс'],
    ['spv_inn' => '7710380617', 'spv_name' => 'ООО «Аэрофьюэлз Групп»', 'issuer_id' => 1561, 'note' => 'П13: подтверждённое совпадение «по корню»'],
    ['spv_inn' => '7708335695', 'spv_name' => 'ООО «ЕВА»', 'issuer_inn' => '7714482955', 'note' => 'ФСК Активы — рейтингуемое лицо ЕВА'],
    ['spv_inn' => '2315190775', 'spv_name' => 'ООО «АгроНэкст»', 'issuer_inn' => '9725091192', 'note' => 'Русбонд-Удобрения — рейтингуемое лицо АгроНэкст'],
];

/** @var array<int, array{source_inn: string, source_name: string, issuer_id: int, agency: string}> $rejections */
$rejections = [
    ['source_inn' => '6949003359', 'source_name' => 'ОЗОН КАПИТАЛ', 'issuer_id' => 1442, 'agency' => 'expert_ra'],
    ['source_inn' => '9703077050', 'source_name' => 'ООО «ОЗОН Банк»', 'issuer_id' => 1442, 'agency' => 'nra'],
    ['source_inn' => '4826022365', 'source_name' => 'АО «ПРОГРЕСС» (Липецк)', 'issuer_id' => 3382, 'agency' => 'nra'],
];

/** issuer_id => ожидаемый ИНН в issuers (защита чистки от перепутанного id). */
$cleanupIssuers = [1442 => '6345002063', 3382 => '4622004142'];

$manualTimestamp = '2026-09-02 16:30:49';
$expectedManual = [1306, 1790, 68, 1789, 3050, 3567, 3573];

$issuerStmt = $db->prepare('SELECT id, short_name, inn FROM issuers WHERE id = :id');
$fetchIssuer = static function (int $id) use ($issuerStmt): ?array {
    $issuerStmt->execute(['id' => $id]);
    $row = $issuerStmt->fetch();
    return $row !== false ? $row : null;
};

// === 1. Связки ===
Logger::info('--- 1. Связки issuer_spv_links ---');
$existingLink = $db->prepare('SELECT issuer_id FROM issuer_spv_links WHERE spv_inn = :spv_inn');
foreach ($links as $link) {
    $issuerId = $link['issuer_id'] ?? $matcher->findIssuerIdByInn($link['issuer_inn']);
    $issuer = $issuerId !== null ? $fetchIssuer($issuerId) : null;
    if ($issuer === null) {
        Logger::warn("ИНН {$link['spv_inn']} ({$link['spv_name']}): целевая компания не найдена ("
            . (isset($link['issuer_inn']) ? "ИНН {$link['issuer_inn']}" : "issuer_id={$link['issuer_id']}") . ') — пропуск');
        continue;
    }
    if ($matcher->findIssuerIdByInn($link['spv_inn']) !== null) {
        Logger::warn("ИНН {$link['spv_inn']} ({$link['spv_name']}) сам есть в issuers — связка не нужна, сопоставится напрямую; пропуск");
        continue;
    }

    $existingLink->execute(['spv_inn' => $link['spv_inn']]);
    $current = $existingLink->fetchColumn();
    $existingLink->closeCursor();
    $target = "{$issuer['short_name']} (issuer_id={$issuer['id']}, ИНН {$issuer['inn']})";
    if ($current !== false && (int) $current === (int) $issuer['id']) {
        Logger::info("Уже есть: {$link['spv_name']} (ИНН {$link['spv_inn']}) → {$target}");
        continue;
    }
    if ($current !== false) {
        Logger::warn("ИНН {$link['spv_inn']} уже связан с issuer_id={$current}, а по разбору должен вести на {$target} — НЕ перезаписываю, проверьте вручную (bin/link_spv.php --list)");
        continue;
    }

    Logger::info(($apply ? 'Заведена' : 'Будет заведена') . " связка: {$link['spv_name']} (ИНН {$link['spv_inn']}) → {$target}");
    if ($apply) {
        $db->prepare('INSERT INTO issuer_spv_links (spv_inn, issuer_id, spv_name, note) VALUES (:spv_inn, :issuer_id, :spv_name, :note)')
            ->execute([
                'spv_inn' => $link['spv_inn'],
                'issuer_id' => (int) $issuer['id'],
                'spv_name' => $link['spv_name'],
                'note' => 'разбор сверки сентябрь 2026: ' . $link['note'],
            ]);
    }
}

// === 2. Отклонённые пары ===
Logger::info('--- 2. Отклонённые пары (больше не предлагать) ---');
$existingReview = $db->prepare(
    "SELECT id, status FROM issuer_name_match_reviews WHERE source_key_type = 'inn' AND source_key = :source_key AND issuer_id = :issuer_id"
);
foreach ($rejections as $r) {
    $issuer = $fetchIssuer($r['issuer_id']);
    $target = $issuer !== null ? "{$issuer['short_name']} (issuer_id={$issuer['id']}, ИНН {$issuer['inn']})" : "issuer_id={$r['issuer_id']} (не найден!)";
    if ($issuer === null) {
        Logger::warn("{$r['source_name']} (ИНН {$r['source_inn']}) → {$target} — пропуск");
        continue;
    }

    $existingReview->execute(['source_key' => $r['source_inn'], 'issuer_id' => $r['issuer_id']]);
    $review = $existingReview->fetch();
    $existingReview->closeCursor();
    if ($review !== false && $review['status'] === 'rejected') {
        Logger::info("Уже отклонено: {$r['source_name']} (ИНН {$r['source_inn']}) ≠ {$target}");
        continue;
    }

    Logger::info(($apply ? 'Отклонено' : 'Будет отклонено') . ": {$r['source_name']} (ИНН {$r['source_inn']}) ≠ {$target}");
    if (!$apply) {
        continue;
    }
    if ($review !== false) {
        $db->prepare("UPDATE issuer_name_match_reviews SET status = 'rejected', decided_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute(['id' => (int) $review['id']]);
    } else {
        $db->prepare(
            "INSERT INTO issuer_name_match_reviews
                (source_key_type, source_key, issuer_id, status, match_type, agency, source_name, source_title, notified_at, decided_at)
             VALUES ('inn', :source_key, :issuer_id, 'rejected', 'root_name', :agency, :source_name,
                     'разбор сверки сентябрь 2026: ложное совпадение «по корню»', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([
            'source_key' => $r['source_inn'],
            'issuer_id' => $r['issuer_id'],
            'agency' => $r['agency'],
            'source_name' => $r['source_name'],
        ]);
    }
    $db->prepare('DELETE FROM issuer_spv_links WHERE spv_inn = :spv_inn AND issuer_id = :issuer_id')
        ->execute(['spv_inn' => $r['source_inn'], 'issuer_id' => $r['issuer_id']]);
}

// === 3. Чистка чужих рейтингов ===
Logger::info('--- 3. Чужие рейтинги у 1442 (Озон) и 3382 (Прогресс) ---');
foreach ($cleanupIssuers as $issuerId => $expectedInn) {
    $issuer = $fetchIssuer($issuerId);
    if ($issuer === null || IssuerMatcher::normalizeInn($issuer['inn']) !== $expectedInn) {
        Logger::warn("issuer_id={$issuerId}: ожидался ИНН {$expectedInn}, в issuers — " . ($issuer['inn'] ?? 'компании нет') . ' — чистку НЕ выполняю');
        continue;
    }

    $cr = $db->prepare('SELECT agency, rating, last_action_date FROM current_ratings WHERE issuer_id = :id AND matched_by_root_name = 1');
    $cr->execute(['id' => $issuerId]);
    foreach ($cr->fetchAll() as $row) {
        Logger::info(($apply ? 'Удалено' : 'Будет удалено') . " current_ratings: {$issuer['short_name']} ({$issuerId}) / {$row['agency']} — {$row['rating']} от {$row['last_action_date']}");
    }

    $ra = $db->prepare(
        'SELECT ra.id, ra.agency, ra.action_date, ra.rating_to, ra.source_title, ra.event_id,
                (SELECT COUNT(*) FROM notifications n WHERE n.event_id = ra.event_id) AS notifications
         FROM rating_actions ra
         WHERE ra.issuer_id = :id AND ra.matched_by_root_name = 1
         ORDER BY ra.action_date'
    );
    $ra->execute(['id' => $issuerId]);
    $actions = $ra->fetchAll();
    foreach ($actions as $row) {
        Logger::info(($apply ? 'Удалено' : 'Будет удалено') . " rating_actions #{$row['id']}: {$row['agency']} {$row['action_date']} {$row['rating_to']} «{$row['source_title']}»"
            . ($row['event_id'] !== null ? " + событие #{$row['event_id']} (уведомлений: {$row['notifications']})" : ''));
    }

    if (!$apply) {
        continue;
    }
    $db->beginTransaction();
    try {
        foreach ($actions as $row) {
            if ($row['event_id'] !== null) {
                $db->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => (int) $row['event_id']]);
            }
            $db->prepare('DELETE FROM rating_actions WHERE id = :id')->execute(['id' => (int) $row['id']]);
        }
        $db->prepare('DELETE FROM current_ratings WHERE issuer_id = :id AND matched_by_root_name = 1')->execute(['id' => $issuerId]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// === 4. Метка source='manual' ===
Logger::info("--- 4. source='manual' для ручного xlsx от 02.09.2026 ---");
$manual = $db->prepare(
    'SELECT cr.issuer_id, cr.agency, cr.rating, cr.last_action_date, i.short_name
     FROM current_ratings cr JOIN issuers i ON i.id = cr.issuer_id
     WHERE cr.updated_at = :ts AND cr.source IS NULL
     ORDER BY cr.issuer_id'
);
$manual->execute(['ts' => $manualTimestamp]);
$manualRows = $manual->fetchAll();
foreach ($manualRows as $row) {
    Logger::info(($apply ? 'Помечено' : 'Будет помечено') . " как manual: {$row['short_name']} ({$row['issuer_id']}) / {$row['agency']} — {$row['rating']} от {$row['last_action_date']}");
}
$foundIds = array_map('intval', array_column($manualRows, 'issuer_id'));
$missingExpected = array_diff($expectedManual, $foundIds);
$unexpected = array_diff($foundIds, $expectedManual);
if ($missingExpected !== [] || $unexpected !== []) {
    Logger::warn('Найденные строки не совпадают с ожидаемыми семью (1306, 1790, 68, 1789, 3050, 3567, 3573): '
        . 'не найдены — ' . ($missingExpected !== [] ? implode(', ', $missingExpected) : 'нет')
        . '; лишние — ' . ($unexpected !== [] ? implode(', ', $unexpected) : 'нет')
        . '. Надёжнее перезагрузить тот же xlsx: php bin/seed_ratings.php --agency=manual --file=...');
}
if ($apply && $manualRows !== []) {
    $db->prepare("UPDATE current_ratings SET source = 'manual' WHERE updated_at = :ts AND source IS NULL")
        ->execute(['ts' => $manualTimestamp]);
}

// === 5. Оставшиеся строки со старым флагом ===
Logger::info('--- 5. Оставшиеся строки со старым флагом matched_by_root_name=1 (придут предложениями на подтверждение) ---');
$legacy = $db->query(
    "SELECT 'current_ratings' AS tbl, cr.issuer_id, i.short_name, cr.agency, cr.rating AS rating, cr.last_action_date AS dt, NULL AS title
     FROM current_ratings cr JOIN issuers i ON i.id = cr.issuer_id WHERE cr.matched_by_root_name = 1
     UNION ALL
     SELECT 'rating_actions', ra.issuer_id, i.short_name, ra.agency, ra.rating_to, ra.action_date, ra.source_title
     FROM rating_actions ra JOIN issuers i ON i.id = ra.issuer_id WHERE ra.matched_by_root_name = 1
     ORDER BY issuer_id"
)->fetchAll();
foreach ($legacy as $row) {
    if (!$apply && isset($cleanupIssuers[(int) $row['issuer_id']])) {
        continue; // будет удалено в шаге 3
    }
    Logger::info("{$row['tbl']}: {$row['short_name']} ({$row['issuer_id']}) / {$row['agency']} — {$row['rating']} от {$row['dt']}"
        . ($row['title'] !== null ? " «{$row['title']}»" : ''));
}

Logger::info($apply
    ? 'Готово. Дальше: сверка php bin/reconcile_ratings.php --agency=nkr (проверить, что 493, 1306, 1536, 1790 больше не «нет в снимке»), затем перезапись снимком по команде из отчёта.'
    : 'Это был просмотр. Если всё верно — запустите с --apply.');
