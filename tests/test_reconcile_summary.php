<?php

declare(strict_types=1);

/**
 * Офлайн-проверка сводки сверки для администратора (ReconcileSummary) и
 * разбиения длинного текста на сообщения (AdminNotifier::splitLines()).
 * Данные — по мотивам первой живой сводки НКР 28.09.2026: раньше
 * приходили первые 10 строк "по полю" и отсылка к логу.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring tests/test_reconcile_summary.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаг -d не нужен.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\ReconcileSummary;
use BondKeeper\Telegram\AdminNotifier;

$failures = 0;
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n";
    }
}

$mismatch = static fn (int $id, string $our, string $agency, string $field, ?string $ours, ?string $theirs, ?string $source, ?string $title = null, ?string $url = null): array => [
    'issuer_id' => $id, 'our_name' => $our, 'agency_name' => $agency, 'field' => $field, 'ours' => $ours, 'theirs' => $theirs,
    'our_source' => $source, 'our_action_title' => $title, 'our_action_url' => $url,
];

$result = [
    'snapshot_count' => 116,
    'field_mismatches' => [
        $mismatch(368, 'АО "ГИДРОМАШСЕРВИС"', 'АО «ГИДРОМАШСЕРВИС»', 'outlook', 'stable', 'negative', 'action', 'НКР снизило кредитные рейтинги АО «ГИДРОМАШСЕРВИС» и его облигаций с A+.ru до A.ru', 'https://ratings.ru/ratings/press-releases/HMS-RA-240926/'),
        $mismatch(1790, 'ООО "ЕвразХолдинг Финанс"', 'ПАО «ЕВРАЗ»', 'rating', 'AA+.ru', 'AA-.ru', 'manual'),
        $mismatch(1790, 'ООО "ЕвразХолдинг Финанс"', 'ПАО «ЕВРАЗ»', 'outlook', null, 'stable', 'manual'),
        $mismatch(1790, 'ООО "ЕвразХолдинг Финанс"', 'ПАО «ЕВРАЗ»', 'last_action_date', '2026-03-30', '2026-09-04', 'manual'),
        $mismatch(1536, 'ООО "ИКС 5 ФИНАНС"', 'ООО «Корпоративный центр ИКС 5»', 'outlook', null, 'stable', 'action', 'НКР присвоило выпуску биржевых облигаций ООО «ИКС 5 ФИНАНС» серии 003P-21 кредитный рейтинг AAA.ru'),
        $mismatch(1536, 'ООО "ИКС 5 ФИНАНС"', 'ООО «Корпоративный центр ИКС 5»', 'last_action_date', '2026-09-14', '2025-12-03', 'action', 'НКР присвоило выпуску биржевых облигаций ООО «ИКС 5 ФИНАНС» серии 003P-21 кредитный рейтинг AAA.ru'),
    ],
    'missing_in_ours' => [],
    'missing_in_snapshot' => [
        ['issuer_id' => 373, 'our_name' => 'ООО "ВИС ФИНАНС"', 'ours' => 'AA-.ru', 'last_action_date' => '2026-07-16', 'source' => null, 'expected' => false, 'reason' => null,
            'note' => 'строка получена старым сопоставлением «по корню» названия — проверьте, та ли это компания'],
        ['issuer_id' => 3050, 'our_name' => 'ООО "реСтор"', 'ours' => 'отозван', 'last_action_date' => '2024-03-14', 'source' => 'manual', 'expected' => true,
            'reason' => 'внесено вручную из xlsx — агентство рейтингует облигации компании или её мать, а не саму компанию', 'note' => null],
    ],
];

$lines = ReconcileSummary::lines('НКР', $result);
$text = implode("\n", $lines);
$lineWith = static fn (string $needle): string => (string) current(array_filter($lines, static fn (string $l): bool => str_contains($l, $needle)));

echo "--- Заголовок и разделы ---\n";
check('в заголовке: направление "у нас → у агентства" и число компаний, а не полей', str_contains($lines[0], 'НКР (у нас → у агентства)') && str_contains($lines[0], 'компаний с расхождениями по полям: 3'));
check('раздел "Требуют внимания" с числом', str_contains($text, 'Требуют внимания (3):'));
check('раздел "Ожидаемые" с числом', str_contains($text, 'Ожидаемые (2):'));
check('нет отсылки к логу и обрезки "ещё N"', !str_contains($text, 'лог') && !str_contains($text, 'ещё'));

echo "--- Одна строка на компанию, все поля сразу ---\n";
$evraz = $lineWith('ЕвразХолдинг');
check('ЕвразХолдинг Финанс — одной строкой', substr_count($text, 'ЕвразХолдинг') === 1);
check('все три поля по-русски, даты ДД.ММ.ГГГГ', str_contains($evraz, 'рейтинг AA+.ru → AA-.ru; прогноз — → стабильный; дата 30.03.2026 → 04.09.2026'));
check('название у агентства показано, раз оно другое', str_contains($evraz, '[у агентства — ПАО «ЕВРАЗ»]'));
check('источник нашего значения — ручной ввод', str_contains($evraz, 'наше: ручной ввод'));

$hms = $lineWith('ГИДРОМАШСЕРВИС');
check('ГИДРОМАШСЕРВИС: название агентства НЕ дублируется, когда совпадает с нашим', !str_contains($hms, 'у агентства —'));
check('ГИДРОМАШСЕРВИС: прогноз стабильный → негативный', str_contains($hms, 'прогноз стабильный → негативный'));
check('ГИДРОМАШСЕРВИС: наша новость с заголовком и ссылкой', str_contains($hms, 'наше: новость «НКР снизило') && str_contains($hms, 'HMS-RA-240926'));

echo "--- Ожидаемые и требующие внимания ---\n";
$attentionPart = substr($text, strpos($text, 'Требуют внимания'), strpos($text, 'Ожидаемые') - strpos($text, 'Требуют внимания'));
$expectedPart = substr($text, strpos($text, 'Ожидаемые'));
check('ИКС 5 ФИНАНС (новость о выпуске облигаций) — в ожидаемых', str_contains($expectedPart, 'ИКС 5 ФИНАНС') && !str_contains($attentionPart, 'ИКС 5 ФИНАНС'));
check('…с пояснением', str_contains($lineWith('ИКС 5 ФИНАНС'), 'наше — из новости о рейтинге выпуска облигаций'));
check('ГИДРОМАШСЕРВИС и ЕвразХолдинг — в требующих внимания', str_contains($attentionPart, 'ГИДРОМАШСЕРВИС') && str_contains($attentionPart, 'ЕвразХолдинг'));
check('ВИС ФИНАНС (нет в снимке, старый root) — в требующих внимания с пометкой', str_contains($attentionPart, 'ВИС ФИНАНС') && str_contains($lineWith('ВИС ФИНАНС'), 'по корню'));
check('реСтор (ручной, отозван) — в ожидаемых с причиной', str_contains($expectedPart, 'реСтор') && str_contains($lineWith('реСтор'), 'вручную'));

echo "--- Сверка Эксперт РА 29.09.2026: новости, которые теперь пропускаем, и статус наблюдения ---\n";
$era = ReconcileSummary::lines('Эксперт РА', [
    'snapshot_count' => 250,
    'field_mismatches' => [
        $mismatch(95, 'АО "АЛЬФА-БАНК"', 'АО "АЛЬФА-БАНК"', 'rating', 'ruA+', 'ruAA+', 'action', '«Эксперт РА» присвоил кредитный рейтинг субординированным облигациям АО «АЛЬФА-БАНК» серии T2-CR-09 с установленным сроком погашения на уровне ruA+', 'https://raexpert.ru/releases/2026/sep29a'),
        $mismatch(497, 'ООО "ПетроИнжиниринг"', 'ООО "ИСК "ПЕТРОИНЖИНИРИНГ"', 'rating', 'отозван', 'ruA', 'action', '«Эксперт РА» отозвал кредитный рейтинг облигаций ООО «ИСК «Петроинжиниринг» серии 001Р-01 в связи с их полным погашением', 'https://raexpert.ru/releases/2026/sep21e'),
        $mismatch(2025, 'АО "Атомэнергопром"', 'АО "АТОМЭНЕРГОПРОМ"', 'last_action_date', '2026-09-29', '2026-09-02', 'action', '«Эксперт РА» присвоил кредитный рейтинг облигациям АО «Атомэнергопром» серии 001Р-18 на уровне ruAAA'),
        $mismatch(3100, 'ПАО "ТрансКонтейнер"', 'ПАО "ТРАНСКОНТЕЙНЕР"', 'outlook', 'under_review_stable', 'stable', 'action', '«Эксперт РА» продлил статус «под наблюдением» по кредитному рейтингу ПАО «ТрансКонтейнер»') + ['watch_kept' => true],
    ],
    'missing_in_ours' => [],
    'missing_in_snapshot' => [],
]);
$eraText = implode("\n", $era);
$eraLine = static fn (string $needle): string => (string) current(array_filter($era, static fn (string $l): bool => str_contains($l, $needle)));
$eraAttention = substr($eraText, strpos($eraText, 'Требуют внимания'), strpos($eraText, 'Ожидаемые') - strpos($eraText, 'Требуют внимания'));
$eraExpected = substr($eraText, strpos($eraText, 'Ожидаемые'));
check('Альфа-Банк (новость о субординированных) — требует внимания, не ожидаемое', str_contains($eraAttention, 'АЛЬФА-БАНК') && !str_contains($eraExpected, 'АЛЬФА-БАНК'));
check('…с заголовком, ссылкой и причиной', str_contains($eraLine('АЛЬФА-БАНК'), 'sep29a') && str_contains($eraLine('АЛЬФА-БАНК'), 'рейтинг субординированных облигаций') && str_contains($eraLine('АЛЬФА-БАНК'), 'исправит перезапись'));
check('ПетроИнжиниринг (отзыв из-за погашения) — требует внимания', str_contains($eraAttention, 'ПетроИнжиниринг') && str_contains($eraLine('ПетроИнжиниринг'), 'из-за погашения'));
check('Атомэнергопром (обычная новость о выпуске) — по-прежнему ожидаемое', str_contains($eraExpected, 'Атомэнергопром') && str_contains($eraLine('Атомэнергопром'), 'новости о рейтинге выпуска облигаций'));
check('ТрансКонтейнер (наш статус наблюдения) — ожидаемое с пояснением', str_contains($eraExpected, 'ТрансКонтейнер') && str_contains($eraLine('ТрансКонтейнер'), 'не показывает'));
check('разделы: 2 требуют внимания, 2 ожидаемых', str_contains($eraText, 'Требуют внимания (2):') && str_contains($eraText, 'Ожидаемые (2):'));

echo "--- AdminNotifier::splitLines() ---\n";
$many = array_map(static fn (int $i): string => "• строка {$i} " . str_repeat('ж', 90), range(1, 100));
$messages = AdminNotifier::splitLines($many);
check('длинная сводка — несколько сообщений', count($messages) > 1);
check('каждое не длиннее 3800 символов', array_filter($messages, static fn (string $m): bool => mb_strlen($m) > 3800) === []);
check('ни одна строка не потеряна и не разрезана', implode("\n", $messages) === implode("\n", $many));
check('короткая сводка — одно сообщение', count(AdminNotifier::splitLines(['a', 'b'])) === 1);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
