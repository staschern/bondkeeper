<?php

declare(strict_types=1);

/**
 * Шаг 1 дорожной карты (продолжение): наполнить coupons/amortizations
 * графиком выплат через bondization-эндпоинт ISS API. Запускать после
 * seed_market.php — опирается на уже загруженный справочник securities.
 *
 * Запуск:
 *   php bin/seed_bondization.php
 *
 * По расписанию — тоже раз в сутки, вслед за seed_market.php:
 *   30 3 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_bondization.php >> /var/log/bondkeeper/seed_bondization.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Iss\BondizationImporter;
use BondKeeper\Iss\IssClient;
use BondKeeper\Support\Logger;

Logger::info('Старт: сидирование графика выплат (coupons/amortizations) из ISS API MOEX');

$importer = new BondizationImporter(new IssClient(), Database::connection());
$importer->importForAllPending();

Logger::info('Готово.');
