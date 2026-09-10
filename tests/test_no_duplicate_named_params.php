<?php

declare(strict_types=1);

/**
 * Статическая проверка (не поведенческая, не требует БД вообще) — сканирует
 * все ->prepare(...) в src/ и bin/ и ищет один и тот же именованный
 * плейсхолдер (:name), повторённый ДВАЖДЫ в тексте ОДНОГО запроса.
 *
 * Нашлось вживую (10 сентября 2026): BotCommandHandler::searchIssuers()
 * использовал "WHERE short_name LIKE :fragment OR full_name LIKE :fragment"
 * с execute(['fragment' => ...]) — одно значение на оба вхождения. Под
 * SQLite (офлайн-тесты) это отрабатывает штатно — SQLite нативно допускает
 * повтор одного :имени. Но Database::connection() держит
 * PDO::ATTR_EMULATE_PREPARES=false (настоящие подготовленные запросы
 * MySQL) — там КАЖДОЕ вхождение плейсхолдера требует своего значения,
 * даже при одинаковом имени. execute() с одним ключом на оба вхождения
 * падает с "SQLSTATE[HY093]: Invalid parameter number". Офлайн-тест
 * (tests/test_bot_ux_screens.php) этого не поймал — тот же класс пробела,
 * что и MySQL-диалект ON DUPLICATE KEY UPDATE в Фазе 1 (см.
 * tests/test_event_engine.php): SQLite попросту НЕ воспроизводит это
 * конкретное ограничение MySQL. Единственный надёжный офлайн-способ
 * ловить именно такие баги — статически читать код, а не эмулировать
 * поведение БД. Исправлено (см. searchIssuers()): два РАЗНЫХ
 * плейсхолдера (:fragment1/:fragment2), execute() передаёт значение под
 * каждым из них.
 *
 * Запуск (из корня репозитория):
 *   php tests/test_no_duplicate_named_params.php
 * Никаких расширений (pdo_sqlite/mbstring) не требует — чистый разбор
 * текста, без обращения к БД.
 */

$failures = 0;
$checks = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
}

/** Разбирает аргумент вызова f(...), начиная сразу ПОСЛЕ открывающей "(", учитывая вложенные скобки и строки в кавычках. */
function extractCallArgument(string $content, int $startIndex): string
{
    $depth = 1;
    $i = $startIndex;
    $len = strlen($content);
    $inString = null;
    $buf = '';

    while ($i < $len && $depth > 0) {
        $c = $content[$i];
        if ($inString !== null) {
            $buf .= $c;
            if ($c === '\\') {
                $i++;
                if ($i < $len) {
                    $buf .= $content[$i];
                }
            } elseif ($c === $inString) {
                $inString = null;
            }
        } else {
            if ($c === "'" || $c === '"') {
                $inString = $c;
                $buf .= $c;
            } elseif ($c === '(') {
                $depth++;
                $buf .= $c;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                $buf .= $c;
            } else {
                $buf .= $c;
            }
        }
        $i++;
    }

    return $buf;
}

/** @return array<int, array{file: string, line: int, names: array<int, string>}> */
function findDuplicateNamedParams(array $files): array
{
    $found = [];
    foreach ($files as $path) {
        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }
        $offset = 0;
        while (($pos = strpos($content, '->prepare(', $offset)) !== false) {
            $argStart = $pos + strlen('->prepare(');
            $arg = extractCallArgument($content, $argStart);
            $offset = $argStart;

            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $arg, $m);
            $counts = array_count_values($m[1]);
            $dupes = array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));
            if ($dupes !== []) {
                $line = substr_count(substr($content, 0, $pos), "\n") + 1;
                $found[] = ['file' => $path, 'line' => $line, 'names' => $dupes];
            }
        }
    }

    return $found;
}

$root = dirname(__DIR__);
// glob() не раскрывает "**" рекурсивно сам по себе — обходим src/ вручную.
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $fileInfo) {
    if ($fileInfo->getExtension() === 'php') {
        $files[] = $fileInfo->getPathname();
    }
}
foreach (glob($root . '/bin/*.php') ?: [] as $binFile) {
    $files[] = $binFile;
}

$duplicates = findDuplicateNamedParams($files);

check('Файлов просканировано (src/ + bin/) — больше нуля', count($files) > 0, 'files=' . count($files));

if ($duplicates === []) {
    check('Ни одного ->prepare() с повторённым :именем плейсхолдера в src/+bin/', true);
} else {
    foreach ($duplicates as $d) {
        $rel = str_replace($root . '/', '', $d['file']);
        check(
            "Повтор :имени в {$rel}:{$d['line']} — " . implode(', ', $d['names']),
            false,
            'используйте разные имена плейсхолдеров на каждое вхождение (PDO::ATTR_EMULATE_PREPARES=false — MySQL native prepares)'
        );
    }
}

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
