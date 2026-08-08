<?php

declare(strict_types=1);

/**
 * Диагностический инструмент: печатает сырой ответ ISS API по одной или
 * нескольким бумагам (description + boards), плюс распарсенную карту
 * "имя поля => значение" из description. Нужен, когда SecuritiesImporter
 * не может резолвить какое-то поле (чаще всего — ИНН эмитента) — чтобы
 * увидеть, как поле называется в реальном ответе, а не гадать.
 *
 * Запуск:
 *   php bin/debug_iss_security.php RU000A107QM0
 *   php bin/debug_iss_security.php RU000A107QM0 RU000A107R29 RU000A107RW7
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

    $response = $iss->getJson("/securities/{$isin}.json", ['iss.only' => 'description,boards']);

    echo "--- сырой JSON (как есть) ---\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    $description = IssClient::block($response, 'description');
    echo "\n--- description как name => value (после разбора columns/data) ---\n";
    foreach ($description as $row) {
        $name = $row['name'] ?? '?';
        $value = $row['value'] ?? '';
        $marker = preg_match('/EMIT|ISSU|ИНН|INN/i', (string) $name) ? '  <-- похоже на эмитента' : '';
        printf("  %-25s = %s%s\n", $name, $value, $marker);
    }
    if ($description === []) {
        echo "  (пусто — блок description не пришёл вообще, см. сырой JSON выше)\n";
    }

    $boards = IssClient::block($response, 'boards');
    echo "\n--- boards ---\n";
    foreach ($boards as $board) {
        echo '  ' . json_encode($board, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
