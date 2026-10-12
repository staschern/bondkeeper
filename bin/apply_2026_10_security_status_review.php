<?php

declare(strict_types=1);

/**
 * РАЗОВЫЙ скрипт: статусы бумаг, ушедших с биржи до срока погашения, по
 * разбору от 11 октября 2026. Запускать один раз, после миграции 029 и
 * ДО первого запуска bin/sync_security_status.php. По умолчанию только
 * показывает, что будет сделано; применить — с --apply.
 *
 *   php bin/apply_2026_10_security_status_review.php            # посмотреть
 *   php bin/apply_2026_10_security_status_review.php --apply    # применить
 *
 * Повторный запуск безопасен: уже сделанное пропускается.
 *
 * Зачем отдельный скрипт. До 11.10.2026 статус бумаг не менялся вовсе, и
 * в базе числились активными 25 выпусков, ушедших с биржи раньше срока.
 * По каждому причина разобрана вручную — по сообщениям НРД (выгрузка
 * 18–30.09.2026) и по данным биржи. Ежедневная сверка сообщений НРД за
 * прошлое не видит и без этого списка поставила бы части из них «причина
 * не установлена». Решение пользователя (11.10.2026): где досрочное
 * погашение подтверждено — matured, где причина неизвестна — delisted.
 *
 * Бумаги, погашенные ПО СРОКУ (их 169), сюда не входят: их ставит сама
 * ежедневная сверка — срок прошёл и бумаги нет в списке биржи.
 *
 * У бумаг из списка заодно снимается признак оферты и удаляются строки
 * оферт (SecurityStatus::set).
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Securities\SecurityStatus;
use BondKeeper\Support\Logger;

$apply = in_array('--apply', $argv, true);

/** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> $review ISIN, название, статус, причина, дата окончания, на чём основано */
$review = [
    // Досрочное погашение подтверждено сообщением НРД о деньгах (весь номинал).
    ['RU000A1026H0', 'ГИДРОМАШБ3', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-09-25', 'НРД: досрочное погашение 100 %'],
    ['RU000A10B6K8', 'ТЕХНЛГ 02', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-09-18', 'НРД: досрочное погашение 100 %'],
    ['RU000A10B7N0', 'БиоВит 1Р1', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-09-24', 'НРД: досрочное погашение 100 %, оферта 17.11.2026 отменена'],
    ['RU000A10B925', 'ЕАС 1P2', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-09-30', 'НРД: досрочное погашение 100 %'],
    ['RU000A10AWB0', 'СбCIB1008', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-09-28', 'НРД: досрочное погашение 103,7 %'],
    // НРД: досрочно выплачен остаток номинала после амортизаций.
    ['RU000A0JX3M0', 'ДОМРФИА 1P', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-09-28', 'НРД: досрочно выплачен остаток номинала (3,6 %)'],
    ['RU000A10B7T7', 'ТБ-3_01', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-09-28', 'НРД: досрочно выплачен остаток номинала (9,9 %)'],
    // НРД объявил досрочное погашение на уже прошедшую дату.
    ['RU000A10EW77', 'РКСОлимпA1', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-10-05', 'НРД: объявлено досрочное погашение остатка (16,3 %) на 05.10.2026'],
    ['RU000A0ZYBM4', 'АльфаБО-21', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_NSD, '2026-10-09', 'НРД: объявлено досрочное погашение 100 % на 09.10.2026, оферта 13.10 отменена'],
    // У биржи запись «Оферта/Погашение» по 100 % накануне прекращения торгов.
    ['RU000A0ZZ1M2', 'ПИК БО-П04', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_MOEX, '2026-08-20', 'биржа: «Оферта/Погашение» по 100 % на 20.08.2026'],
    ['RU000A100SZ3', 'ПочтаРБ1P6', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_MOEX, '2026-08-27', 'биржа: «Оферта/Погашение» по 100 % на 27.08.2026'],
    ['RU000A1021G3', 'РВК-Ин1Р01', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_MOEX, '2026-08-11', 'биржа: «Оферта/Погашение» по 100 % на 11.08.2026'],
    ['RU000A103KJ8', 'Европлн1Р3', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_MOEX, '2026-08-14', 'биржа: «Оферта/Погашение» по 100 % на 14.08.2026'],
    ['RU000A107TB7', 'Боржоми1Р2', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_MOEX, '2026-08-18', 'биржа: «Оферта/Погашение» по 100 % на 18.08.2026'],
    ['RU000A10AYB6', 'АСпэйс 1Р2', SecurityStatus::MATURED, SecurityStatus::REASON_EARLY_MOEX, '2026-08-17', 'биржа: «Оферта/Погашение» по 100 % на 17.08.2026'],
    // Известен только факт: торги прекращены до срока. Дата — последний день торгов.
    ['RU000A10AGA5', 'Капитал1Р2', SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, '2026-09-18', 'торги прекращены, причина не подтверждена'],
    ['RU000A100UT2', 'ПочтаРБ1P8', SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, '2026-09-16', 'торги прекращены, причина не подтверждена'],
    ['RU000A107WL0', 'ИКС5Фин3P4', SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, '2026-09-09', 'торги прекращены, причина не подтверждена'],
    ['RU000A10FXR3', 'МаниКап1Р4', SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, '2026-09-09', 'торги прекращены, причина не подтверждена'],
    ['RU000A10FPJ6', 'ВТБ Б1-419', SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, '2026-09-29', 'торги прекращены, причина не подтверждена'],
    ['RU000A109V13', 'СберСIB943', SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, '2026-08-24', 'структурная нота; торги прекращены, причина не подтверждена'],
    ['RU000A10BKY5', 'СбCIB1032', SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, '2026-08-13', 'структурная нота; торги прекращены, причина не подтверждена'],
    ['RU000A10F2D8', 'СбКИБ1P640', SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, '2026-09-25', 'структурная нота; торги прекращены, причина не подтверждена'],
    ['RU000A10EZ58', 'СбКИБ1P535', SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, '2026-09-30', 'структурная нота; торги прекращены, причина не подтверждена'],
    ['RU000A10BZ28', 'Румбр1P43', SecurityStatus::DELISTED, SecurityStatus::REASON_LEFT_UNKNOWN, '2026-10-05', 'структурная нота; торги прекращены, причина не подтверждена'],
];

$db = Database::connection();
$status = new SecurityStatus($db);
$find = $db->prepare('SELECT id, status, short_name FROM securities WHERE isin = :isin');

Logger::info($apply ? '=== ПРИМЕНЕНИЕ разбора статусов от 11.10.2026 ===' : '=== ПРОСМОТР: ничего не меняется, для применения запустите с --apply ===');
$changed = 0;
$skipped = 0;
foreach ($review as [$isin, $name, $newStatus, $reason, $endedAt, $basis]) {
    $find->execute(['isin' => $isin]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        Logger::warn("{$isin} {$name}: в базе нет — пропущено.");
        $skipped++;
        continue;
    }
    if (!SecurityStatus::inCirculation((string) $row['status'])) {
        Logger::info("{$isin} {$name}: уже {$row['status']} — пропущено.");
        $skipped++;
        continue;
    }
    Logger::info("{$isin} {$name}: {$row['status']} → {$newStatus}, дата окончания {$endedAt} ({$basis})");
    if ($apply) {
        $status->set((int) $row['id'], $newStatus, $reason, $endedAt);
    }
    $changed++;
}
Logger::info(($apply ? 'Изменено' : 'Будет изменено') . ": {$changed}; пропущено: {$skipped}; всего в разборе: " . count($review));
