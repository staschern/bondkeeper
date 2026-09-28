<?php

declare(strict_types=1);

/**
 * Офлайн-проверка предложений "сопоставить по названию" (миграция 024,
 * решение пользователя, сентябрь 2026): NameMatchReviews,
 * NameMatchResolver, IssuerMatcher::findIssuerIdByApprovedName().
 * Всё на SQLite in-memory — SQL в этих классах переносимый.
 *
 * Живые случаи из разбора сверки:
 *   - ООО «Озон» (фармацевтика, ИНН 6345002063) ← ООО «ОЗОН Капитал» (ИНН
 *     6949003359): корень совпал, компании разные — предложение, после
 *     отклонения больше не предлагается; ← ООО «ОЗОН Банк» (ИНН
 *     9703077050): «Банк» снимается нормализацией, названия совпадают
 *     целиком — тёзка, не предлагается вовсе;
 *   - ЗАО «Прогресс» (Курская обл., ИНН 4622004142) ← АО «ПРОГРЕСС»
 *     (Липецк, ИНН 4826022365): тёзки — не предлагается вовсе;
 *   - АО «Аэрофьюэлз» ← ООО «Аэрофьюэлз Групп»: верное совпадение —
 *     после подтверждения становится связкой issuer_spv_links.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_name_match_reviews.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\NameMatchResolver;
use BondKeeper\Ratings\NameMatchReviews;

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
    $pdo->exec(
        'CREATE TABLE current_ratings (issuer_id INTEGER, agency TEXT, rating TEXT, outlook TEXT, last_action_date TEXT,
            matched_by_root_name INTEGER DEFAULT 0, source TEXT, PRIMARY KEY (issuer_id, agency))'
    );

    $issuers = [
        [1, 'Общество с ограниченной ответственностью "Озон"', 'ООО "Озон"', '6345002063'],
        [2, 'Закрытое акционерное общество "Прогресс"', 'ЗАО "Прогресс"', '4622004142'],
        [3, 'Акционерное общество "Аэрофьюэлз"', 'АО "Аэрофьюэлз"', '7714216826'],
        [4, 'Общество с ограниченной ответственностью "Борец Капитал"', 'ООО "Борец Капитал"', '7708797192'],
    ];
    $stmt = $pdo->prepare('INSERT INTO issuers (id, full_name, short_name, inn) VALUES (:id, :full_name, :short_name, :inn)');
    foreach ($issuers as [$id, $full, $short, $inn]) {
        $stmt->execute(['id' => $id, 'full_name' => $full, 'short_name' => $short, 'inn' => $inn]);
    }

    return $pdo;
}

function countRows(PDO $db, string $where = '1=1'): int
{
    return (int) $db->query("SELECT COUNT(*) FROM issuer_name_match_reviews WHERE {$where}")->fetchColumn();
}

echo "--- propose(): новое предложение, повтор, тёзки ---\n";

$db = makeDb();
$reviews = new NameMatchReviews($db);

$status = $reviews->propose('expert_ra', 'root_name', '6949003359', 'ООО «ОЗОН Капитал»', 1, 'Эксперт РА подтвердил рейтинг ООО «ОЗОН Капитал»', 'https://raexpert.ru/releases/ozon/');
check('Озон ← ОЗОН Капитал: новое предложение', NameMatchReviews::PROPOSED, $status);
$row = $db->query('SELECT * FROM issuer_name_match_reviews')->fetch();
check('ключ — ИНН источника', ['inn', '6949003359', 'pending'], [$row['source_key_type'], $row['source_key'], $row['status']]);
check('сохранены заголовок и ссылка', ['Эксперт РА подтвердил рейтинг ООО «ОЗОН Капитал»', 'https://raexpert.ru/releases/ozon/'], [$row['source_title'], $row['source_url']]);

check('та же пара второй раз — уже ждёт решения, новой строки нет', NameMatchReviews::PENDING, $reviews->propose('expert_ra', 'root_name', '6949003359', 'ООО «ОЗОН Капитал»', 1, 'другая новость', null));
check('строк по-прежнему одна', 1, countRows($db));

// «Банк» normalizeCompanyName() снимает как форму — «ОЗОН Банк» с другим
// ИНН по названию целиком совпадает с «Озон», это тёзка, не мать/SPV.
check(
    'Озон ← ОЗОН Банк (другой ИНН, после нормализации то же название) — тёзка, не предлагается',
    NameMatchReviews::NAMESAKE,
    $reviews->propose('nra', 'root_name', '9703077050', 'ООО «ОЗОН Банк»', 1, 'НРА подтвердило рейтинг ООО «ОЗОН Банк»', null)
);
check(
    'Прогресс: АО «ПРОГРЕСС» (другой ИНН, то же название) — тёзка, не предлагается',
    NameMatchReviews::NAMESAKE,
    $reviews->propose('nra', 'exact_name', '4826022365', 'АО «ПРОГРЕСС»', 2, 'НРА присвоило рейтинг АО «ПРОГРЕСС»', null)
);
check('для тёзки строка не создана', 0, countRows($db, 'issuer_id = 2'));

check('без ИНН и без названия — нечем идентифицировать', NameMatchReviews::SKIPPED, $reviews->propose('nkr', 'exact_name', null, '«»', 3, null, null));

echo "\n--- reject(): больше не предлагается, чистит старую root-строку current_ratings ---\n";

$db->exec("INSERT INTO current_ratings VALUES (1, 'expert_ra', 'ruA', 'stable', '2026-04-06', 1, NULL)");
$db->exec("INSERT INTO current_ratings VALUES (1, 'nkr', 'A+.ru', 'stable', '2026-05-01', 1, NULL)");
$id = (int) $db->query("SELECT id FROM issuer_name_match_reviews WHERE source_key = '6949003359'")->fetchColumn();
$rejected = $reviews->reject($id);
check('статус rejected', NameMatchReviews::REJECTED, $rejected['status']);
check('удалена root-строка current_ratings этого агентства (expert_ra)', 1, $rejected['removed_current_ratings']);
check('строка другого агентства (nkr) не тронута', 1, (int) $db->query("SELECT COUNT(*) FROM current_ratings WHERE issuer_id = 1 AND agency = 'nkr'")->fetchColumn());
check('после отклонения пара больше не предлагается', NameMatchReviews::REJECTED, $reviews->propose('expert_ra', 'root_name', '6949003359', 'ООО «ОЗОН Капитал»', 1, 'новая новость', null));

echo "\n--- approve(): источник с ИНН → связка issuer_spv_links ---\n";

$reviews->propose('nkr', 'root_name', '7710380617', 'ООО «Аэрофьюэлз Групп»', 3, 'Полная выгрузка НКР: ООО «Аэрофьюэлз Групп» — рейтинг A.ru', 'https://ratings.ru/ratings/issuers/');
$id = (int) $db->query("SELECT id FROM issuer_name_match_reviews WHERE source_key = '7710380617'")->fetchColumn();
$approved = $reviews->approve($id);
check('статус approved', NameMatchReviews::APPROVED, $approved['status']);
$matcher = new IssuerMatcher($db);
check('ИНН Аэрофьюэлз Групп теперь сопоставляется напрямую через связку', 3, $matcher->findIssuerIdBySpvLink('7710380617'));
check('повторное отклонение снимает связку', null, (function () use ($reviews, $id, $matcher): ?int {
    $reviews->reject($id);
    return $matcher->findIssuerIdBySpvLink('7710380617');
})());

echo "\n--- источник без ИНН → подтверждённое название ---\n";

$db = makeDb();
$reviews = new NameMatchReviews($db);
$matcher = new IssuerMatcher($db);
$resolver = new NameMatchResolver($matcher, $reviews);

$result = $resolver->resolve('nkr', null, ['Борец Капитал'], 'НКР подтвердило рейтинг ООО «ПК «Борец» и ООО «Борец Капитал»', 'https://ratings.ru/news/1');
check('до подтверждения: issuer_id не возвращается, создано 1 предложение', ['issuerId' => null, 'proposed' => 1], $result);
$row = $db->query('SELECT * FROM issuer_name_match_reviews')->fetch();
check('ключ — нормализованное название, тип exact_name', ['name', IssuerMatcher::nameKey('Борец Капитал'), 'exact_name'], [$row['source_key_type'], $row['source_key'], $row['match_type']]);
$reviews->approve((int) $row['id']);
check('после подтверждения: findIssuerIdByApprovedName()', 4, $matcher->findIssuerIdByApprovedName('ООО «Борец Капитал»'));
check('после подтверждения: resolve() возвращает issuer_id без новых предложений', ['issuerId' => 4, 'proposed' => 0], $resolver->resolve('nkr', null, ['Борец Капитал'], 't', null));
check('подтверждённое название НЕ применяется к источнику с ИНН (там решает связка)', null, $resolver->findApproved('7715265054', ['Борец Капитал']));
check('findApproved() без ИНН — находит', 4, $resolver->findApproved(null, ['«Борец Капитал»']));

echo "\n--- resolve(): skip уже сопоставленных напрямую, корень vs точное имя ---\n";

$db = makeDb();
$reviews = new NameMatchReviews($db);
$resolver = new NameMatchResolver(new IssuerMatcher($db), $reviews);
check(
    'issuer_id уже сопоставлен по ИНН в этом прогоне — по названию не предлагаем',
    ['issuerId' => null, 'proposed' => 0],
    $resolver->resolve('nkr', '7710380617', ['ООО «Аэрофьюэлз Групп»'], 't', null, [3 => true])
);
check('и строки в таблице нет', 0, countRows($db));
$resolver->resolve('nkr', '7710380617', ['ООО «Аэрофьюэлз Групп»'], 't', null);
check('совпадение по корню помечено root_name', 'root_name', $db->query('SELECT match_type FROM issuer_name_match_reviews')->fetchColumn());
check('несовпадающее название — ничего не предлагается', ['issuerId' => null, 'proposed' => 0], $resolver->resolve('nkr', '1234567890', ['ООО «Совсем другое»'], 't', null));

echo "\n--- formatProposal(): заголовок, ссылка, оба названия, команды ---\n";

$pending = $reviews->listPending();
check('listPending(): одно ждущее предложение', 1, count($pending));
$text = NameMatchReviews::formatProposal($pending[0]);
foreach (
    [
        'агентство и тип' => 'НКР, совпадение по корню',
        'название у агентства + ИНН' => 'У агентства: ООО «Аэрофьюэлз Групп», ИНН 7710380617',
        'наша компания + ИНН' => 'У нас: АО "Аэрофьюэлз", issuer_id=3, ИНН 7714216826',
        'заголовок' => 'Заголовок: «t»',
        'ссылка (нет — прочерк)' => 'Ссылка: —',
        'команда подтверждения' => 'php bin/review_matches.php --approve=' . $pending[0]['id'],
        'команда отклонения' => 'php bin/review_matches.php --reject=' . $pending[0]['id'],
    ] as $label => $needle
) {
    check("formatProposal(): {$label}", true, str_contains($text, $needle));
}

echo "\n--- notifyNewProposals(): каждое предложение — один раз, лимит, сбой отправки ---\n";

$db = makeDb();
$reviews = new NameMatchReviews($db);
$reviews->propose('expert_ra', 'root_name', '6949003359', 'ООО «ОЗОН Капитал»', 1, 'новость 1', 'https://x/1');
$reviews->propose('nkr', 'root_name', '7710380617', 'ООО «Аэрофьюэлз Групп»', 3, 'новость 2', 'https://x/2');

$failing = static fn (string $text): bool => false;
check('отправка не удалась — 0 отправлено', 0, $reviews->notifyNewProposals($failing));
check('notified_at не проставлен', 2, countRows($db, 'notified_at IS NULL'));

$sentTexts = [];
$sender = static function (string $text) use (&$sentTexts): bool {
    $sentTexts[] = $text;
    return true;
};
check('лимит 1: отправлено одно предложение', 1, $reviews->notifyNewProposals($sender, 1));
check('лимит 1: плюс сообщение «ещё N»', true, str_contains($sentTexts[1] ?? '', 'Ещё предложений на подтверждение: 1'));
check('второй вызов — досылает оставшееся', 1, $reviews->notifyNewProposals($sender));
check('третий вызов — отправлять нечего', 0, $reviews->notifyNewProposals($sender));
check('в сообщениях — заголовки и ссылки', true, str_contains($sentTexts[0], 'Заголовок: «новость 1»') && str_contains($sentTexts[0], 'Ссылка: https://x/1'));

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
