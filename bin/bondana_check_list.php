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
 * Выгружает по одному ISIN на строку в помесячный файл
 * parser_offer/bondana_ГГГГММ.txt (месяц и год — текущие на момент
 * запуска скрипта; каталог — рядом с bin/, т.е.
 * /var/www/www-root/data/bondkeeper-app/parser_offer/ на сервере). Если
 * файл за этот месяц уже существует — новые ISIN дописываются в конец
 * без повторов, уже имеющиеся в файле не трогаются. Раннер запускается
 * из этого же каталога тем же именем файла:
 *
 *   nohup php bondana_offers_runner.php run --isins=bondana_202610.txt >> out/nohup.log 2>&1 &
 *
 *   php bin/bondana_check_list.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Securities\SecurityStatus;
use BondKeeper\Support\Logger;

const OUT_DIR = __DIR__ . '/../parser_offer';

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

if (!is_dir(OUT_DIR) && !mkdir(OUT_DIR, 0775, true) && !is_dir(OUT_DIR)) {
    fwrite(STDERR, "Не удалось создать каталог " . OUT_DIR . "\n");
    exit(1);
}

$file = OUT_DIR . '/bondana_' . date('Ym') . '.txt';
$existing = is_file($file)
    ? array_flip(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
    : [];

$new = array_values(array_diff($isins, array_keys($existing)));

if ($new === []) {
    Logger::info('Все ' . count($isins) . " бумаг уже есть в {$file} — ничего не добавлено.");
    exit(0);
}

$handle = fopen($file, 'a');
if ($handle === false) {
    fwrite(STDERR, "Не удалось открыть на запись {$file}\n");
    exit(1);
}
foreach ($new as $isin) {
    fwrite($handle, $isin . "\n");
}
fclose($handle);

Logger::info(sprintf(
    'Бумаг на проверку по Bondana: %d, из них новых: %d (уже было в файле: %d) — записано в %s',
    count($isins),
    count($new),
    count($isins) - count($new),
    $file,
));
