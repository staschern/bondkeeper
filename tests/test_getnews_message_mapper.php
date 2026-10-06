<?php

declare(strict_types=1);

/**
 * Офлайн-проверка разбора сообщений GetNews: GetNewsBody (чтение текста) и
 * GetNewsMessageMapper (перевод в PaymentMessage).
 *
 * Образцы — НАСТОЯЩИЕ сообщения НРД из выгрузки тестового доступа
 * (18–30.09.2026), tests/fixtures/getnews_messages.json: 31 сообщение, по
 * одному на каждый встреченный случай (английские поля убраны). Правила
 * проверены ещё и на всей выгрузке целиком (3798 сообщений → 1554
 * сообщения о выплатах, ни одного непрочитанного) — см.
 * docs/STAGE5_PAYMENTS.md, раздел 9.
 *
 * Переписан 05.10.2026 вместе с разбором: прежняя версия проверяла
 * правило «состояние A → выплачено, N → пропустить», которое на данных
 * оказалось неверным.
 *
 * Запуск (из корня репозитория):
 *   php -d extension=mbstring tests/test_getnews_message_mapper.php
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Payments\GetNewsBody;
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

/** @var array<string, array<string, mixed>> $fixtures */
$fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures/getnews_messages.json'), true, 512, JSON_THROW_ON_ERROR);
$map = static fn (string $label): ?PaymentMessage => GetNewsMessageMapper::map($fixtures[$label]);
$reason = static fn (string $label): ?string => GetNewsMessageMapper::classify($fixtures[$label])['reason'];
/** Главное в сообщении одной строкой: вид, стадия, исполнение, сумма, пометки. */
$brief = static function (?PaymentMessage $m): ?string {
    if ($m === null) {
        return null;
    }

    return "{$m->kind} {$m->stage}/{$m->execution} " . ($m->amountPerBond ?? '-') . ' из ' . ($m->plannedPerBond ?? '-')
        . ($m->late ? ' late' : '') . ($m->beforeDue ? ' before_due' : '');
};

echo "--- чтение текста сообщения ---\n";
$body = GetNewsBody::currentPayment((string) $fixtures['samolet_redemption_part2']['body_ru']);
check('блок «Текущая выплата по КД»: сумма перевода на бумагу и дата поступления', ['730.895092532', '2026-09-29', null], [$body['amount'], $body['received'], $body['transferred']]);
check('формулировка НРД о неисполнении — дословно', 'Не исполнена эмитентом в срок, исполнена ненадлежащим образом', $body['note']);
$body = GetNewsBody::currentPayment((string) $fixtures['pair_transferred']['body_ru']);
check('«О передаче»: есть и дата поступления, и дата передачи; пометки нет', ['105.2', '2026-09-25', '2026-09-30', null], array_values($body));
check('объявление: блока о деньгах нет', ['amount' => null, 'received' => null, 'transferred' => null, 'note' => null], GetNewsBody::currentPayment((string) $fixtures['announcement_state_N']['body_ru']));
check('дата прописью', ['2026-09-01', '2026-10-12', null], [GetNewsBody::parseDate('01 сентября 2026 г.'), GetNewsBody::parseDate('12 Октября 2026 г.'), GetNewsBody::parseDate('скоро')]);
check('сумма: точка, запятая, пробелы; не число → null', ['730.895092532', '1000.50', null], [GetNewsBody::parseAmount('730.895092532'), GetNewsBody::parseAmount('1 000,50'), GetNewsBody::parseAmount('—')]);
check('пустой текст — ничего не найдено, без ошибок', null, GetNewsBody::currentPayment('')['amount']);

echo "\n--- вид сообщения — по заголовку, а не по полю «состояние» ---\n";
check('«О получении и передаче»', GetNewsMessageMapper::TYPE_RECEIVED_TRANSFERRED, GetNewsMessageMapper::messageType((string) $fixtures['combined_state_A']['title_ru']));
check('«О получении»', GetNewsMessageMapper::TYPE_RECEIVED, GetNewsMessageMapper::messageType((string) $fixtures['pair_received']['title_ru']));
check('«О передаче»', GetNewsMessageMapper::TYPE_TRANSFERRED, GetNewsMessageMapper::messageType((string) $fixtures['pair_transferred']['title_ru']));
check('«О корпоративном действии»', GetNewsMessageMapper::TYPE_ANNOUNCEMENT, GetNewsMessageMapper::messageType((string) $fixtures['announcement_state_N']['title_ru']));
check('«Об отмене корпоративного действия» — не про выплату', null, GetNewsMessageMapper::messageType((string) $fixtures['cancellation']['title_ru']));

