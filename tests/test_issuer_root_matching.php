<?php

declare(strict_types=1);

/**
 * Офлайн-проверка третьего уровня сопоставления IssuerMatcher — "по
 * корню" названия (миграция 021, см. её докблок и докблок
 * IssuerMatcher::findIssuerIdByRootName()): по прямому запросу
 * пользователя (сентябрь 2026) закрывает кейс "в issuers заведена
 * только SPV (например, «Ростелеком Финанс»), а источник называет
 * только материнскую компанию («Ростелеком»)" — и наоборот.
 *
 * Часть 1 — чистая текстовая логика rootCompanyName() (без БД). Часть 2
 * — findIssuerIdByRootName() целиком, через SQLite in-memory (SELECT —
 * простой, портируемый SQL, тот же приём, что уже используется для
 * findIssuerIdByInn()/findIssuerIdByName() в этом классе — MySQL-
 * специфика есть только у writer'ов с ON DUPLICATE KEY UPDATE, не у
 * самого IssuerMatcher).
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_issuer_root_matching.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Ratings\IssuerMatcher;

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

echo "--- Часть 1: rootCompanyName() — чистая текстовая логика ---\n";

check(
    'Маркер отдельным словом отбрасывается целиком',
    'РОСТЕЛЕКОМ',
    IssuerMatcher::rootCompanyName('Ростелеком Финанс')
);
check(
    'Маркер слитным суффиксом отрезается от токена',
    'ГАЗПРОМ',
    IssuerMatcher::rootCompanyName('ГазпромКапитал')
);
check(
    'ОПФ + кавычки снимаются тоже (как и раньше, normalizeCompanyName)',
    'РОСТЕЛЕКОМ',
    IssuerMatcher::rootCompanyName('ПАО «Ростелеком»')
);
check(
    'ОПФ + кавычки + маркер одновременно — та же материнская компания',
    'РОСТЕЛЕКОМ',
    IssuerMatcher::rootCompanyName('ПАО «Ростелеком Финанс»')
);
check(
    'Симметрия: обе формы дают ОДИН И ТОТ ЖЕ корень',
    IssuerMatcher::rootCompanyName('Ростелеком'),
    IssuerMatcher::rootCompanyName('Ростелеком Финанс')
);
check(
    'Компания, целиком совпадающая со словом-маркером — пустой корень (не считаем)',
    '',
    IssuerMatcher::rootCompanyName('ООО «Финанс»')
);
check(
    'После снятия маркера остался слишком короткий обрубок (< 3 симв.) — пустой корень',
    '',
    IssuerMatcher::rootCompanyName('АО «ПЗ Капитал»')
);
check(
    'Обычное название без маркера — просто обычная нормализация, длиннее порога',
    'РОМАШКА',
    IssuerMatcher::rootCompanyName('ООО «Ромашка»')
);
check(
    'Реальный найденный случай — "Групп" (не "Группа", другой токен) тоже маркер SPV',
    'АЭРОФЬЮЭЛЗ',
    IssuerMatcher::rootCompanyName('ООО «Аэрофьюэлз Групп»')
);
check(
    'Симметрия для реального случая — материнская компания даёт тот же корень',
    IssuerMatcher::rootCompanyName('ООО «Аэрофьюэлз Групп»'),
    IssuerMatcher::rootCompanyName('Акционерное общество "Аэрофьюэлз"')
);
check(
    'Пустая строка на входе — пустой корень',
    '',
    IssuerMatcher::rootCompanyName('')
);
check(
    'Полностью прописанная ОПФ (не только аббревиатура) тоже снимается',
    'РОСТЕЛЕКОМ',
    IssuerMatcher::rootCompanyName('Публичное акционерное общество «Ростелеком»')
);
check(
    'Полная ОПФ + маркер SPV одновременно',
    'РОСТЕЛЕКОМ',
    IssuerMatcher::rootCompanyName('Общество с ограниченной ответственностью «Ростелеком Финанс»')
);
check(
    'Полная ОПФ хвостом в скобках (формат ИСС для Газпромбанка и т.п.)',
    'ГАЗПРОМБАНК',
    IssuerMatcher::rootCompanyName('"Газпромбанк" (Акционерное общество)')
);
check(
    'Симметрия: аббревиатура и полная форма дают ОДИН И ТОТ ЖЕ корень',
    IssuerMatcher::rootCompanyName('ПАО «Ростелеком»'),
    IssuerMatcher::rootCompanyName('Публичное акционерное общество «Ростелеком»')
);
check(
    '"Международная компания" сознательно НЕ добавлена в regex-снятие (риск схлопнуть реальное короткое название "МК") — просто не даёт идеального сокращения, но не портит чужие данные',
    'МК АЛЬФА',
    IssuerMatcher::rootCompanyName('Международная компания «Альфа»')
);

echo "\n--- Часть 2: findIssuerIdByRootName() — целиком, через SQLite in-memory ---\n";

function makeMatcher(array $issuers): IssuerMatcher
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE issuers (id INTEGER PRIMARY KEY, full_name TEXT, short_name TEXT, inn TEXT)');
    $stmt = $pdo->prepare('INSERT INTO issuers (id, full_name, short_name, inn) VALUES (:id, :full_name, :short_name, :inn)');
    foreach ($issuers as $row) {
        $stmt->execute([
            'id' => $row['id'],
            'full_name' => $row['full_name'],
            'short_name' => $row['short_name'] ?? $row['full_name'],
            'inn' => $row['inn'] ?? null,
        ]);
    }

    return new IssuerMatcher($pdo);
}

// Кейс пользователя: в issuers заведена ТОЛЬКО SPV, источник называет
// ТОЛЬКО материнскую компанию.
$matcher = makeMatcher([
    ['id' => 1, 'full_name' => 'Публичное акционерное общество «Ростелеком Финанс»', 'short_name' => 'ПАО «Ростелеком Финанс»'],
    ['id' => 2, 'full_name' => 'Общество с ограниченной ответственностью «Ромашка»', 'short_name' => 'ООО «Ромашка»'],
]);

check(
    'ИНН/точное имя не помогли бы (проверка предпосылки) — по имени "Ростелеком" ничего нет',
    null,
    $matcher->findIssuerIdByName('Ростелеком')
);
check(
    'По корню — "Ростелеком" (материнская, только слово) находит SPV в базе',
    1,
    $matcher->findIssuerIdByRootName('Ростелеком')
);
check(
    'По корню — работает и в обратную сторону (кандидат с ОПФ и маркером)',
    1,
    $matcher->findIssuerIdByRootName('ПАО «Ростелеком»')
);
check(
    'По корню — несвязанная компания не подтягивается',
    null,
    $matcher->findIssuerIdByRootName('Сбербанк')
);
check(
    'По корню — слово-маркер само по себе не матчит ничего (пустой корень)',
    null,
    $matcher->findIssuerIdByRootName('Финанс')
);

// Неоднозначность: ДВЕ разные компании в базе сводятся к одному корню —
// та же политика "не угадываем", что и у findIssuerIdByName().
$ambiguousMatcher = makeMatcher([
    ['id' => 10, 'full_name' => 'ООО «Ростелеком Финанс»'],
    ['id' => 11, 'full_name' => 'АО «Ростелеком Капитал»'],
]);
check(
    'Неоднозначность (два разных issuer_id дают один корень) — не угадываем, null',
    null,
    $ambiguousMatcher->findIssuerIdByRootName('Ростелеком')
);

// Практический случай, ради которого добавлено снятие ПОЛНОЙ формы ОПФ
// (не только аббревиатуры): full_name в issuers хранит полную форму, а
// short_name ПОКА не сокращён/совпадает с full_name (реальный сценарий
// до IssuerNameShortener, см. её докблок) — раньше root-сопоставление
// работало только если short_name уже был в аббревиатурной форме.
$fullFormOnlyMatcher = makeMatcher([
    ['id' => 30, 'full_name' => 'Публичное акционерное общество «Ростелеком Финанс»', 'short_name' => 'Публичное акционерное общество «Ростелеком Финанс»'],
]);
check(
    'Полная форма ОПФ в обоих полях (full_name=short_name) — root всё равно находит SPV',
    30,
    $fullFormOnlyMatcher->findIssuerIdByRootName('Ростелеком')
);

// Обратное направление: в issuers — материнская компания, источник
// называет SPV.
$reverseMatcher = makeMatcher([
    ['id' => 20, 'full_name' => 'Публичное акционерное общество «Магнит»', 'short_name' => 'ПАО «Магнит»'],
]);
check(
    'Обратное направление — в базе материнская компания, источник называет SPV',
    20,
    $reverseMatcher->findIssuerIdByRootName('Магнит Капитал')
);

// Реальный найденный случай (сентябрь 2025): АО «Аэрофьюэлз» — в issuers
// (есть облигации на МосБирже), ООО «Аэрофьюэлз Групп» (SPV, ИНН
// 7710380617) — в issuers НЕТ вообще. Будущее действие НКР только по
// SPV (без повторного упоминания материнской в том же заголовке) должно
// найти материнскую компанию по корню.
$aeroflightsMatcher = makeMatcher([
    ['id' => 40, 'full_name' => 'Акционерное общество "Аэрофьюэлз"', 'short_name' => 'АО "Аэрофьюэлз"'],
]);
check(
    'Реальный найденный случай — SPV "Аэрофьюэлз Групп" находит материнскую "Аэрофьюэлз" в базе',
    40,
    $aeroflightsMatcher->findIssuerIdByRootName('ООО «Аэрофьюэлз Групп»')
);

echo "\n";
if ($failures > 0) {
    echo "ИТОГО: {$failures} из {$checks} ПРОВАЛЕНО.\n";
    exit(1);
}
echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
