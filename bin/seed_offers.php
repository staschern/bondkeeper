<?php

declare(strict_types=1);

/**
 * Постобработка этапа 1: наполнить offers через доска-специфичный эндпоинт
 * ISS API (/engines/stock/markets/bonds/boards/{board}/securities/{secid}.json),
 * который до этого в проекте нигде не запрашивался. Опирается на уже
 * загруженные securities.secid/moex_board — запускать после seed_market.php.
 *
 * Строка в offers создаётся, если есть хотя бы один из двух сигналов
 * (BUYBACKDATE или OFFERDATE) — объединение множеств, не пересечение.
 * По каждому запуску в лог попадает честная разбивка: сколько бумаг имеют
 * оба сигнала, сколько только один из двух, и на скольких удалось
 * определить вид оферты (put/call) по PUTOPTIONDATE/CALLOPTIONDATE.
 *
 * Запуск:
 *   php bin/seed_offers.php
 *
 * По расписанию — вслед за seed_market.php и seed_bondization.php:
 *   0 4 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_offers.php >> /var/log/bondkeeper/seed_offers.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Iss\IssClient;
use BondKeeper\Iss\OffersImporter;
use BondKeeper\Support\Logger;

Logger::info('Старт: сидирование оферт (offers) из ISS API MOEX');

$importer = new OffersImporter(new IssClient(), Database::connection());
$importer->importForAllActive();

Logger::info('Готово.');