echo "\n--- обычная выплата ---\n";
$m = $map('pair_received');
check('«О получении» при состоянии «Не состоялось» — деньги получены (раньше такое сообщение пропускалось)', 'coupon received/full 105.2 из 105.2', $brief($m));
check('даты: выплата 30.09, сообщение 28.09, деньги поступили 25.09', ['2026-09-30', '2026-09-28', '2026-09-25', null], [$m->paymentDate, $m->messageDate, $m->receivedDate, $m->transferredDate]);
check('ключ повторов — content_id_out, событие — action_id, бумага — ISIN', ['16341148028', '442109', 'RU000A100W45'], [$m->sourceRef, $m->actionRef, $m->isin]);
check('валюта выплаты и дата фиксации', ['RUB', '2026-09-29'], [$m->currency, $m->recordDate]);
$m = $map('pair_transferred');
check('«О передаче» — вторая половина пары: стадия «передано», та же дата поступления', ['coupon transferred/full 105.2 из 105.2', '2026-09-25', '2026-09-30'], [$brief($m), $m->receivedDate, $m->transferredDate]);
check('«О получении и передаче», состояние «Состоялось»', 'coupon received/full 11.34 из 11.34', $brief($map('combined_state_A')));
check('«О получении и передаче», состояние «Не состоялось» — то же самое', 'coupon received/full 0.05 из 0.05', $brief($map('combined_state_N')));
check('амортизация (PRED)', 'amortization received/full 41.6 из 41.6', $brief($map('amortization_ltrade')));
check('купон той же бумаги и даты — отдельное сообщение', 'coupon received/full 7.95 из 7.95', $brief($map('coupon_ltrade')));
check('погашение (REDM)', 'redemption received/full 1000 из 1000', $brief($map('invobl_redemption')));
check('дата выплаты — с переносом на рабочий день (по условиям выпуска суббота 26.09)', ['2026-09-28', '2026-09-26'], [$map('weekend_shift_samolet_p13')->paymentDate, $fixtures['weekend_shift_samolet_p13']['data']['action_date_calc']]);

echo "\n--- объявления ---\n";
check('объявление в состоянии «Не состоялось» — не выплата', [null, GetNewsMessageMapper::REASON_ANNOUNCEMENT], [$map('announcement_state_N'), $reason('announcement_state_N')]);
check('объявление в состоянии «Состоялось» — тоже не деньги (раньше засчитывалось как получение)', [null, GetNewsMessageMapper::REASON_ANNOUNCEMENT], [$map('announcement_state_A'), $reason('announcement_state_A')]);
$m = $map('garant_tech_default_announcement');
check('объявление в состоянии «Техн.дефолт» — не исполнено в срок, суммы нет', ['coupon received/none - из 8.22', '2026-09-28'], [$brief($m), $m->paymentDate]);
$m = $map('lkh2_tech_default_announcement');
check('то же, дата по условиям — воскресенье 27.09, в сообщении — понедельник 28.09', ['coupon received/none - из 25.48', '2026-09-28'], [$brief($m), $m->paymentDate]);
check('объявление в состоянии «Дефолт» — полный дефолт (купон)', 'coupon received/default - из 21.78', $brief($map('monopoly_default_coupon')));
check('объявление о погашении идёт с типом REDM/BN — тот же вид выплаты', 'redemption received/default - из 1000', $brief($map('monopoly_default_redemption')));

echo "\n--- деньги с нарушением ---\n";
$m = $map('late_full_em_zapad');
check('ЭМ ЗАПАД: вся сумма, но на день позже срока — выплата полная и поздняя, а не «не выплачено»', 'coupon received/full 12.74 из 12.74 late', $brief($m));
check('формулировка НРД сохранена', 'Не исполнена эмитентом в срок', $m->sourceNote);
$m = $map('samolet_redemption_part1');
check('«Самолёт», первая часть: сумма из текста (269,10), а не плановая 1000 из полей', 'redemption received/partial 269.104907468 из 1000', $brief($m));
check('состояние у сообщения «Не состоялось», нарушение видно только по пометке', ['N', 'Исполнена ненадлежащим образом'], [$fixtures['samolet_redemption_part1']['data']['state']['code'], $m->sourceNote]);
check('«Самолёт», вторая часть на следующий день: частичная и поздняя', 'redemption received/partial 730.895092532 из 1000 late', $brief($map('samolet_redemption_part2')));
$m = $map('mmz_partial_before_due');
check('ММЗ: часть суммы пришла раньше срока — отдельный признак, не «поздно»', 'coupon received/partial 2.84 из 4.68 before_due', $brief($m));
check('…деньги поступили 28.09, срок 29.09', ['2026-09-28', '2026-09-29'], [$m->receivedDate, $m->paymentDate]);
check('ЛКХ: поздняя полная выплата, «О получении»', 'coupon received/full 25.48 из 25.48 late', $brief($map('lkh1_late_received')));
check('ЛКХ: её же «О передаче»', 'coupon transferred/full 25.48 из 25.48 late', $brief($map('lkh1_late_transferred')));
check('ЛКХ: выплата после срока полного дефолта (состояние «Дефолт») — деньги всё равно читаются', 'coupon transferred/full 25.48 из 25.48 late', $brief($map('lkh2_transferred_state_D')));
check('ВЗВТ: частичная выплата на 14-й рабочий день', 'coupon transferred/partial 61.79 из 74.79 late', $brief($map('vzvt_partial_transferred_state_D')));

