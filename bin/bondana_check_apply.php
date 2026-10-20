<?php

declare(strict_types=1);

/**
 * Применяет результат проверки по Bondana (CSV из bondana_offers_check.js,
 * см. bin/bondana_check_list.php) — только НОВЫЕ бумаги, проверенные
 * впервые (решения пользователя, 19–20.10.2026, docs/OFFERS.md):
 *
 *   - колл из Bondana заводится в offers (source='file'), если НРД по
 *     этой бумаге колл ещё не объявлял (приоритет НРД — даже если
 *     bin/bondana_check_list.php уже отсеивал такие бумаги на экспорте,
 *     за время проверки в браузере НРД мог объявить колл только что —
 *     перепроверяем на входе);
 *   - путы из Bondana НЕ берутся (решение пользователя: пут — только с
 *     биржи, см. OffersImporter);
 *   - securities.bondana_checked_at проставляется, когда по бумаге
 *     получен полный ответ — карточка открылась и график раскрыт до
 *     конца (или бумага в Bondana не найдена вовсе — это тоже
 *     законченный результат). Карточка не открылась или график раскрыт
 *     не до конца — бумага остаётся непроверенной и попадёт в следующую
 *     выгрузку bin/bondana_check_list.php.
 *
 *   php bin/bondana_check_apply.php --file=var/bondana_offers_2026-10-25.csv            # посмотреть
 *   php bin/bondana_check_apply.php --file=var/bondana_offers_2026-10-25.csv --apply    # применить
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
    fwrite(STDERR, "Укажите --file=путь/к/bondana_offers_ДАТА.csv\n");
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
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
$required = ['isin', 'found_in_bondana', 'card_opened', 'call_dates', 'note'];
$missing = array_diff($required, $header);
if ($missing !== []) {
    fwrite(STDERR, 'В файле нет колонок: ' . implode(', ', $missing) . "\n");
    exit(1);
}

$db = Database::connection();
$findSecurity = $db->prepare('SELECT id, issuer_id, short_name, status FROM securities WHERE isin = :isin');
$hasNsdCall = $db->prepare("SELECT 1 FROM offers WHERE security_id = :id AND offer_type = 'call' AND source = 'nsd'");
$findOffer = $db->prepare("SELECT 1 FROM offers WHERE security_id = :security_id AND execution_date_planned = :date AND offer_type = 'call'");
$insert = $db->prepare(
    "INSERT INTO offers (security_id, issuer_id, offer_type, source, execution_date_planned, status)
     VALUES (:security_id, :issuer_id, 'call', 'file', :date, 'announced')"
);
$setFlag = $db->prepare('UPDATE securities SET has_offer = 1 WHERE id = :id AND has_offer <> 1');
$markChecked = $db->prepare('UPDATE securities SET bondana_checked_at = :now WHERE id = :id');

$notFound = 0;
$notInCirculation = 0;
$nsdAlreadyHasCall = 0;
$incomplete = 0;
$checked = 0;
$bondsWithCall = 0;
$datesAdded = 0;
$datesAlready = 0;

Logger::info($apply ? '=== ПРИМЕНЕНИЕ результата проверки Bondana ===' : '=== ПРОСМОТР: ничего не меняется, для применения запустите с --apply ===');

if ($apply) {
    $db->beginTransaction();
}
try {
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $record = array_combine($header, $row);
        if ($record === false) {
            continue;
        }
        $isin = trim((string) $record['isin']);
        $findSecurity->execute(['isin' => $isin]);
        $security = $findSecurity->fetch();
        if ($security === false) {
            Logger::warn("{$isin}: в базе нет — пропущено.");
            $notFound++;
            continue;
        }
        if (!SecurityStatus::inCirculation((string) $security['status'])) {
            Logger::warn("{$isin} {$security['short_name']}: статус {$security['status']}, не в обращении — пропущено.");
            $notInCirculation++;
            continue;
        }
        $securityId = (int) $security['id'];

        $hasNsdCall->execute(['id' => $securityId]);
        if ($hasNsdCall->fetchColumn() !== false) {
            Logger::info("{$isin} {$security['short_name']}: колл уже объявлен НРД — данные Bondana не применяются.");
            $nsdAlreadyHasCall++;
            // НРД объявил — это и есть законченный результат по этой бумаге, Bondana можно больше не спрашивать.
            if ($apply) {
                $markChecked->execute(['now' => date('Y-m-d H:i:s'), 'id' => $securityId]);
            }
            $checked++;
            continue;
        }

        $foundInBondana = $record['found_in_bondana'] === '1';
        $cardOpened = $record['card_opened'] === '1';
        $note = (string) ($record['note'] ?? '');
        // Законченный результат: бумага не найдена вовсе, либо карточка открылась и график раскрыт до конца.
        $complete = !$foundInBondana || ($cardOpened && !str_contains($note, 'не до конца'));
        if (!$complete) {
            Logger::warn("{$isin} {$security['short_name']}: неполный ответ ({$note}) — проверим ещё раз в следующий раз.");
            $incomplete++;
            continue;
        }

        $added = [];
        $already = [];
        foreach (explode(',', (string) ($record['call_dates'] ?? '')) as $date) {
            $date = trim($date);
            if ($date === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                Logger::warn("{$isin} {$security['short_name']}: «{$date}» не похоже на дату — пропущено.");
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
            $bondsWithCall++;
            if ($apply) {
                $setFlag->execute(['id' => $securityId]);
            }
            Logger::info("{$isin} {$security['short_name']}: " . (count($added)) . ' дат(а) колла от Bondana: '
                . implode(', ', array_map(static fn (string $d): string => date('d.m.Y', (int) strtotime($d)), $added)));
        }
        if ($apply) {
            $markChecked->execute(['now' => date('Y-m-d H:i:s'), 'id' => $securityId]);
        }
        $checked++;
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
    'Проверено (%s bondana_checked_at): %d; колл от Bondana у %d бумаг (дат: %d, уже было: %d); колл уже был от НРД: %d;'
        . ' неполный ответ (перепроверим позже): %d; бумаг не найдено в базе: %d; бумаг не в обращении: %d',
    $apply ? 'проставлено' : 'будет проставлено',
    $checked,
    $bondsWithCall,
    $datesAdded,
    $datesAlready,
    $nsdAlreadyHasCall,
    $incomplete,
    $notFound,
    $notInCirculation,
));
