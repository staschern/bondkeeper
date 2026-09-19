<?php

declare(strict_types=1);

/**
 * Ручное управление таблицей issuer_spv_links (миграция 023) — явная
 * связка "ИНН, которого нет в issuers → issuer_id, который уже есть"
 * для случаев, когда имена вообще не имеют ничего общего и
 * автоматическое сопоставление (ИНН/точное имя/"по корню" — миграция
 * 021) принципиально не может их связать.
 *
 * ВАЖНО про направление — уточнено пользователем не с первого раза,
 * стоит подчеркнуть явно: --spv-inn — это НЕ обязательно "ИНН SPV" в
 * смысле реальной корпоративной структуры (кто чья "мать", кто
 * поручитель, а кто SPV/эмитент облигаций). Единственный критерий:
 * --spv-inn — ИНН ТОЙ стороны, которой НЕТ в issuers; --issuer-inn/
 * --issuer-id — ТА сторона, что уже ЕСТЬ в issuers (обычно потому, что
 * именно она физически выпускает облигации на бирже, но это не
 * гарантия, а только типичная причина). См. подробности в докблоке
 * IssuerMatcher::findIssuerIdBySpvLink().
 *
 * Реальные найденные случаи (сентябрь 2026): ООО «АгроНэкст» (ИНН
 * 2315190775, в issuers НЕТ) → issuer_id ООО «Русбонд-Удобрения» (ИНН
 * 9725091192, В issuers ЕСТЬ — её облигации на бирже); ООО «ЕВА» (ИНН
 * 7708335695, в issuers НЕТ, хотя именно её рейтингует агентство
 * напрямую) → issuer_id ООО «ФСК Активы» (ИНН 7714482955, В issuers
 * ЕСТЬ — её облигации на бирже, в новости о присвоении рейтинга
 * упоминается только в связи с выпуском). Это не эвристика, а
 * подтверждённый человеком факт, поэтому в порядке приоритета стоит
 * ВЫШЕ сопоставления "по корню".
 *
 * Добавить связку (сторона, что уже есть в issuers — по её ИНН ИЛИ
 * напрямую по issuer_id, одно из двух обязательно):
 *   php bin/link_spv.php --spv-inn=7708335695 --issuer-inn=7714482955 [--spv-name="ООО «ЕВА»"] [--note="..."]
 *   php bin/link_spv.php --spv-inn=7708335695 --issuer-id=123 [--spv-name=...] [--note=...]
 *
 * Посмотреть все текущие связки:
 *   php bin/link_spv.php --list
 *
 * Удалить связку:
 *   php bin/link_spv.php --remove --spv-inn=7708335695
 *
 * Повторный запуск с тем же --spv-inn перезаписывает предыдущую связку
 * (ON DUPLICATE KEY UPDATE) — если ошиблись при вводе (в т.ч. перепутали
 * направление), просто запустите снова с верными данными, отдельно
 * удалять не нужно.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Support\Logger;

$args = [];
$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $args[$key] = $value;
    } elseif (str_starts_with($arg, '--')) {
        $flags[substr($arg, 2)] = true;
    }
}

$db = Database::connection();

if (isset($flags['list'])) {
    $stmt = $db->query(
        'SELECT l.spv_inn, l.spv_name, l.issuer_id, i.short_name, l.note, l.created_at
         FROM issuer_spv_links l
         JOIN issuers i ON i.id = l.issuer_id
         ORDER BY l.created_at DESC'
    );
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        Logger::info('Связок SPV -> материнская компания пока нет.');
        exit(0);
    }
    Logger::info('=== Связки SPV -> материнская компания (' . count($rows) . ') ===');
    foreach ($rows as $row) {
        $spvLabel = $row['spv_name'] !== null ? "{$row['spv_name']} (ИНН={$row['spv_inn']})" : "ИНН={$row['spv_inn']}";
        $noteSuffix = $row['note'] !== null ? " -- {$row['note']}" : '';
        Logger::info("{$spvLabel} -> issuer_id={$row['issuer_id']} ({$row['short_name']}), добавлено {$row['created_at']}{$noteSuffix}");
    }
    exit(0);
}

$spvInn = IssuerMatcher::normalizeInn($args['spv-inn'] ?? null);
if ($spvInn === null) {
    fwrite(STDERR, "Требуется --spv-inn=<ИНН SPV> (10 или 12 цифр). Использование:\n"
        . "  php bin/link_spv.php --spv-inn=... --issuer-inn=... [--spv-name=...] [--note=...]\n"
        . "  php bin/link_spv.php --spv-inn=... --issuer-id=... [--spv-name=...] [--note=...]\n"
        . "  php bin/link_spv.php --list\n"
        . "  php bin/link_spv.php --remove --spv-inn=...\n");
    exit(1);
}

if (isset($flags['remove'])) {
    $stmt = $db->prepare('DELETE FROM issuer_spv_links WHERE spv_inn = :spv_inn');
    $stmt->execute(['spv_inn' => $spvInn]);
    Logger::info($stmt->rowCount() > 0
        ? "Связка для SPV с ИНН={$spvInn} удалена."
        : "Связки для SPV с ИНН={$spvInn} не было -- нечего удалять.");
    exit(0);
}

$matcher = new IssuerMatcher($db);
$issuerId = null;
if (isset($args['issuer-id'])) {
    $issuerId = (int) $args['issuer-id'];
} elseif (isset($args['issuer-inn'])) {
    $issuerId = $matcher->findIssuerIdByInn($args['issuer-inn']);
    if ($issuerId === null) {
        fwrite(STDERR, "Не нашлась материнская компания с ИНН={$args['issuer-inn']} в issuers.\n");
        exit(1);
    }
}
if ($issuerId === null) {
    fwrite(STDERR, "Требуется --issuer-id=<id> ИЛИ --issuer-inn=<ИНН материнской компании>.\n");
    exit(1);
}

// issuer_id должен реально существовать — та же защита от опечаток, что
// уже применяется в ManualRatingsImporter (не доверяем числу вслепую).
$checkStmt = $db->prepare('SELECT short_name FROM issuers WHERE id = :id');
$checkStmt->execute(['id' => $issuerId]);
$issuerRow = $checkStmt->fetch();
if ($issuerRow === false) {
    fwrite(STDERR, "issuer_id={$issuerId} не найден в issuers -- проверьте номер.\n");
    exit(1);
}

$stmt = $db->prepare(
    'INSERT INTO issuer_spv_links (spv_inn, issuer_id, spv_name, note)
     VALUES (:spv_inn, :issuer_id, :spv_name, :note)
     ON DUPLICATE KEY UPDATE
        issuer_id = VALUES(issuer_id),
        spv_name = VALUES(spv_name),
        note = VALUES(note)'
);
$stmt->execute([
    'spv_inn' => $spvInn,
    'issuer_id' => $issuerId,
    'spv_name' => $args['spv-name'] ?? null,
    'note' => $args['note'] ?? null,
]);

Logger::info("Связка добавлена: SPV ИНН={$spvInn} -> issuer_id={$issuerId} ({$issuerRow['short_name']}).");
