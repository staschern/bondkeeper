<?php

declare(strict_types=1);

/**
 * Офлайн-проверка: приоритет ИНН НАД сопоставлением "по корню" внутри
 * ОДНОГО прогона полного импортёра current_ratings — по прямому запросу
 * пользователя (сентябрь 2026, реальный найденный случай АО
 * «Аэрофьюэлз» / SPV ООО «Аэрофьюэлз Групп»): если в официальной
 * выгрузке агентства есть ОТДЕЛЬНАЯ строка для материнской компании (под
 * её настоящим ИНН) И отдельная строка для её SPV (которая сведётся по
 * корню к тому же issuer_id) — строка SPV не должна молча перезаписать
 * более надёжный прямой результат по ИНН, независимо от порядка строк в
 * файле (кроме случая, когда root-строка идёт РАНЬШЕ прямой — тогда
 * прямая строка её просто законно перезапишет позже, это ожидаемо).
 *
 * Проверяется через Reflection на приватном resolveIssuerIdWithPriority()
 * — вынесен отдельно от реального INSERT именно ради такого теста без
 * завязки на MySQL-диалект "ON DUPLICATE KEY UPDATE ... VALUES()" (тот
 * же нюанс, что и везде в проекте, см. CurrentRatingsSync::resolveOutlook()
 * и tests/test_default_grade_clears_outlook.php).
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_root_priority_conflict.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\AcraImporter;
use BondKeeper\Ratings\ExpertRaClient;
use BondKeeper\Ratings\ExpertRaImporter;
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

/** issuers: только материнская АО «Аэрофьюэлз» (ИНН 7714216826), SPV ООО «Аэрофьюэлз Групп» (ИНН 7710380617) в базе НЕТ. */
function makeMatcher(): IssuerMatcher
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, full_name TEXT, short_name TEXT, inn TEXT)');
    // Пустая (миграция 022) — resolveIssuerIdWithPriority() теперь пробует
    // findIssuerIdBySpvLink() между прямым ИНН и root, см.
    // tests/test_issuer_spv_link.php за проверкой самой этой связки.
    $pdo->exec('CREATE TABLE issuer_spv_links (spv_inn TEXT PRIMARY KEY, issuer_id INTEGER, spv_name TEXT, note TEXT)');
    $stmt = $pdo->prepare('INSERT INTO issuers (id, full_name, short_name, inn) VALUES (1, :full_name, :short_name, :inn)');
    $stmt->execute([
        'full_name' => 'Акционерное общество "Аэрофьюэлз"',
        'short_name' => 'АО "Аэрофьюэлз"',
        'inn' => '7714216826',
    ]);

    return new IssuerMatcher($pdo);
}

function callPrivate(object $obj, string $method, array $args): mixed
{
    $ref = new ReflectionClass($obj);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($obj, $args);
}

$dummyPdo = new PDO('sqlite::memory:');

$importers = [
    'NkrImporter' => new NkrImporter($dummyPdo, makeMatcher()),
    'ExpertRaImporter' => new ExpertRaImporter($dummyPdo, makeMatcher(), new ExpertRaClient()),
    'AcraImporter' => new AcraImporter($dummyPdo, makeMatcher()),
];

foreach ($importers as $label => $importer) {
    echo "--- {$label}: прямая строка (ИНН материнской), потом строка SPV (по корню) в ЭТОМ ЖЕ прогоне ---\n";

    $direct = callPrivate($importer, 'resolveIssuerIdWithPriority', ['7714216826', 'Акционерное общество "Аэрофьюэлз"']);
    check("{$label}: прямая строка по ИНН — issuer_id найден, не по корню, без конфликта", ['issuerId' => 1, 'matchedByRoot' => false, 'skippedPriorityConflict' => false], $direct);

    $spvAfter = callPrivate($importer, 'resolveIssuerIdWithPriority', ['7710380617', 'ООО «Аэрофьюэлз Групп»']);
    check(
        "{$label}: строка SPV ПОСЛЕ прямой — root-совпадение на тот же issuer_id ИГНОРИРУЕТСЯ (приоритет ИНН)",
        ['issuerId' => null, 'matchedByRoot' => false, 'skippedPriorityConflict' => true],
        $spvAfter
    );
}

foreach ($importers as $label => $_) {
    // Свежий импортёр — своё собственное состояние innMatchedIssuerIds на
    // прогон (не переиспользуем предыдущий, иначе тест зависел бы от
    // порядка выполнения foreach выше).
    $importer = match ($label) {
        'NkrImporter' => new NkrImporter($dummyPdo, makeMatcher()),
        'ExpertRaImporter' => new ExpertRaImporter($dummyPdo, makeMatcher(), new ExpertRaClient()),
        'AcraImporter' => new AcraImporter($dummyPdo, makeMatcher()),
    };

    echo "--- {$label}: строка SPV (по корню) ПЕРВОЙ, потом прямая строка материнской — обратный порядок ---\n";

    $spvFirst = callPrivate($importer, 'resolveIssuerIdWithPriority', ['7710380617', 'ООО «Аэрофьюэлз Групп»']);
    check("{$label}: строка SPV первой (нечего конфликтовать) — находит по корню как обычно", ['issuerId' => 1, 'matchedByRoot' => true, 'skippedPriorityConflict' => false], $spvFirst);

    $directAfter = callPrivate($importer, 'resolveIssuerIdWithPriority', ['7714216826', 'Акционерное общество "Аэрофьюэлз"']);
    check(
        "{$label}: прямая строка ПОСЛЕ root — не подавляется, законно 'перезапишет' (обратный порядок безопасен)",
        ['issuerId' => 1, 'matchedByRoot' => false, 'skippedPriorityConflict' => false],
        $directAfter
    );
}

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
