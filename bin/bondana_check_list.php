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
 * Выгружает не CSV/txt, а сразу готовый массив LIST — ровно то, что ждёт
 * bondana_offers_check.js (строка `const LIST = [...]`, формат [ISIN,
 * наша дата погашения или '']): скрипт получает ISIN не из отдельного
 * файла, а как JS-литерал, вставленный прямо в его код. Выгрузка
 * сохраняется в var/bondana_check_list.js — открыть, скопировать
 * содержимое, вставить вместо строки `const LIST = [...]` в
 * bondana_offers_check.js, запустить в консоли браузера на bondana.app.
 *
 *   php bin/bondana_check_list.php
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Securities\SecurityStatus;
use BondKeeper\Support\Logger;

const OUT_FILE = __DIR__ . '/../var/bondana_check_list.js';

$db = Database::connection();
$rows = $db->query(
    "SELECT s.isin, s.maturity_date
     FROM securities s
     WHERE s.bondana_checked_at IS NULL
       AND s.status IN (" . SecurityStatus::inCirculationSql() . ")
       AND NOT EXISTS (
           SELECT 1 FROM offers o
           WHERE o.security_id = s.id AND o.offer_type = 'call' AND o.source = 'nsd'
       )
     ORDER BY s.isin"
)->fetchAll();

if ($rows === []) {
    Logger::info('Новых бумаг на проверку по Bondana нет — список не создан.');
    exit(0);
}

$items = array_map(
    static fn (array $r): string => '[' . json_encode((string) $r['isin'], JSON_UNESCAPED_UNICODE) . ',' . json_encode((string) ($r['maturity_date'] ?? '')) . ']',
    $rows,
);

$js = "const LIST = [\n" . implode(",\n", $items) . "\n];\n";

if (!is_dir(dirname(OUT_FILE))) {
    mkdir(dirname(OUT_FILE), 0775, true);
}
file_put_contents(OUT_FILE, $js);

Logger::info('Бумаг на проверку по Bondana: ' . count($rows) . ' — записано в ' . OUT_FILE);
Logger::info('Вставить содержимое файла вместо строки "const LIST = [...]" в bondana_offers_check.js и запустить в консоли браузера на bondana.app.');
