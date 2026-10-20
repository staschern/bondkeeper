<?php

declare(strict_types=1);

/**
 * Список бумаг на проверку по Bondana (миграция 035, docs/OFFERS.md):
 * только бумаги, которые ещё НИ РАЗУ не проверялись
 * (securities.bondana_checked_at IS NULL) — сплошная сверка рынка сделана
 * один раз, 18.10.2026, и не повторяется; дальше под проверку попадают
 * только новые бумаги, появившиеся после нее (seed_market.php).
 *
 * Решение пользователя, 20.10.2026: приоритет по офертам — НРД выше
 * всего (и по путам, и по коллам); если НРД ничего не объявил — пут
 * берём только с биржи (Bondana не нужна), а колл сверяем по Bondana
 * (биржевая эвристика для колла ненадёжна, см. docs/OFFERS.md, раздел про
 * сверку от 18.10.2026). Поэтому в список НЕ попадают бумаги, по которым
 * НРД уже объявил колл (offers: offer_type='call', source='nsd') — для
 * них Bondana не нужна; наличие/отсутствие пута на решение не влияет.
 *
 * Выгружает по одному ISIN на строку, разбитыми на части по CHUNK_SIZE
 * штук — ровно формат, который ждёт run_part.sh/bondana_offers_runner.php
 * (файлы no_offer_part1.txt, no_offer_part2.txt, ...). Части складываются
 * в var/bondana/ — оттуда их нужно скопировать в папку раннера рядом с
 * run_part.sh и запустить, например:
 *
 *   nohup bash run_part.sh 1 2 3 >> out/nohup.log 2>&1 &
 *
 *   php bin/bondana_check_list.php
 *   php bin/bondana_check_list.php --chunk=300
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Securities\SecurityStatus;
use BondKeeper\Support\Logger;

const OUT_DIR = __DIR__ . '/../var/bondana';
const DEFAULT_CHUNK_SIZE = 500;

$chunkSize = DEFAULT_CHUNK_SIZE;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--chunk=')) {
        $chunkSize = (int) substr($arg, 8);
    }
}
if ($chunkSize < 1) {
    fwrite(STDERR, "--chunk должен быть положительным числом\n");
    exit(1);
}

$db = Database::connection();
$isins = $db->query(
    "SELECT s.isin
     FROM securities s
     WHERE s.bondana_checked_at IS NULL
       AND s.status IN (" . SecurityStatus::inCirculationSql() . ")
       AND NOT EXISTS (
           SELECT 1 FROM offers o
           WHERE o.security_id = s.id AND o.offer_type = 'call' AND o.source = 'nsd'
       )
     ORDER BY s.isin"
)->fetchAll(\PDO::FETCH_COLUMN);

if ($isins === []) {
    Logger::info('Новых бумаг на проверку по Bondana нет — список не создан.');
    exit(0);
}

if (!is_dir(OUT_DIR)) {
    mkdir(OUT_DIR, 0775, true);
}

$parts = array_chunk($isins, $chunkSize);
foreach ($parts as $i => $part) {
    $file = OUT_DIR . '/no_offer_part' . ($i + 1) . '.txt';
    file_put_contents($file, implode("\n", $part) . "\n");
}

Logger::info(sprintf(
    'Бумаг на проверку по Bondana: %d — записано в %d файл(ов) по %d в %s',
    count($isins),
    count($parts),
    $chunkSize,
    OUT_DIR,
));
Logger::info('Скопировать no_offer_part*.txt в папку раннера и запустить run_part.sh с нужными номерами частей.');
