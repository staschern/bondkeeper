<?php

declare(strict_types=1);

/**
 * РАЗОВЫЙ скрипт: коллы, которых нет у биржи, но есть у Bondana — по
 * сверке от 18 октября 2026 (docs/OFFERS.md). По умолчанию только
 * показывает, что будет сделано; применить — с --apply.
 *
 *   php bin/apply_2026_10_bondana_calls.php --file=var/offers_bondana_final_comparison.csv            # посмотреть
 *   php bin/apply_2026_10_bondana_calls.php --file=var/offers_bondana_final_comparison.csv --apply    # применить
 *
 * Повторный запуск безопасен: оферта, которая уже есть (та же бумага,
 * дата, вид), пропускается.
 *
 * Зачем отдельный скрипт. Сверка показала: по путам наш источник (биржа +
 * приоритет объявлений НРД) совпадает с Bondana почти везде (404
 * «совпало» против 13 расхождений) — путы брать с биржи и дальше. А по
 * коллам биржевая эвристика «Оферта/Погашение раньше срока» почти не
 * работает: только 3 «совпало» и 2 «только у нас» против 396 «колл
 * только у Bondana» (решение пользователя, 19.10.2026). Коллы из этого
 * списка заводятся в offers вручную, одним разовым разбором, с
 * source='file' (зарезервировано под это миграцией 030). Дальнейшая
 * периодическая сверка с Bondana — отдельная задача, не этот скрипт.
 *
 * Файл — тот же `offers_bondana_final_comparison.csv`, что и в отчёте
 * администратору (разделитель «;», кодировка UTF-8, может быть с BOM).
 * Берутся только строки с call_verdict = «колл только у Bondana»; из
 * bondana_call_dates — все даты через запятую (многие бумаги — вечные
 * субординированные облигации с колл-опционом каждый год).
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Securities\SecurityStatus;
use BondKeeper\Support\Logger;

$apply = in_array('--apply', $argv, true);
$file = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    }
}
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "Укажите --file=путь/к/offers_bondana_final_comparison.csv\n");
    exit(1);
}

$handle = fopen($file, 'r');
if ($handle === false) {
    fwrite(STDERR, "Не удалось открыть {$file}\n");
    exit(1);
}
$header = fgetcsv($handle, 0, ';');
if ($header === false) {
    fwrite(STDERR, "Пустой файл: {$file}\n");
    exit(1);
}
// BOM в начале первой колонки (Excel) — срезаем, иначе isin не найдётся по имени.
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
$required = ['isin', 'name', 'call_verdict', 'bondana_call_dates'];
$missing = array_diff($required, $header);
if ($missing !== []) {
    fwrite(STDERR, 'В файле нет колонок: ' . implode(', ', $missing) . "\n");
    exit(1);
}

$db = Database::connection();
$findSecurity = $db->prepare('SELECT id, issuer_id, short_name, status FROM securities WHERE isin = :isin');
$findOffer = $db->prepare("SELECT 1 FROM offers WHERE security_id = :security_id AND execution_date_planned = :date AND offer_type = 'call'");
$insert = $db->prepare(
    "INSERT INTO offers (security_id, issuer_id, offer_type, source, execution_date_planned, status)
     VALUES (:security_id, :issuer_id, 'call', 'file', :date, 'announced')"
);
$setFlag = $db->prepare('UPDATE securities SET has_offer = 1 WHERE id = :id AND has_offer <> 1');

$bondsTotal = 0;
$bondsNotFound = 0;
$bondsNotInCirculation = 0;
$bondsChanged = 0;
$datesAdded = 0;
$datesAlready = 0;

Logger::info($apply ? '=== ПРИМЕНЕНИЕ разбора коллов от Bondana (18.10.2026) ===' : '=== ПРОСМОТР: ничего не меняется, для применения запустите с --apply ===');

if ($apply) {
    $db->beginTransaction();
}
try {
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $record = array_combine($header, $row);
        if ($record === false || ($record['call_verdict'] ?? '') !== 'колл только у Bondana') {
            continue;
        }
        $bondsTotal++;
        $isin = trim((string) $record['isin']);
        $name = trim((string) $record['name']);

        $findSecurity->execute(['isin' => $isin]);
        $security = $findSecurity->fetch();
        if ($security === false) {
            Logger::warn("{$isin} {$name}: в базе нет — пропущено.");
            $bondsNotFound++;
            continue;
        }
        if (!SecurityStatus::inCirculation((string) $security['status'])) {
            Logger::warn("{$isin} {$name}: статус {$security['status']}, не в обращении — пропущено.");
            $bondsNotInCirculation++;
            continue;
        }

        $securityId = (int) $security['id'];
        $added = [];
        $already = [];
        foreach (explode(',', (string) $record['bondana_call_dates']) as $date) {
            $date = trim($date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                Logger::warn("{$isin} {$name}: «{$date}» не похоже на дату — пропущено.");
                continue;
            }
            $findOffer->execute(['security_id' => $securityId, 'date' => $date]);
            if ($findOffer->fetchColumn() !== false) {
                $already[] = $date;
                continue;
            }
            $added[] = $date;
            if ($apply) {
                $insert->execute(['security_id' => $securityId, 'issuer_id' => (int) $security['issuer_id'], 'date' => $date]);
            }
        }

        $datesAdded += count($added);
        $datesAlready += count($already);
        if ($added !== []) {
            $bondsChanged++;
            if ($apply) {
                $setFlag->execute(['id' => $securityId]);
            }
            Logger::info("{$isin} {$security['short_name']}: " . ($apply ? 'добавлено' : 'будет добавлено') . ' ' . count($added)
                . ' дат(а) колла: ' . implode(', ', array_map(static fn (string $d): string => date('d.m.Y', (int) strtotime($d)), $added))
                . ($already !== [] ? '; уже было: ' . count($already) : ''));
        }
    }
    if ($apply) {
        $db->commit();
    }
} catch (\Throwable $e) {
    if ($apply) {
        $db->rollBack();
    }
    throw $e;
}
fclose($handle);

Logger::info(sprintf(
    'Бумаг с коллом от Bondana: %d; %s: %d; дат %s: %d; уже было: %d; бумаг не найдено в базе: %d; бумаг не в обращении: %d',
    $bondsTotal,
    $apply ? 'изменено' : 'будет изменено',
    $bondsChanged,
    $apply ? 'добавлено' : 'будет добавлено',
    $datesAdded,
    $datesAlready,
    $bondsNotFound,
    $bondsNotInCirculation,
));
