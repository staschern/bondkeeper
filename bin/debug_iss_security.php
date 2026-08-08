<?php

declare(strict_types=1);

/**
 * Диагностический инструмент: печатает сырой ответ ISS API по одной или
 * нескольким бумагам (description + boards), плюс распарсенную карту
 * "имя поля => значение" из description. Нужен, когда SecuritiesImporter
 * не может резолвить какое-то поле (чаще всего — ИНН эмитента) — чтобы
 * увидеть, как поле называется в реальном ответе, а не гадать.
 *
 * По итогам первого прогона на bondkeeper.ru выяснилось: в description
 * нет прямого поля с ИНН — только EMITTER_ID (внутренний числовой ID
 * эмитента на Мосбирже). Поэтому скрипт теперь дополнительно:
 *   1. Запрашивает /securities/{ISIN}.json БЕЗ iss.only — печатает список
 *      всех доступных блоков (вдруг что-то отсекли параметром iss.only).
 *   2. Если находит EMITTER_ID — пробует несколько правдоподобных ISS-
 *      эндпоинтов вида /iss/emitents/{id}.json и печатает, какой из них
 *      реально отвечает и что отдаёт.
 *
 * Запуск:
 *   php bin/debug_iss_security.php RU000A107QM0
 *   php bin/debug_iss_security.php RU000A107QM0 RU000A107R29
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Iss\IssClient;

$isins = array_slice($argv, 1);
if ($isins === []) {
    fwrite(STDERR, "Использование: php bin/debug_iss_security.php ISIN [ISIN ...]\n");
    exit(1);
}

$iss = new IssClient();

foreach ($isins as $isin) {
    echo "\n========== {$isin} ==========\n";

    echo "--- список блоков в ответе БЕЗ iss.only (вдруг что-то отсекли фильтром) ---\n";
    $fullResponse = $iss->getJson("/securities/{$isin}.json", []);
    foreach (array_keys($fullResponse) as $blockName) {
        $rowCount = count($fullResponse[$blockName]['data'] ?? []);
        echo "  {$blockName} (строк: {$rowCount})\n";
    }
    foreach ($fullResponse as $blockName => $block) {
        if (preg_match('/emit|issu|company|firm|organ/i', (string) $blockName)) {
            echo "\n  >>> блок '{$blockName}' похож на данные эмитента, печатаю целиком:\n";
            echo '  ' . json_encode($block, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }
    }

    $description = IssClient::block($fullResponse, 'description');
    echo "\n--- description как name => value ---\n";
    $emitterId = null;
    foreach ($description as $row) {
        $name = $row['name'] ?? '?';
        $value = $row['value'] ?? '';
        $marker = preg_match('/EMIT|ISSU|ИНН|INN/i', (string) $name) ? '  <-- похоже на эмитента' : '';
        printf("  %-25s = %s%s\n", $name, $value, $marker);
        if (strtoupper((string) $name) === 'EMITTER_ID') {
            $emitterId = (string) $value;
        }
    }

    $boards = IssClient::block($fullResponse, 'boards');
    echo "\n--- boards (только is_primary=1) ---\n";
    foreach ($boards as $board) {
        if (($board['is_primary'] ?? 0) == 1) {
            echo '  ' . json_encode($board, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }

    if ($emitterId === null) {
        echo "\n(EMITTER_ID не найден в description — карточку эмитента пробовать не по чему)\n";
        continue;
    }

    echo "\n--- пробую найти карточку эмитента по EMITTER_ID={$emitterId} ---\n";
    $candidates = [
        "/emitents/{$emitterId}.json",
        "/emitents/{$emitterId}/securities.json",
        "/statistics/engines/stock/emitents/{$emitterId}.json",
    ];
    foreach ($candidates as $path) {
        echo "  пробую {$path} ... ";
        try {
            $resp = $iss->getJson($path, []);
            $blocks = array_keys($resp);
            if ($blocks === []) {
                echo "пусто\n";
                continue;
            }
            echo "ОТВЕТИЛ, блоки: " . implode(', ', $blocks) . "\n";
            echo '  ' . json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (\Throwable $e) {
            echo 'ошибка: ' . $e->getMessage() . "\n";
        }
    }
}
