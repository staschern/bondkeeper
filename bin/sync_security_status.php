<?php

declare(strict_types=1);

/**
 * Ежедневная сверка статусов бумаг со списком торгуемых облигаций биржи
 * (docs/SECURITY_STATUS.md). Что делает — см. BondKeeper\Iss\SecurityStatusSync:
 * погашенные по сроку получают matured, ушедшие с биржи до срока — matured
 * (если у биржи есть запись о досрочном погашении) или delisted,
 * переведённые в режим «Облигации Д» — tech_default.
 *
 *   php bin/sync_security_status.php                    # обычный запуск
 *   php bin/sync_security_status.php --dry-run          # показать, что изменится, ничего не меняя
 *   php bin/sync_security_status.php --date=2026-10-12  # считать «сегодня» этой датой
 *
 * По расписанию — раз в сутки, ДО загрузки графика и оферт (они берут
 * только бумаги в обращении). Рядом с остальными ночными заданиями:
 *   50 2 * * * /usr/bin/php /path/to/bondkeeper/bin/sync_security_status.php >> /path/to/bondkeeper/var/log/sync_security_status.log 2>&1
 *
 * Итог — администратору в Telegram, если что-то изменилось. Если скрипт
 * упал или список биржи пришёл неполным — сообщение разработчику
 * (CronGuard), статусы при этом не меняются.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Iss\IssClient;
use BondKeeper\Iss\SecurityStatusSync;
use BondKeeper\Securities\SecurityStatus;
use BondKeeper\Support\CronGuard;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\AdminNotifier;

$dryRun = in_array('--dry-run', $argv, true);
CronGuard::install('sync_security_status', !$dryRun);

$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
foreach ($argv as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $today = $m[1];
    }
}

$db = Database::connection();
$sync = new SecurityStatusSync(new IssClient(), $db, new SecurityStatus($db));
$report = $sync->run($today, !$dryRun);

$date = static fn (string $d): string => $d === '' ? '—' : date('d.m.Y', (int) strtotime($d));
$groups = [
    'matured' => ['Погашены по сроку', static fn (array $i): string => "{$i['name']} ({$i['isin']}), срок {$date($i['ended_at'])}"],
    'early' => ['Погашены досрочно (запись биржи «Оферта/Погашение»)', static fn (array $i): string => "{$i['name']} ({$i['isin']}), погашена {$date($i['ended_at'])}, срок был {$date($i['maturity'])}"],
    'delisted' => ['Ушли с биржи до срока, причина не установлена — статус «снята с торгов»', static fn (array $i): string => "{$i['name']} ({$i['isin']}), последний день {$date($i['ended_at'])}, срок был {$date($i['maturity'])}"],
    'board_d' => ['Биржа перевела в режим «Облигации Д» — статус «техдефолт»', static fn (array $i): string => "{$i['name']} ({$i['isin']})"],
    'restored' => ['Вернулись в обращение', static fn (array $i): string => "{$i['name']} ({$i['isin']}): {$i['note']}"],
    'gone_today' => ['Пропали из списка биржи до срока погашения (признак оферты снят; статус сменится через ' . SecurityStatusSync::GRACE_DAYS . ' дн., если не вернутся)',
        static fn (array $i): string => "{$i['name']} ({$i['isin']}), срок {$date($i['maturity'])}"],
];

Logger::info(($dryRun ? '=== ПРОСМОТР (ничего не меняется) === ' : '') . "Статусы бумаг на {$today}: в списке биржи {$report['market']} бумаг, наших в обращении проверено {$report['checked']}");
$message = [];
foreach ($groups as $key => [$title, $line]) {
    $items = $report[$key];
    if ($items === []) {
        continue;
    }
    Logger::info("{$title}: " . count($items));
    foreach ($items as $item) {
        Logger::info('  ' . $line($item));
    }
    $message[] = "{$title} — " . count($items) . ':';
    foreach (array_slice($items, 0, 15) as $item) {
        $message[] = '• ' . $line($item);
    }
    if (count($items) > 15) {
        $message[] = '… и ещё ' . (count($items) - 15) . ' (полный список — в логе)';
    }
    $message[] = '';
}
if ($report['waiting'] !== []) {
    Logger::info('Ждут смены статуса (нет в списке биржи меньше ' . SecurityStatusSync::GRACE_DAYS . ' дн.): ' . count($report['waiting']));
}
if ($message === []) {
    Logger::info('Изменений нет.');
} elseif (!$dryRun) {
    AdminNotifier::sendLines(array_merge(['📋 Статусы бумаг на ' . $date($today), ''], $message));
}
