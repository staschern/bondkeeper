<?php

declare(strict_types=1);

/**
 * Шаг 1 дорожной карты: наполнить issuers/securities/redemptions данными
 * из бесплатного ISS API Мосбиржи.
 *
 * Запуск:
 *   php bin/seed_market.php
 *   php bin/seed_market.php --boards=TQRD,TQOD,TQOY   # только названные доски (без ключа — все, см. SecuritiesImporter::DEFAULT_BOARDS)
 *
 * По расписанию (раз в сутки, справочник меняется редко — см. doc "Синтезировал
 * архитектуру..." из documents/):
 *   0 3 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_market.php >> /var/log/bondkeeper/seed_market.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Iss\IssClient;
use BondKeeper\Iss\SecuritiesImporter;
use BondKeeper\Support\Logger;

$boards = SecuritiesImporter::DEFAULT_BOARDS;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--boards=')) {
        $boards = array_values(array_filter(array_map('trim', explode(',', strtoupper(substr($arg, 9))))));
        if ($boards === [] || array_filter($boards, static fn (string $b): bool => preg_match('/^[A-Z0-9]{2,8}$/', $b) !== 1) !== []) {
            fwrite(STDERR, "Неверный ключ --boards: ожидается список досок через запятую, например --boards=TQRD,TQOD\n");
            exit(1);
        }
    } else {
        // Не останавливаемся: раньше скрипт ключей не разбирал вовсе, и запуск по расписанию из-за лишнего слова падать не должен.
        Logger::warn("Неизвестный ключ пропущен: {$arg}");
    }
}

Logger::info('Старт: сидирование справочника issuers/securities из ISS API MOEX');

$importer = new SecuritiesImporter(new IssClient(), Database::connection());
$importer->importMarket($boards);

Logger::info('Готово.');
