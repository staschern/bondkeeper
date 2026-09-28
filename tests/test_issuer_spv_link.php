<?php

declare(strict_types=1);

/**
 * Офлайн-проверка явной ручной связки "неизвестный ИНН → issuer_id"
 * (`issuer_spv_links`, миграция 023, см. докблок
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
 * ОБЩЕГО, "по корню" их связать невозможно в принципе.
 *
 * Часть 1 — IssuerMatcher::findIssuerIdBySpvLink() напрямую, через
 * SQLite in-memory (SELECT — простой, портируемый SQL, тот же приём,
 * что и у findIssuerIdByInn()/findIssuerIdByRootName()).
 * Часть 2 — связка в импортёре (NkrImporter::resolveIssuerId(), через
 * Reflection): связка сопоставляет напрямую, а эмитент, уже
 * сопоставленный через неё, по названию не предлагается (миграция 024,
 * см. tests/test_root_priority_conflict.php).
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_issuer_spv_link.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\NameMatchResolver;
use BondKeeper\Ratings\NameMatchReviews;
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

function makeDbWithLink(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, full_name TEXT, short_name TEXT, inn TEXT)');
    $pdo->exec('CREATE TABLE issuer_spv_links (spv_inn TEXT PRIMARY KEY, issuer_id INTEGER, spv_name TEXT, note TEXT)');
    $pdo->exec(
        "CREATE TABLE issuer_name_match_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_key_type TEXT NOT NULL, source_key TEXT NOT NULL, issuer_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending', match_type TEXT NOT NULL, agency TEXT NOT NULL,
            source_name TEXT, source_title TEXT, source_url TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP, notified_at TEXT, decided_at TEXT,
            UNIQUE (source_key_type, source_key, issuer_id)
        )"
    );

    // Реальный найденный случай: ООО «ФСК Активы» (ИНН 7714482955) есть
    // в issuers (её облигации на бирже); ООО «ЕВА» (ИНН 7708335695) — та
    // сторона, которую рейтингует агентство напрямую, но в issuers её
    // НЕТ вообще — связь только через issuer_spv_links.
    $pdo->prepare('INSERT INTO issuers (id, full_name, short_name, inn) VALUES (1, :full_name, :short_name, :inn)')
        ->execute(['full_name' => 'Общество с ограниченной ответственностью "ФСК Активы"', 'short_name' => 'ООО "ФСК Активы"', 'inn' => '7714482955']);
    $pdo->prepare('INSERT INTO issuer_spv_links (spv_inn, issuer_id, spv_name) VALUES (:spv_inn, 1, :spv_name)')
        ->execute(['spv_inn' => '7708335695', 'spv_name' => 'ООО «ЕВА»']);

    return $pdo;
}

echo "--- Часть 1: IssuerMatcher::findIssuerIdBySpvLink() ---\n";

$matcher = new IssuerMatcher(makeDbWithLink());

check('ИНН стороны, которой нет в issuers -> issuer_id стороны, которая есть', 1, $matcher->findIssuerIdBySpvLink('7708335695'));
check('Имена вообще не связаны текстом — по корню это НЕ нашлось бы (проверка предпосылки)', null, $matcher->findIssuerIdByRootName('ООО «ЕВА»'));
check('ИНН, которого нет в связке -- null', null, $matcher->findIssuerIdBySpvLink('0000000000'));
check('NULL на входе -- null', null, $matcher->findIssuerIdBySpvLink(null));
check('Некорректный формат ИНН -- null (normalizeInn не пропустит)', null, $matcher->findIssuerIdBySpvLink('770833569X'));

echo "\n--- Часть 2: связка в импортёре (NkrImporter::resolveIssuerId()) ---\n";

function callPrivate(object $obj, string $method, array $args): mixed
{
    $ref = new ReflectionClass($obj);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($obj, $args);
}

$db = makeDbWithLink();
$linkMatcher = new IssuerMatcher($db);
$importer = new NkrImporter($db, $linkMatcher, new NameMatchResolver($linkMatcher, new NameMatchReviews($db)));

check(
    'Строка с "неизвестным" ИНН -- находит issuer_id через issuer_spv_links',
    1,
    callPrivate($importer, 'resolveIssuerId', ['7708335695', 'ООО «ЕВА»', 'Полная выгрузка НКР: ООО «ЕВА»', null])
);

// Связка считается наравне с прямым ИНН: ПОСЛЕДУЮЩАЯ строка, которая
// нашла бы тот же issuer_id только "по корню", не даёт issuer_id и не
// создаёт предложения (см. tests/test_root_priority_conflict.php).
check(
    'Гипотетическая ПОЗЖЕ строка, находящая тот же issuer_id по корню, -- null',
    null,
    callPrivate($importer, 'resolveIssuerId', ['0000000000', 'ФСК Активы Капитал', 'Полная выгрузка НКР: ФСК Активы Капитал', null])
);
check(
    '…и предложения по названию не создано',
    0,
    (int) $db->query('SELECT COUNT(*) FROM issuer_name_match_reviews')->fetchColumn()
);

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
