<?php

declare(strict_types=1);

/**
 * Образцы всех уведомлений о выплатах — администратору в Telegram (Этап 5,
 * docs/STAGE5_PAYMENTS.md). Чтобы посмотреть, как сообщения выглядят у
 * клиента, и поправить формулировки, не дожидаясь настоящих событий.
 *
 * В базу ничего не пишет, клиентам ничего не отправляет: сообщения уходят
 * только на admin_telegram_id из config/telegram_bot.php. Настройки
 * config/payments.php на скрипт не влияют.
 *
 * Текст каждого клиентского образца строит тот же NotificationDispatcher,
 * что и для настоящих уведомлений (данные — из
 * PaymentNotificationSamples), с тем же оформлением (HTML, без
 * предпросмотра ссылок). Первая строка курсивом — подпись образца, в
 * настоящем уведомлении её нет. Эмитент и бумага вымышленные.
 *
 *   php bin/send_payment_samples.php              # отправить все образцы администратору
 *   php bin/send_payment_samples.php --dry-run    # показать тексты в консоли, ничего не отправляя
 *   php bin/send_payment_samples.php --only=B1,B5 # только выбранные виды событий (admin — служебные сообщения)
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Payments\PaymentNotificationSamples;
use BondKeeper\Telegram\BotFormatting;
use BondKeeper\Telegram\NotificationDispatcher;
use BondKeeper\Telegram\TelegramBotConfig;
use BondKeeper\Telegram\TelegramClient;

$dryRun = in_array('--dry-run', $argv, true);
$only = null;
foreach ($argv as $arg) {
    if (preg_match('/^--only=(.+)$/', $arg, $m)) {
        $only = array_map('trim', explode(',', $m[1]));
    }
}

$config = TelegramBotConfig::fromFile(__DIR__ . '/../config/telegram_bot.php');
if (!$dryRun && $config->adminTelegramId === 0) {
    fwrite(STDERR, "admin_telegram_id не настроен в config/telegram_bot.php — отправлять некому.\n");
    exit(1);
}
$telegram = new TelegramClient($config->botToken);
$dispatcher = new NotificationDispatcher(Database::connection(), $telegram);

/** @var array<int, array{label: string, text: string}> $messages */
$messages = [];
foreach (PaymentNotificationSamples::client() as $sample) {
    if ($only !== null && !in_array($sample['code'], $only, true)) {
        continue;
    }
    $messages[] = [
        'label' => "{$sample['code']} · {$sample['title']}",
        'text' => $dispatcher->renderText($sample['code'], $sample['payload'], PaymentNotificationSamples::ISSUER, PaymentNotificationSamples::ISSUER_INN),
    ];
}
foreach (PaymentNotificationSamples::admin() as $sample) {
    if ($only !== null && !in_array('admin', $only, true)) {
        continue;
    }
    $messages[] = [
        'label' => "только администратору · {$sample['title']}",
        'text' => BotFormatting::escapeHtml($sample['text']),
    ];
}

$total = count($messages);
$sent = 0;
foreach ($messages as $i => $message) {
    $number = $i + 1;
    $caption = "Образец {$number}/{$total} · {$message['label']}";
    if ($dryRun) {
        echo "--- {$caption} ---\n{$message['text']}\n\n";
        continue;
    }
    $ok = $telegram->sendMessage(
        $config->adminTelegramId,
        '<i>' . BotFormatting::escapeHtml($caption) . "</i>\n\n" . $message['text'],
        null,
        'HTML',
        true,
    );
    echo ($ok ? 'отправлено' : 'НЕ ОТПРАВЛЕНО') . ": {$caption}\n";
    $sent += $ok ? 1 : 0;
    sleep(1); // не чаще одного сообщения в секунду в один чат — ограничение Telegram
}

echo $dryRun ? "Образцов: {$total}. Ничего не отправлено (--dry-run).\n" : "Отправлено {$sent} из {$total}.\n";
