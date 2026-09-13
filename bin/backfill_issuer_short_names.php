<?php

declare(strict_types=1);

/**
 * Разовая пересборка issuers.short_name из full_name эвристическим
 * парсером (IssuerNameShortener) — нужна ОДИН РАЗ для строк, уже
 * накопленных ДО того, как SecuritiesImporter::resolveOrCreateIssuer()
 * стал сокращать short_name сам. Дальше это не нужно — новые/обновлённые
 * строки сокращаются на лету при каждом обычном прогоне seed_market.php.
 *
 * Присланный вместе с IssuerNameShortener пакет предполагал, что этот
 * скрипт не нужен вообще ("проект ни разу не запускался на боевой
 * MySQL") — на практике это устарело: seed_market.php уже давно крутится
 * в проде по расписанию, и issuers успела накопить реальные строки, где
 * short_name буквально равен full_name (старое поведение). Без этого
 * скрипта они так и остались бы длинными до следующего изменения самого
 * full_name у конкретного эмитента (что бывает редко/никогда).
 *
 * Трогает СТРОГО те строки, где short_name === full_name (то есть ещё
 * не сокращённые этим или каким-либо другим путём) — если когда-нибудь
 * short_name у кого-то поправят вручную, повторный прогон это не
 * перезапишет. Безопасно перезапускать многократно (идемпотентно):
 * уже сокращённые строки перестают удовлетворять условию WHERE.
 *
 * Запуск:
 *   php bin/backfill_issuer_short_names.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Iss\IssuerNameShortener;
use BondKeeper\Support\Logger;

$db = Database::connection();

$rows = $db->query('SELECT id, full_name FROM issuers WHERE short_name = full_name')->fetchAll();
Logger::info('Строк с short_name = full_name (кандидаты на сокращение): ' . count($rows));

$stmt = $db->prepare('UPDATE issuers SET short_name = :short_name WHERE id = :id');

$updated = 0;
$unrecognized = 0;
foreach ($rows as $row) {
    $shortened = IssuerNameShortener::shorten($row['full_name']);
    if ($shortened === $row['full_name']) {
        // Не гадаем — ОПФ не узнана (иностранная SPV, служебная заглушка
        // и т.п.), см. докблок IssuerNameShortener. Не считается ошибкой.
        $unrecognized++;
        continue;
    }

    $stmt->execute(['short_name' => $shortened, 'id' => $row['id']]);
    $updated++;
    Logger::info("id={$row['id']}: «{$row['full_name']}» -> «{$shortened}»");
}

Logger::info('=== Отчёт по пересборке issuers.short_name ===');
Logger::info("Обновлено: {$updated}");
Logger::info("ОПФ не узнана, оставлено как было: {$unrecognized}");
Logger::info('Готово.');