echo "\n--- особые случаи ---\n";
$m = $map('fx_coupon_acron_usd');
check('валютный выпуск: сумма и плановая — в валюте выплаты (рубли), не в долларах номинала', ['coupon received/full 537.25 из 537.25', 'RUB', 6.37], [$brief($m), $m->currency, $fixtures['fx_coupon_acron_usd']['data']['coupon']['size']]);
check('два события на одну дату по одной бумаге: «купонный доход»…', ['coupon received/full 0.1 из 0.1', '976513'], [$brief($map('invobl_coupon_fixed')), $map('invobl_coupon_fixed')->actionRef]);
check('…и «процентный доход» — другое событие НРД', ['coupon received/full 65.21 из 65.21', '1220824'], [$brief($map('invobl_extra_income')), $map('invobl_extra_income')->actionRef]);
check('MCAL на 100 % номинала — погашение', 'redemption received/full 1000 из 1000', $brief($map('mcal_full_veb')));
check('MCAL на часть номинала — амортизация', ['amortization received/full 98.77 из 98.77', '2026-09-28'], [$brief($map('mcal_partial_tb3')), $map('mcal_partial_tb3')->paymentDate]);

echo "\n--- что пропускается и почему ---\n";
check('другой вид события (раскрытие информации)', [null, GetNewsMessageMapper::REASON_OTHER_ACTION], [$map('other_action_dscl'), $reason('other_action_dscl')]);
check('оферта (BPUT) — пока не обрабатывается, даже если это сообщение о деньгах', [null, GetNewsMessageMapper::REASON_OTHER_ACTION], [$map('offer_bput_money'), $reason('offer_bput_money')]);
check('отмена корпоративного действия', [null, GetNewsMessageMapper::REASON_OTHER_MESSAGE], [$map('cancellation'), $reason('cancellation')]);

$broken = $fixtures['pair_received'];
$broken['body_ru'] = str_replace('Текущая выплата по КД', 'Выплата', (string) $broken['body_ru']);
check('сообщение о деньгах без таблицы «Текущая выплата по КД» — не угадываем, а сообщаем причину', [null, GetNewsMessageMapper::REASON_UNREADABLE], [GetNewsMessageMapper::map($broken), GetNewsMessageMapper::classify($broken)['reason']]);
$noIsin = $fixtures['pair_received'];
$noIsin['data']['securities'] = [];
check('нет ISIN', GetNewsMessageMapper::REASON_NO_KEY_FIELDS, GetNewsMessageMapper::classify($noIsin)['reason']);
$noId = $fixtures['pair_received'];
unset($noId['content_id_out'], $noId['id']);
check('нет идентификатора сообщения', GetNewsMessageMapper::REASON_NO_KEY_FIELDS, GetNewsMessageMapper::classify($noId)['reason']);
$noPlan = $fixtures['pair_received'];
unset($noPlan['data']['action_date_plan']);
check('нет плановой даты — берётся дата по условиям выпуска', '2026-09-30', GetNewsMessageMapper::map($noPlan)->paymentDate);

echo "\n--- архив ---\n";
$withEnglish = $fixtures['pair_received'] + ['body_en' => '<table>…</table>', 'title_en' => 'Notification', 'announce_en' => 'Notification'];
$raw = GetNewsMessageMapper::map($withEnglish)->raw;
check('в архив идёт сообщение без английских копий текста', [false, false, true, true], [isset($raw['body_en']), isset($raw['title_en']), isset($raw['body_ru']), isset($raw['data'])]);

echo "\n--- все образцы разом ---\n";
$mapped = 0;
$unreadable = 0;
foreach ($fixtures as $fixture) {
    $classified = GetNewsMessageMapper::classify($fixture);
    $mapped += $classified['message'] !== null ? 1 : 0;
    $unreadable += $classified['reason'] === GetNewsMessageMapper::REASON_UNREADABLE ? 1 : 0;
}
check('из 31 образца 26 — сообщения о выплатах, непрочитанных нет', [31, 26, 0], [count($fixtures), $mapped, $unreadable]);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
