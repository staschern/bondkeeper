<?php

declare(strict_types=1);

/**
 * Офлайн-проверка сопоставления в полных импортёрах current_ratings
 * (NkrImporter, ExpertRaImporter, AcraImporter) после решения
 * пользователя (сентябрь 2026): совпадение по названию (точное или "по
 * корню") без подтверждения администратора НЕ даёт issuer_id — только
 * предложение в issuer_name_match_reviews.
 *
 * Реальный случай: АО «Аэрофьюэлз» (ИНН 7714216826) есть в issuers,
 * ООО «Аэрофьюэлз Групп» (ИНН 7710380617) — нет, у агентства рейтинг у
 * неё. Проверяется:
 *   1. строка «Аэрофьюэлз Групп» → issuer_id не возвращается, создаётся
 *      предложение с заголовком (описание строки выгрузки) и ссылкой;
 *   2. прямая строка по ИНН → issuer_id как обычно;
 *   3. строка «Аэрофьюэлз Групп» ПОСЛЕ прямой строки того же эмитента в
 *      этом же прогоне → и предложения не создаётся (issuer_id уже
 *      сопоставлен напрямую — бывший "приоритет ИНН над корнем");
 *   4. после подтверждения предложения → связка, строка сопоставляется
 *      напрямую;
 *   5. Эксперт РА: карточка не открылась (ИНН не увидели) → ни issuer_id,
 *      ни предложения.
 *
 * Через Reflection на приватном resolveIssuerId() — без сети и без
 * скачивания выгрузок.
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

function makeDb(): PDO
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
    $pdo->exec('CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, matched_by_root_name INTEGER DEFAULT 0)');
    $pdo->prepare('INSERT INTO issuers (id, full_name, short_name, inn) VALUES (1, :full_name, :short_name, :inn)')
        ->execute(['full_name' => 'Акционерное общество "Аэрофьюэлз"', 'short_name' => 'АО "Аэрофьюэлз"', 'inn' => '7714216826']);

    return $pdo;
}

function callPrivate(object $obj, string $method, array $args): mixed
{
    $m = (new ReflectionClass($obj))->getMethod($method);
    $m->setAccessible(true);

    return $m->invokeArgs($obj, $args);
}

/** @return array{0: object, 1: PDO, 2: callable(?string, string, bool=): ?int} */
function makeImporter(string $label): array
{
    $db = makeDb();
    $matcher = new IssuerMatcher($db);
    $resolver = new NameMatchResolver($matcher, new NameMatchReviews($db));

    [$importer, $resolve] = match ($label) {
        'NkrImporter' => [
            $imp = new NkrImporter($db, $matcher, $resolver),
            static fn (?string $inn, string $name, bool $cardFailed = false): ?int => callPrivate($imp, 'resolveIssuerId', [(string) $inn, $name, 'Полная выгрузка НКР: ' . $name, 'https://ratings.ru/ratings/issuers/']),
        ],
        'ExpertRaImporter' => [
            $imp = new ExpertRaImporter($db, $matcher, new ExpertRaClient(), $resolver),
            static fn (?string $inn, string $name, bool $cardFailed = false): ?int => callPrivate($imp, 'resolveIssuerId', [$inn, $name, 'Полная выгрузка Эксперт РА: ' . $name, 'https://raexpert.ru/database/companies/x/', $cardFailed]),
        ],
        'AcraImporter' => [
            $imp = new AcraImporter($db, $matcher, $resolver),
            static fn (?string $inn, string $name, bool $cardFailed = false): ?int => callPrivate($imp, 'resolveIssuerId', [$inn, $name, 'Выгрузка АКРА (JSON-файл): ' . $name, 'https://www.acra-ratings.ru/ratings/issuers/1/']),
        ],
    };

    return [$importer, $db, $resolve];
}

$proposals = static fn (PDO $db): int => (int) $db->query('SELECT COUNT(*) FROM issuer_name_match_reviews')->fetchColumn();

foreach (['NkrImporter', 'ExpertRaImporter', 'AcraImporter'] as $label) {
    echo "--- {$label} ---\n";

    [, $db, $resolve] = makeImporter($label);
    check("{$label}: «Аэрофьюэлз Групп» без подтверждения — issuer_id НЕ возвращается", null, $resolve('7710380617', 'ООО «Аэрофьюэлз Групп»'));
    $row = $db->query('SELECT source_key, issuer_id, match_type, source_title, source_url FROM issuer_name_match_reviews')->fetch();
    check("{$label}: вместо этого — предложение (ИНН источника → issuer_id=1, по корню)", ['7710380617', '1', 'root_name'], [$row['source_key'], (string) $row['issuer_id'], $row['match_type']]);
    check("{$label}: у предложения есть заголовок и ссылка", true, $row['source_title'] !== null && $row['source_url'] !== null);

    (new NameMatchReviews($db))->approve(1);
    check("{$label}: после подтверждения — сопоставляется через связку", 1, $resolve('7710380617', 'ООО «Аэрофьюэлз Групп»'));

    [, $db, $resolve] = makeImporter($label);
    check("{$label}: прямая строка по ИНН — issuer_id найден", 1, $resolve('7714216826', 'Акционерное общество "Аэрофьюэлз"'));
    check("{$label}: «Аэрофьюэлз Групп» ПОСЛЕ прямой строки — null", null, $resolve('7710380617', 'ООО «Аэрофьюэлз Групп»'));
    check("{$label}: …и предложения не создано (эмитент уже сопоставлен напрямую)", 0, $proposals($db));
}

echo "--- ExpertRaImporter: карточка не открылась ---\n";
[, $db, $resolve] = makeImporter('ExpertRaImporter');
check('ИНН не увидели — issuer_id нет', null, $resolve(null, 'ООО «Аэрофьюэлз Групп»', true));
check('…и предложения по названию нет (у компании может быть ИНН)', 0, $proposals($db));

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
