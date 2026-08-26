<?php

declare(strict_types=1);

/**
 * Пост-обработка этапа 1: проверка активных блокировок счетов через
 * официальный сервис ФНС service.nalog.ru/bi.do (см.
 * docs/STAGE1_POSTPROCESSING.md и src/Fns/NalogBiClient.php про источник
 * и подтверждённый вживую контракт).
 *
 * НАМЕРЕННО не проверяет весь рынок по умолчанию — сервис не даёт
 * официального API, у него есть капча (эмпирически — примерно на 3-м
 * запросе подряд в одной сессии), и мы ещё не знаем, насколько это
 * реально мешает на большом объёме. Начинаем с маленьких партий.
 *
 * Запуск по конкретным ИНН (первый тест — известные 6 заблокированных):
 *   php bin/check_fns_blocks.php --inns=1101148661,3702151662,7730176955,7805485840,7826108963,9727020246
 *
 * Запуск по списку ИНН из файла (по одному на строку, # — комментарий) —
 * удобнее --inns для длинных списков и для cron, чтобы не редактировать
 * саму строку crontab при изменении списка:
 *   php bin/check_fns_blocks.php --watchlist=config/fns_watchlist.txt
 *
 * Запуск по первым N эмитентам из справочника (по умолчанию N=5):
 *   php bin/check_fns_blocks.php --limit=10
 *
 * Пауза между проверками — 5 секунд по умолчанию, меняется через --delay:
 *   php bin/check_fns_blocks.php --limit=10 --delay=8
 *
 * По расписанию — раз в сутки по watchlist, в 08:00 (сервис ФНС не даёт
 * официального API — только точечный список, не весь рынок, см.
 * docs/STAGE1_POSTPROCESSING.md):
 *   0 8 * * * /usr/bin/php /path/to/bondkeeper/bin/check_fns_blocks.php --watchlist=config/fns_watchlist.txt --delay=8 >> /var/log/bondkeeper/check_fns_blocks.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Fns\FnsBlocksImporter;
use BondKeeper\Fns\NalogBiClient;
use BondKeeper\Support\Logger;

$inns = null;
$limit = 5;
$delaySeconds = 5;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--inns=')) {
        $inns = array_values(array_filter(array_map('trim', explode(',', substr($arg, 7)))));
    } elseif (str_starts_with($arg, '--watchlist=')) {
        $inns = readWatchlist(substr($arg, 12));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    } elseif (str_starts_with($arg, '--delay=')) {
        $delaySeconds = max(1, (int) substr($arg, 8));
    }
}

/** @return string[] */
function readWatchlist(string $path): array
{
    $resolved = is_file($path) ? $path : __DIR__ . '/../' . ltrim($path, '/');
    $lines = file($resolved, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("Не удалось прочитать файл со списком ИНН: {$path}");
    }

    return array_values(array_filter(
        array_map('trim', $lines),
        static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
    ));
}

$db = Database::connection();

if ($inns !== null && $inns !== []) {
    $placeholders = implode(',', array_fill(0, count($inns), '?'));
    $stmt = $db->prepare("SELECT id, inn FROM issuers WHERE inn IN ({$placeholders})");
    $stmt->execute($inns);
} else {
    $stmt = $db->prepare('SELECT id, inn FROM issuers ORDER BY id LIMIT :limit');
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
}
$issuers = $stmt->fetchAll();

Logger::info('Старт: проверка блокировок счетов ФНС для ' . count($issuers) . ' эмитентов'
    . " (пауза между проверками: {$delaySeconds} с)");

$importer = new FnsBlocksImporter(new NalogBiClient(), $db, $delaySeconds);
$importer->checkIssuers($issuers);

Logger::info('Готово.');
