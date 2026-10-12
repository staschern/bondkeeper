<?php

declare(strict_types=1);

/**
 * Оферты: привести таблицу offers и признак securities.has_offer к тому,
 * что биржа показывает на сегодня (переписано 11.10.2026, docs/OFFERS.md).
 *
 * Берёт два списка биржи — торгуемые облигации и все предстоящие оферты
 * рынка (около 8 запросов вместо обхода каждой бумаги) — и одной
 * транзакцией добавляет новые оферты, обновляет изменившиеся и удаляет
 * прошедшие, исчезнувшие и оферты бумаг, которые больше не торгуются.
 * Если ответ биржи выглядит неполным, ничего не меняет и сообщает
 * разработчику.
 *
 * Запуск:
 *   php bin/seed_offers.php              # по расписанию
 *   php bin/seed_offers.php --dry-run    # только показать, что изменится
 *   php bin/seed_offers.php --dry-run --list   # то же и поимённый список изменений
 *
 * По расписанию — вслед за sync_security_status.php, seed_market.php и
 * seed_bondization.php (строка крона прежняя):
 *   0 4 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_offers.php >> /var/log/bondkeeper/seed_offers.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Iss\IssClient;
use BondKeeper\Iss\OffersImporter;
use BondKeeper\Support\CronGuard;
use BondKeeper\Support\Logger;

$dryRun = in_array('--dry-run', $argv, true);
$list = in_array('--list', $argv, true);
CronGuard::install('seed_offers', !$dryRun);
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
foreach ($argv as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $today = $m[1];
    }
}

$prefix = $dryRun ? '=== ПРОСМОТР (ничего не изменено) === ' : '';
Logger::info($prefix . "Оферты на {$today}: сверка таблицы offers с биржей");

$db = Database::connection();
$problem = OffersImporter::schemaProblem($db);
if ($problem !== null) {
    throw new RuntimeException($problem);
}
$report = (new OffersImporter(new IssClient(), $db))->rebuild($today, !$dryRun);

Logger::info("Торгуемых облигаций у биржи: {$report['market']}; предстоящих оферт у биржи: {$report['exchange_rows']}");
foreach ($report['skipped'] as $reason => $count) {
    if ($count > 0) {
        Logger::info("  не взято — {$reason}: {$count}");
    }
}
$verb = $dryRun ? 'будет' : 'стало';
Logger::info("Строк в offers: было {$report['before']}, {$verb} {$report['after']} — добавлено " . count($report['added'])
    . ", обновлено {$report['updated']}, удалено " . count($report['removed']));
$reasons = array_count_values(array_column($report['removed'], 'reason'));
foreach ($reasons as $reason => $count) {
    Logger::info("  удалено — {$reason}: {$count}");
}
Logger::info("Виды оферт: put — {$report['by_type']['put']}, call — {$report['by_type']['call']}"
    . (($report['by_type']['unknown'] ?? 0) > 0 ? ", unknown — {$report['by_type']['unknown']}" : ''));
Logger::info("Бумаг с признаком оферты: было {$report['flag_before']}, {$verb} {$report['flag_after']} — признак поставлен у "
    . count($report['flag_on']) . ', снят у ' . count($report['flag_off']));

if ($list) {
    $typeNames = ['put' => 'пут', 'call' => 'колл', 'unknown' => 'вид не определён'];
    foreach ($report['added'] as $item) {
        Logger::info("  + {$item['name']} ({$item['isin']}): {$typeNames[$item['type']]} {$item['date']}");
    }
    foreach ($report['removed'] as $item) {
        Logger::info("  − {$item['name']} ({$item['isin']}): {$typeNames[$item['type']]} {$item['date']} — {$item['reason']}");
    }
    foreach ($report['flag_on'] as $item) {
        Logger::info("  признак поставлен: {$item['name']} ({$item['isin']})");
    }
    foreach ($report['flag_off'] as $item) {
        Logger::info("  признак снят: {$item['name']} ({$item['isin']})");
    }
}

Logger::info($dryRun ? 'Ничего не изменено (--dry-run).' : 'Готово.');
