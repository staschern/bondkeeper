<?php

declare(strict_types=1);

/**
 * Офлайн-проверка переписанного OffersImporter (14 сентября 2026, см.
 * докблок класса) — через Reflection к приватным resolveUpcomingOfferDate()/
 * resolveOfferType()/nullableDate(), той же логике, что теперь решает,
 * какая строка из bondization/offers попадает в offers и как определяется
 * put/call.
 *
 * Сама запись в БД (INSERT ... ON DUPLICATE KEY UPDATE) здесь не
 * проверяется — тот же давний нюанс офлайн-тестирования, что и везде в
 * проекте (SQLite не понимает MySQL-диалект, см. docs/STAGE4_EVENT_ENGINE.md
 * и докблок tests/test_bot_ux_screens.php): предмет этой переписки —
 * именно выбор даты/типа из bondization/offers, а он проверяется целиком
 * без обращения к БД.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring tests/test_offers_importer.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаг -d не нужен.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Iss\IssClient;
use BondKeeper\Iss\OffersImporter;

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

$importer = new OffersImporter(new IssClient(), new PDO('sqlite::memory:'));
$ref = new ReflectionClass($importer);

function callPrivate(ReflectionClass $ref, object $obj, string $method, array $args): mixed
{
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($obj, $args);
}

// --- resolveUpcomingOfferDate(): фильтр по типу и по дате ---

// Живой пример из выборки (14 сентября 2026, iss.moex.com): "Оферта" с
// будущей датой — берём как есть.
$rows = [
    ['offertype' => 'Оферта', 'offerdate' => '2026-12-01'],
];
check(
    'resolveUpcomingOfferDate(): простая предстоящая "Оферта" берётся',
    callPrivate($ref, $importer, 'resolveUpcomingOfferDate', [$rows]) === '2026-12-01'
);

// Живой пример из выборки: "Оферта/Погашение" с реальной будущей датой
// (RU000A10ENU1, «Банк ВТБ (ПАО) Б-1-232», offerdate=2026-09-24) — по
// прямому решению (см. докблок класса) тоже считается предстоящей офертой.
$rows = [
    ['offertype' => 'Оферта/Погашение', 'offerdate' => '2026-09-24'],
];
check(
    'resolveUpcomingOfferDate(): "Оферта/Погашение" с реальной датой тоже берётся',
    callPrivate($ref, $importer, 'resolveUpcomingOfferDate', [$rows]) === '2026-09-24'
);

// Уже состоявшиеся/отменённые/дефолтные — не предстоящие события, не берём
// даже если формально дата в будущем.
foreach (['Оферта (состоялось)', 'Оферта (отменено)', 'Оферта (дефолт)', 'Оферта (технический дефолт)', 'Оферта/Погашение (состоялось)'] as $type) {
    $rows = [['offertype' => $type, 'offerdate' => '2099-01-01']];
    check(
        "resolveUpcomingOfferDate(): '{$type}' не считается предстоящей",
        callPrivate($ref, $importer, 'resolveUpcomingOfferDate', [$rows]) === null
    );
}

// Сигнальное значение "0000-00-00" (та же защита nullableDate, что и в
// доска-специфичном эндпоинте) — не считается датой.
$rows = [['offertype' => 'Оферта', 'offerdate' => '0000-00-00']];
check(
    'resolveUpcomingOfferDate(): "0000-00-00" — не дата',
    callPrivate($ref, $importer, 'resolveUpcomingOfferDate', [$rows]) === null
);

// Дата в прошлом у формально "предстоящего" типа (данные ISS устарели/не
// подчистили статус) — не берём, это уже не предстоящее событие.
$rows = [['offertype' => 'Оферта', 'offerdate' => '2020-01-01']];
check(
    'resolveUpcomingOfferDate(): дата в прошлом отбрасывается',
    callPrivate($ref, $importer, 'resolveUpcomingOfferDate', [$rows]) === null
);

// Несколько предстоящих строк — берём БЛИЖАЙШУЮ, не первую по порядку и не
// последнюю.
$rows = [
    ['offertype' => 'Оферта', 'offerdate' => '2027-06-01'],
    ['offertype' => 'Оферта', 'offerdate' => '2026-11-15'],
    ['offertype' => 'Оферта/Погашение', 'offerdate' => '2028-01-01'],
];
check(
    'resolveUpcomingOfferDate(): из нескольких предстоящих берётся ближайшая',
    callPrivate($ref, $importer, 'resolveUpcomingOfferDate', [$rows]) === '2026-11-15'
);

// Смесь: одна отменённая с ближайшей датой (не должна победить) + одна
// настоящая предстоящая подальше.
$rows = [
    ['offertype' => 'Оферта (отменено)', 'offerdate' => '2026-10-01'],
    ['offertype' => 'Оферта', 'offerdate' => '2026-12-25'],
];
check(
    'resolveUpcomingOfferDate(): отменённая не мешает найти настоящую предстоящую',
    callPrivate($ref, $importer, 'resolveUpcomingOfferDate', [$rows]) === '2026-12-25'
);

// Пустой список строк (у bondization/offers бумаги без оферт вообще) — нет
// предстоящей оферты.
check(
    'resolveUpcomingOfferDate(): пустой список -> null',
    callPrivate($ref, $importer, 'resolveUpcomingOfferDate', [[]]) === null
);

// --- resolveOfferType(): unchanged put/call логика с доска-эндпоинта ---

check(
    'resolveOfferType(): только PUTOPTIONDATE заполнен -> put',
    callPrivate($ref, $importer, 'resolveOfferType', [['PUTOPTIONDATE' => '2026-12-01', 'CALLOPTIONDATE' => '0000-00-00']]) === 'put'
);
check(
    'resolveOfferType(): только CALLOPTIONDATE заполнен -> call',
    callPrivate($ref, $importer, 'resolveOfferType', [['PUTOPTIONDATE' => '0000-00-00', 'CALLOPTIONDATE' => '2026-12-01']]) === 'call'
);
check(
    'resolveOfferType(): оба пусты -> unknown',
    callPrivate($ref, $importer, 'resolveOfferType', [['PUTOPTIONDATE' => null, 'CALLOPTIONDATE' => null]]) === 'unknown'
);
check(
    'resolveOfferType(): оба заполнены -> unknown (не гадаем)',
    callPrivate($ref, $importer, 'resolveOfferType', [['PUTOPTIONDATE' => '2026-12-01', 'CALLOPTIONDATE' => '2026-12-01']]) === 'unknown'
);

// --- nullableDate() ---

check('nullableDate(): "0000-00-00" -> null', callPrivate($ref, $importer, 'nullableDate', ['0000-00-00']) === null);
check('nullableDate(): пустая строка -> null', callPrivate($ref, $importer, 'nullableDate', ['']) === null);
check('nullableDate(): null -> null', callPrivate($ref, $importer, 'nullableDate', [null]) === null);
check('nullableDate(): реальная дата проходит как есть', callPrivate($ref, $importer, 'nullableDate', ['2026-12-01']) === '2026-12-01');

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
