<?php

declare(strict_types=1);

/**
 * Офлайн-проверка GetNewsMessageMapper на РЕАЛЬНЫХ сообщениях GetNews
 * (тестовый доступ НРД, 05.10.2026, окно 18.09–02.10.2026 — см.
 * докблок класса). Фикстуры ниже — не выдуманные данные, а урезанные
 * (без body_ru/body_en — лишний объём) копии реальных сообщений: суммы,
 * даты, content_id_out, isin — настоящие значения из выгрузки.
 *
 * Запуск: php tests/test_getnews_message_mapper.php
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Payments\GetNewsMessageMapper;
use BondKeeper\Payments\PaymentMessage;

$failures = 0;
$checks = 0;

function check(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected === $actual) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n       ожидали: " . var_export($expected, true) . "\n       получили: " . var_export($actual, true) . "\n";
    }
}

// --- #1: купон, расписка (первое сообщение пары, action_id=1076442, 29.09 12:00) ---
// Сумма/ISIN/content_id_out — реальные; состояние N -> ждём второго сообщения.
$receiptPending = [
    'ca_type' => 'INTR',
    'content_id_out' => 16356133147,
    'id' => 1449166,
    'pub_date' => '2026-09-29 12:00:53',
    'announce_ru' => '(INTR) (Выплата купонного дохода) О получении головным депозитарием...',
    'data' => [
        'state' => ['id' => 141, 'code' => 'N', 'name' => 'Не состоялось'],
        'action_date_plan' => '2026-09-30',
        'action_date_calc' => '2026-09-30',
        'record_date_plan' => '2026-09-29',
        'securities' => [['isin' => 'RU000A10CLT1']],
        'coupon' => ['size' => 51.35, 'payment_size' => 51.35],
    ],
];

// --- #2: тот же купон, "О передаче" (второе сообщение пары, 30.09 09:55, state=A) ---
$transferConfirmed = [
    'ca_type' => 'INTR',
    'content_id_out' => 16369330854,
    'id' => 1449444,
    'pub_date' => '2026-09-30 09:55:11',
    'announce_ru' => '(INTR) (Выплата купонного дохода) О передаче головным депозитарием, осуществляющим обязательное централизованное хранение ценных бумаг своим депонентам...',
    'data' => [
        'state' => ['id' => 138, 'code' => 'A', 'name' => 'Состоялось'],
        'action_date_plan' => '2026-09-30',
        'action_date_calc' => '2026-09-30',
        'record_date_plan' => '2026-09-29',
        'securities' => [['isin' => 'RU000A10CLT1']],
        'coupon' => ['size' => 51.35, 'payment_size' => 51.35],
    ],
];

// --- #3: купон, ОДНО объединённое сообщение "получении и передаче" (ООО «Лизинг-Трейд», state=A) ---
$combinedReceivedAndTransferred = [
    'ca_type' => 'INTR',
    'content_id_out' => 16369851539,
    'id' => 1449498,
    'pub_date' => '2026-09-30 10:16:11',
    'announce_ru' => '(INTR) (Выплата купонного дохода) О получении и передаче головным депозитарием, осуществляющим обязательное централизованное хранение ценных бумаг...',
    'data' => [
        'state' => ['id' => 138, 'code' => 'A', 'name' => 'Состоялось'],
        'action_date_plan' => '2026-09-30',
        'action_date_calc' => '2026-09-30',
        'record_date_plan' => '2026-09-29',
        'securities' => [['isin' => 'RU000A105RF6']],
        'coupon' => ['size' => 7.95, 'payment_size' => 7.95],
    ],
];

// --- #4: погашение по графику (Банк ВТБ, REDM, state=A, 100% номинала) ---
$redemptionDone = [
    'ca_type' => 'REDM',
    'content_id_out' => 16369818035,
    'id' => 1449492,
    'pub_date' => '2026-09-30 10:14:22',
    'announce_ru' => '(REDM) (Погашение облигаций) О получении и передаче головным депозитарием...',
    'data' => [
        'state' => ['id' => 138, 'code' => 'A', 'name' => 'Состоялось'],
        'action_date_plan' => '2026-09-30',
        'action_date_calc' => '2026-09-30',
        'record_date_plan' => '2026-09-29',
        'securities' => [['isin' => 'RU000A10G7W6']],
        'repayment' => ['size_cur' => 1000, 'size_per_security_cur' => 1000],
    ],
];

// --- #5: амортизация (PRED, ОФЗ, state=A, доля номинала в $, выплата в рублях) ---
$amortizationDone = [
    'ca_type' => 'PRED',
    'content_id_out' => 16374795713,
    'id' => 1449647,
    'pub_date' => '2026-09-30 15:06:15',
    'announce_ru' => '(PRED) (Частичное погашение без уменьшения номинала) О получении и передаче головным депозитарием...',
    'data' => [
        'state' => ['id' => 138, 'code' => 'A', 'name' => 'Состоялось'],
        'action_date_plan' => '2026-09-30',
        'action_date_calc' => '2026-09-30',
        'record_date_plan' => '2026-09-25',
        'securities' => [['isin' => 'RU000A10A8E8']],
        'repayment' => ['size_cur' => 0.005, 'size_per_security_cur' => 0.4221415],
    ],
];

// --- #6: техдефолт (ООО «ЛКХ», известный проблемный эмитент, state=T) ---
$technicalDefault = [
    'ca_type' => 'INTR',
    'content_id_out' => 47378371594,
    'id' => 1449346,
    'pub_date' => '2026-09-29 17:33:34',
    'title_ru' => '(INTR) О корпоративном действии "Выплата купонного дохода" с ценными бумагами эмитента ООО "ЛКХ" ИНН 9729293827',
    'data' => [
        'state' => ['id' => 142, 'code' => 'T', 'name' => 'Техн.дефолт'],
        'action_date_plan' => '2026-09-28',
        'action_date_calc' => '2026-09-27',
        'record_date_plan' => '2026-09-25',
        'securities' => [['isin' => 'RU000A10AT01']],
        'coupon' => ['size' => 25.48, 'payment_size' => 25.48],
    ],
];

// --- #7: ca_type без суффикса — то же действие, что и REDM/BN ---
$redemptionWithSuffix = $redemptionDone;
$redemptionWithSuffix['ca_type'] = 'REDM/BN';

$r1 = GetNewsMessageMapper::map($receiptPending);
check('N (пока не наступило) -> null, ждём следующего сообщения', null, $r1);

$r2 = GetNewsMessageMapper::map($transferConfirmed);
check('"О передаче" без "получении" -> stage=transferred', PaymentMessage::STAGE_TRANSFERRED, $r2?->stage);
check('"О передаче": execution=full (state=A)', PaymentMessage::EXECUTION_FULL, $r2?->execution);
check('"О передаче": kind=coupon', PaymentMessage::KIND_COUPON, $r2?->kind);
check('"О передаче": сумма — payment_size', '51.35', $r2?->amountPerBond);
check('"О передаче": sourceRef = content_id_out', '16369330854', $r2?->sourceRef);
check('"О передаче": isin', 'RU000A10CLT1', $r2?->isin);
check('"О передаче": paymentDate = action_date_plan', '2026-09-30', $r2?->paymentDate);
check('"О передаче": messageDate = дата из pub_date (без времени)', '2026-09-30', $r2?->messageDate);

$r3 = GetNewsMessageMapper::map($combinedReceivedAndTransferred);
check('"О получении и передаче" (есть оба слова) -> stage=received', PaymentMessage::STAGE_RECEIVED, $r3?->stage);
check('объединённое сообщение: execution=full', PaymentMessage::EXECUTION_FULL, $r3?->execution);
check('объединённое сообщение: сумма купона', '7.95', $r3?->amountPerBond);

$r4 = GetNewsMessageMapper::map($redemptionDone);
check('погашение по графику: kind=redemption', PaymentMessage::KIND_REDEMPTION, $r4?->kind);
check('погашение: сумма — size_per_security_cur', '1000', $r4?->amountPerBond);
check('погашение: isin', 'RU000A10G7W6', $r4?->isin);

$r5 = GetNewsMessageMapper::map($amortizationDone);
check('PRED (НРД зовёт "досрочным", на деле плановая амортизация): kind=amortization', PaymentMessage::KIND_AMORTIZATION, $r5?->kind);
check('амортизация: сумма в валюте ВЫПЛАТЫ (размер в $ — для графика неважен, платим в рублях)', '0.4221415', $r5?->amountPerBond);

$r6 = GetNewsMessageMapper::map($technicalDefault);
check('техдефолт (ООО «ЛКХ»): execution=none', PaymentMessage::EXECUTION_NONE, $r6?->execution);
check('техдефолт: title берётся из title_ru, если announce_ru нет', true, str_contains((string) $r6?->title, 'ЛКХ'));
check('техдефолт: amountPerBond — плановая сумма (а не ноль/null — размер в сообщении не зависит от исполнения)', '25.48', $r6?->amountPerBond);

$r7 = GetNewsMessageMapper::map($redemptionWithSuffix);
check('ca_type с суффиксом (REDM/BN) — то же действие, что REDM', PaymentMessage::KIND_REDEMPTION, $r7?->kind);

check('необрабатываемый ca_type (BPUT — оферта, не наша таблица redemptions) -> null', null, GetNewsMessageMapper::map(['ca_type' => 'BPUT', 'data' => ['state' => ['code' => 'A']]]));
check('необрабатываемый ca_type (DVCA — дивиденды, не облигация) -> null', null, GetNewsMessageMapper::map(['ca_type' => 'DVCA', 'data' => ['state' => ['code' => 'A']]]));
check('state=C (Отменено) -> null, пока не решаем, что с этим делать', null, GetNewsMessageMapper::map(['ca_type' => 'INTR', 'data' => ['state' => ['code' => 'C']]]));
check('нет isin -> null, а не падение', null, GetNewsMessageMapper::map(['ca_type' => 'INTR', 'data' => ['state' => ['code' => 'A'], 'action_date_plan' => '2026-09-30', 'securities' => []]]));

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
