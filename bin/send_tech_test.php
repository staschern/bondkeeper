<?php

declare(strict_types=1);

/**
 * Проверка адресата технических сообщений (11.10.2026).
 *
 * Сообщения о сбоях (упал скрипт по расписанию, в базе нет нужной
 * структуры, ошибка запроса к НРД) уходят разработчику — на
 * tech_telegram_id из config/telegram_bot.php; если он не задан —
 * администратору. Скрипт отправляет одно пробное сообщение и пишет, кому
 * оно ушло.
 *
 *   php bin/send_tech_test.php
 *
 * Разработчик должен заранее один раз нажать «Старт» в боте: иначе
 * Telegram не даст боту написать ему первым.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Telegram\AdminNotifier;
use BondKeeper\Telegram\TelegramBotConfig;

$config = TelegramBotConfig::fromFile(__DIR__ . '/../config/telegram_bot.php');
[$chatId, $who] = AdminNotifier::recipient($config, true);

if ($chatId === 0) {
    echo "Не заданы ни tech_telegram_id, ни admin_telegram_id в config/telegram_bot.php — отправлять некому.\n";
    exit(1);
}
if ($config->techTelegramId === 0) {
    echo "tech_telegram_id не задан — технические сообщения уходят администратору.\n";
}

$sent = AdminNotifier::sendTech('🛠 Проверка: сюда приходят сообщения BondKeeper о технических сбоях. ' . date('d.m.Y H:i'));
echo $sent
    ? "Пробное сообщение отправлено {$who} (Telegram ID {$chatId}).\n"
    : "Не удалось отправить {$who} (Telegram ID {$chatId}) — причина в логе выше. Чаще всего получатель не нажал «Старт» в боте.\n";
exit($sent ? 0 : 1);
