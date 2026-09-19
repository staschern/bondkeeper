<?php

declare(strict_types=1);

/**
 * Офлайн-проверка явной ручной связки "неизвестный ИНН → issuer_id"
 * (`issuer_spv_links`, миграция 022, см. докблок
 * IssuerMatcher::findIssuerIdBySpvLink()) — по прямому запросу
 * пользователя (сентябрь 2026).
 *
 * Направление здесь определяется НЕ реальной корпоративной ролью ("кто
 * мать, кто SPV"), а исключительно тем, чей ИНН уже есть в `issuers`:
 * реальные найденные случаи — ООО «Русбонд-Удобрения» (ИНН 9725091192)
 * физически на бирже, есть в issuers; ООО «АгроНэкст» (ИНН 2315190775)
 * — структурно материнская/поручитель, которую рейтингует агентство
 * напрямую, но в issuers её НЕТ. Аналогично: ООО «ФСК Активы» (ИНН
 * 7714482955) — на бирже, есть в issuers; ООО «ЕВА» (ИНН 7708335695) —
 * структурно поручитель (в новости — "Информация о рейтингуемом лице:
 * ООО «ЕВА»"), в issuers её НЕТ. Имена в обеих парах НЕ ИМЕЮТ НИЧЕГО
 * ОБЩЕГО, "по корню" (миграция 021) их связать невозможно в принципе.
 *
 * Часть 1 — IssuerMatcher::findIssuerIdBySpvLink() напрямую, через
 * SQLite in-memory (SELECT — простой, портируемый SQL, тот же приём,
 * что и у findIssuerIdByInn()/findIssuerIdByRootName()).
 * Часть 2 — приоритет: связка ВЫШЕ root (NkrImporter::
 * resolveIssuerIdWithPriority(), через Reflection, без завязки на
 * MySQL-диалект INSERT — тот же приём, что и в
 * tests/test_root_priority_conflict.php).
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_issuer_spv_link.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\NkrImporter;

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
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        echo "  FAIL {$label}\n       ожидали: {$expectedStr}\n       получили: {$actualStr}\n";
    }
}

function makeMatcherWithLink(): IssuerMatcher
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, full_name TEXT, short_name TEXT, inn TEXT)');
    $pdo->exec('CREATE TABLE issuer_spv_links (spv_inn TEXT PRIMARY KEY, issuer_id INTEGER, spv_name TEXT, note TEXT)');

    // Реальный найденный случай: ООО «ФСК Активы» (ИНН 7714482955) есть
    // в issuers (её облигации на бирже); ООО «ЕВА» (ИНН 7708335695) — та
    // сторона, которую рейтингует агентство напрямую, но в issuers её
    // НЕТ вообще — связь только через issuer_spv_links.
    $pdo->prepare('INSERT INTO issuers (id, full_name, short_name, inn) VALUES (1, :full_name, :short_name, :inn)')
        ->execute(['full_name' => 'Общество с ограниченной ответственностью "ФСК Активы"', 'short_name' => 'ООО "ФСК Активы"', 'inn' => '7714482955']);
    $pdo->prepare('INSERT INTO issuer_spv_links (spv_inn, issuer_id, spv_name) VALUES (:spv_inn, 1, :spv_name)')
        ->execute(['spv_inn' => '7708335695', 'spv_name' => 'ООО «ЕВА»']);

    return new IssuerMatcher($pdo);
}

echo "--- Часть 1: IssuerMatcher::findIssuerIdBySpvLink() ---\n";

$matcher = makeMatcherWithLink();

check('ИНН стороны, которой нет в issuers -> issuer_id стороны, которая есть', 1, $matcher->findIssuerIdBySpvLink('7708335695'));
check('Имена вообще не связаны текстом — по корню это НЕ нашлось бы (проверка предпосылки)', null, $matcher->findIssuerIdByRootName('ООО «ЕВА»'));
check('ИНН, которого нет в связке -- null', null, $matcher->findIssuerIdBySpvLink('0000000000'));
check('NULL на входе -- null', null, $matcher->findIssuerIdBySpvLink(null));
check('Некорректный формат ИНН -- null (normalizeInn не пропустит)', null, $matcher->findIssuerIdBySpvLink('770833569X'));

echo "\n--- Часть 2: приоритет связки над корнем (NkrImporter::resolveIssuerIdWithPriority()) ---\n";

function callPrivate(object $obj, string $method, array $args): mixed
{
    $ref = new ReflectionClass($obj);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($obj, $args);
}

$dummyPdo = new PDO('sqlite::memory:');
$importer = new NkrImporter($dummyPdo, makeMatcherWithLink());

$viaSpvLink = callPrivate($importer, 'resolveIssuerIdWithPriority', ['7708335695', 'ООО «ЕВА»']);
check(
    'Строка с "неизвестным" ИНН -- находит issuer_id через issuer_spv_links, НЕ как root',
    ['issuerId' => 1, 'matchedByRoot' => false, 'skippedPriorityConflict' => false],
    $viaSpvLink
);

// Связка считается наравне с прямым ИНН для приоритета: ПОСЛЕДУЮЩАЯ
// строка, которая нашла бы тот же issuer_id только "по корню", должна
// быть проигнорирована (тот же принцип, что и у прямого ИНН, см.
// tests/test_root_priority_conflict.php).
$laterRootCandidate = callPrivate($importer, 'resolveIssuerIdWithPriority', ['0000000000', 'ФСК Активы Капитал']);
check(
    'Гипотетическая ПОЗЖЕ строка, находящая тот же issuer_id по корню, -- игнорируется (связка тоже в приоритете)',
    ['issuerId' => null, 'matchedByRoot' => false, 'skippedPriorityConflict' => true],
    $laterRootCandidate
);

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
