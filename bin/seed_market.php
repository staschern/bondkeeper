<?php

declare(strict_types=1);

/**
 * Шаг 1 дорожной карты: наполнить issuers/securities/redemptions данными
 * из бесплатного ISS API Мосбиржи.
 *
 * Запуск:
 *   php bin/seed_market.php
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

Logger::info('Старт: сидирование справочника issuers/securities из ISS API MOEX');

$importer = new SecuritiesImporter(new IssClient(), Database::connection());
$importer->importMarket();

Logger::info('Готово.');
