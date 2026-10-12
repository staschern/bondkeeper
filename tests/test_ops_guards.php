<?php

declare(strict_types=1);

/**
 * Офлайн-проверка «страховок» для скриптов по расписанию (11.10.2026):
 *   - CronGuard — сообщение о сбое разработчику, без повторов;
 *   - AdminNotifier::recipient — кому уходит техническое сообщение;
 *   - PaymentSchemaCheck — есть ли в базе нужные типы событий;
 *   - PollLock — замок от наложения запусков опроса GetNews.
 * В сеть и в Telegram ничего не уходит: отправка подменена.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_ops_guards.php
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Payments\PaymentSchemaCheck;
use BondKeeper\Payments\PollLock;
use BondKeeper\Support\CronGuard;
use BondKeeper\Telegram\AdminNotifier;
use BondKeeper\Telegram\TelegramBotConfig;

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

$tmp = sys_get_temp_dir() . '/bk_ops_guards_' . bin2hex(random_bytes(4));
mkdir($tmp);

echo "--- кому уходит служебное сообщение ---\n";
$both = new TelegramBotConfig('token', 111, 222);
$adminOnly = new TelegramBotConfig('token', 111);
check('технический сбой — разработчику, если он указан в конфиге', [222, 'разработчику'], AdminNotifier::recipient($both, true));
check('разработчик не указан — технический сбой уходит администратору, а не теряется', [111, 'администратору'], AdminNotifier::recipient($adminOnly, true));
check('обычное служебное сообщение — всегда администратору', [[111, 'администратору'], [111, 'администратору']], [AdminNotifier::recipient($both, false), AdminNotifier::recipient($adminOnly, false)]);
check('старый конфиг без tech_telegram_id читается как «разработчик не указан»', 0, (static function () use ($tmp): int {
    file_put_contents($tmp . '/telegram_bot.php', "<?php return ['bot_token' => 't', 'admin_telegram_id' => 5];");

    return TelegramBotConfig::fromFile($tmp . '/telegram_bot.php')->techTelegramId;
})());
check('новый ключ tech_telegram_id читается', 7, (static function () use ($tmp): int {
    file_put_contents($tmp . '/telegram_bot2.php', "<?php return ['bot_token' => 't', 'admin_telegram_id' => 5, 'tech_telegram_id' => 7];");

    return TelegramBotConfig::fromFile($tmp . '/telegram_bot2.php')->techTelegramId;
})());

echo "\n--- сообщение о сбое скрипта ---\n";
$sent = [];
CronGuard::$sender = static function (string $text) use (&$sent): bool {
    $sent[] = $text;

    return true;
};
CronGuard::$stateDir = $tmp;
// Сообщения логгера в выводе теста не нужны — перехватываем.
ob_start();
$first = CronGuard::report('payment_reminders', 'SQLSTATE[23000]: нет типа события R1', 'EventPublisher.php:214');
$again = CronGuard::report('payment_reminders', 'SQLSTATE[23000]: нет типа события R1', 'EventPublisher.php:214');
$other = CronGuard::report('payment_reminders', 'Connection refused');
$otherScript = CronGuard::report('poll_getnews', 'SQLSTATE[23000]: нет типа события R1');
$silent = CronGuard::report('payment_checks', 'ошибка при просмотре', '', false);
ob_end_clean();
check('первый сбой — сообщение уходит', true, $first);
check('в сообщении — имя скрипта, текст ошибки и место', "🛠 Сбой: payment_reminders\nSQLSTATE[23000]: нет типа события R1\nEventPublisher.php:214", $sent[0] ?? '');
check('тот же сбой при следующем запуске — без повтора (не чаще раза в час)', false, $again);
check('другая ошибка того же скрипта — уходит', true, $other);
check('та же ошибка в другом скрипте — уходит (отметки раздельные)', true, $otherScript);
check('режим просмотра (--dry-run) — только лог, в Telegram ничего', false, $silent);
check('всего ушло сообщений: три', 3, count($sent));
// «Прошёл час»: старим отметку и повторяем тот же сбой.
$state = json_decode((string) file_get_contents($tmp . '/cron_guard_poll_getnews.json'), true);
$state['sent_at'] = time() - 3700;
file_put_contents($tmp . '/cron_guard_poll_getnews.json', json_encode($state));
ob_start();
$later = CronGuard::report('poll_getnews', 'SQLSTATE[23000]: нет типа события R1');
ob_end_clean();
check('через час тот же сбой напоминает о себе снова', true, $later);
// Telegram сообщение не принял — отметка не ставится, следующий запуск пробует снова.
CronGuard::$sender = static fn (string $text): bool => false;
ob_start();
$failed = CronGuard::report('payment_checks', 'Connection timed out');
CronGuard::$sender = static function (string $text) use (&$sent): bool {
    $sent[] = $text;

    return true;
};
$retried = CronGuard::report('payment_checks', 'Connection timed out');
ob_end_clean();
check('сообщение не ушло — при следующем запуске попытка повторяется', [false, true], [$failed, $retried]);

echo "\n--- есть ли в базе нужные типы событий ---\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE event_types (code TEXT PRIMARY KEY)');
$db->exec("INSERT INTO event_types (code) VALUES ('A2'), ('B2a'), ('B1')");
check('все типы на месте — пусто', [], PaymentSchemaCheck::missingEventTypes($db, ['A2', 'B1']));
check('нет R1 и B2b — названы оба', ['R1', 'B2b'], PaymentSchemaCheck::missingEventTypes($db, ['R1', 'A2', 'B2b']));
check('в тексте — чего нет и какую миграцию применить', 'В базе нет типов событий: R1, B1a. Нужно применить: database/027_payment_events.sql; database/028_payment_events_partial_before_due.sql.',
    PaymentSchemaCheck::describe(['R1', 'B1a']));
check('тип из начальной схемы — отсылка к ней', true, str_contains(PaymentSchemaCheck::describe(['A4']), 'database/001_schema.sql'));
$thrown = '';
try {
    PaymentSchemaCheck::requireEventTypes($db, ['R1']);
} catch (RuntimeException $e) {
    $thrown = $e->getMessage();
}
check('requireEventTypes останавливает скрипт понятной ошибкой', true, str_contains($thrown, 'В базе нет типов событий: R1'));
PaymentSchemaCheck::requireEventTypes($db, ['A2']);
check('…и молчит, когда всё на месте', true, true);

echo "\n--- замок от наложения запусков опроса ---\n";
$lockFile = $tmp . '/var/getnews_poll.lock';
$lock = PollLock::acquire($lockFile);
check('первый запуск занимает замок (папка var создаётся сама)', [true, true], [$lock !== null, is_file($lockFile)]);
check('второй запуск, пока первый работает, замок не получает', null, PollLock::acquire($lockFile));
check('по занятому замку видно, сколько минут идёт запуск', 0, PollLock::heldMinutes($lockFile));
touch($lockFile, time() - 45 * 60);
check('…и это число растёт: запуск, начатый 45 минут назад', 45, PollLock::heldMinutes($lockFile));
$lock->release();
$next = PollLock::acquire($lockFile);
check('после завершения первого запуска замок свободен', true, $next !== null);
$next->release();
check('замок, которого ещё не было, — возраст неизвестен', null, PollLock::heldMinutes($tmp . '/var/none.lock'));

// уборка
foreach (array_merge(glob($tmp . '/var/*') ?: [], glob($tmp . '/*') ?: []) as $file) {
    is_dir($file) ? @rmdir($file) : @unlink($file);
}
@rmdir($tmp);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
