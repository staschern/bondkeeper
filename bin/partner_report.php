<?php

declare(strict_types=1);

/**
 * Итоги партнёрской программы за месяц (docs/PARTNER_PROGRAM.md): по
 * каждому партнёру — промокод, сколько клиентов привёл (всего и за месяц,
 * по ссылке и вводом кода), сколько из них платили в этом месяце и на
 * какую сумму, какое выходит вознаграждение.
 *
 * Только читает базу, ничего не меняет и никому не пишет. То же самое
 * администратор видит в боте командой /partners.
 *
 *   php bin/partner_report.php                  # текущий месяц
 *   php bin/partner_report.php --month=2026-10  # указанный месяц
 *   php bin/partner_report.php --csv            # строки через «;» — для таблицы
 *
 * Считаются успешные платежи (payments.status = 'succeeded') с датой
 * оплаты в этом месяце; вознаграждение — сумма этих платежей, умноженная
 * на процент партнёра (partners.reward_percent). Пока оплаты в продукте
 * нет, суммы нулевые.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Partners\PartnerProgram;

$month = date('Y-m');
$csv = in_array('--csv', $argv, true);
foreach ($argv as $arg) {
    if (preg_match('/^--month=(\d{4}-\d{2})$/', $arg, $m)) {
        $month = $m[1];
    }
}

$db = Database::connection();
$problem = PartnerProgram::schemaProblem($db);
if ($problem !== null) {
    fwrite(STDERR, $problem . "\n");
    exit(1);
}

$rows = (new PartnerProgram($db))->report($month);
if ($csv) {
    echo "partner;telegram_id;promo_code;status;reward_percent;referred_total;referred_in_month;referred_by_link;referred_manual;paid_clients_total;"
        . "first_paid_in_month;paying_clients_in_month;payments_in_month;amount_in_month;reward_in_month\n";
    foreach ($rows as $row) {
        echo implode(';', [
            $row['telegram_username'] !== null ? '@' . $row['telegram_username'] : '', $row['telegram_id'], $row['promo_code'], $row['status'],
            $row['reward_percent'] ?? '', $row['referred_total'], $row['referred_in_month'], $row['referred_by_link'], $row['referred_manual'],
            $row['paid_clients_total'], $row['first_paid_in_month'], $row['paying_clients_in_month'], $row['payments_in_month'], $row['amount_in_month'], $row['reward_in_month'] ?? '',
        ]), "\n";
    }
    exit(0);
}

echo "Партнёрская программа — итоги за {$month}\n";
if ($rows === []) {
    echo "Партнёров пока нет.\n";
    exit(0);
}
$totalAmount = 0.0;
$totalReward = 0.0;
foreach ($rows as $i => $row) {
    $name = $row['telegram_username'] !== null ? '@' . $row['telegram_username'] : 'ID ' . $row['telegram_id'];
    echo "\n", $i + 1, ". {$name} (Telegram ID {$row['telegram_id']}) · промокод {$row['promo_code']}"
        . ($row['status'] === 'paused' ? ' · приостановлен' : '') . "\n";
    echo '   процент вознаграждения: ', $row['reward_percent'] !== null ? $row['reward_percent'] . ' %' : 'не задан', "\n";
    echo "   привёл клиентов: всего {$row['referred_total']} (по ссылке {$row['referred_by_link']}, ввели код сами {$row['referred_manual']}), за месяц {$row['referred_in_month']}\n";
    echo "   из приведённых хоть раз платили: {$row['paid_clients_total']}, впервые оплатили в этом месяце: {$row['first_paid_in_month']}\n";
    echo "   оплаты за месяц: клиентов {$row['paying_clients_in_month']}, платежей {$row['payments_in_month']}, сумма {$row['amount_in_month']} руб.\n";
    echo '   вознаграждение за месяц: ', $row['reward_in_month'] !== null ? $row['reward_in_month'] . ' руб.' : 'не посчитано — не задан процент', "\n";
    $totalAmount += (float) $row['amount_in_month'];
    $totalReward += (float) ($row['reward_in_month'] ?? 0);
}
echo "\nВсего: партнёров ", count($rows), ', оплат на ', number_format($totalAmount, 2, '.', ''), ' руб., вознаграждений на ', number_format($totalReward, 2, '.', ''), " руб.\n";
