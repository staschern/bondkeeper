<?php

declare(strict_types=1);

/**
 * Шаг 1 дорожной карты (продолжение): наполнить coupons/amortizations
 * графиком выплат через bondization-эндпоинт ISS API. Запускать после
 * seed_market.php — опирается на уже загруженный справочник securities.
 *
 * Запуск (обычный, только бумаги без ещё загруженного графика):
 *   php bin/seed_bondization.php
 *
 * Запуск с полным пересевом (переписывает график ВСЕМ активным бумагам,
 * а не только новым) — нужен разово после правки маппинга полей:
 *   php bin/seed_bondization.php --force
 *
 * По расписанию — тоже раз в сутки, вслед за seed_market.php, обычным
 * (не --force) режимом:
 *   30 3 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_bondization.php >> /var/log/bondkeeper/seed_bondization.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Iss\BondizationImporter;
use BondKeeper\Iss\IssClient;
use BondKeeper\Support\Logger;

$forceAll = in_array('--force', $argv, true);

Logger::info('Старт: сидирование графика выплат (coupons/amortizations) из ISS API MOEX'
    . ($forceAll ? ' [--force: полный пересев]' : ''));

$importer = new BondizationImporter(new IssClient(), Database::connection());
$importer->importForAllPending($forceAll);

Logger::info('Готово.');
