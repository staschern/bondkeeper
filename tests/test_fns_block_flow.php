<?php

declare(strict_types=1);

/**
 * Офлайн-проверка блокировок ФНС целиком: ответ сервиса → FnsBlocksImporter
 * → событие E1 → текст уведомления.
 *
 * Повод — жалоба от 13.10.2026 и выгрузка событий с боя за 09–13.10:
 *   1. «количество заблокированных счетов изменилось — было 0, стало 15»,
 *      хотя было 10: прежнее число счетов не попадало в данные события;
 *   2. сервис ФНС временами отдаёт не все строки или ни одной, а потом
 *      прежние — клиенты получали ложные «блокировка снята», «новая
 *      блокировка» и скачки числа счетов. Теперь снятие и число счетов
 *      объявляются только после подтверждения на следующий рабочий день;
 *   3. ответ с капчей читался как «блокировок нет»;
 *   4. ответ сервиса не сохранялся — разобрать странности было не по чему.
 *
 * Прежние тесты проверяли издателя события и текст по отдельности (текст —
 * на данных, написанных в тесте руками), поэтому расхождение между ними не
 * ловили. Здесь выполняется настоящий код импортёра; его запросы на
 * диалекте MySQL на лету переводятся под SQLite (класс MysqlOnSqlite).
 * Календарь рабочих дней — настоящий (config/working_calendar.php).
 *
 * Запуск (из корня репозитория):
 *   php -d extension=mbstring -d extension=pdo_sqlite tests/test_fns_block_flow.php
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Events\EventPublisher;
use BondKeeper\Fns\FnsBlocksImporter;
use BondKeeper\Fns\NalogBiClientInterface;
use BondKeeper\Fns\NalogBiResult;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Telegram\NotificationDispatcher;
use BondKeeper\Telegram\TelegramClientInterface;

/** Переводит запросы импортёра с диалекта MySQL на SQLite. */
final class MysqlOnSqlite extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT(issuer_id) DO UPDATE SET', $query);
        $query = (string) preg_replace('/\bVALUES\((\w+)\)/', 'excluded.$1', $query);

        return parent::prepare($query, $options);
    }
}

/** Сервис ФНС без сети: отдаёт заранее заданные ответы по очереди. */
final class ScriptedFns implements NalogBiClientInterface
{
    /** @var array<int, array<string, mixed>|Throwable> */
    public array $answers = [];

    public function check(string $inn): NalogBiResult
    {
        $answer = array_shift($this->answers);
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return NalogBiResult::fromJson($answer ?? []);
    }
}

final class NoTelegram implements TelegramClientInterface
{
    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null, bool $disableWebPagePreview = false): bool
    {
        return true;
    }

    public function sendMessageReturningId(int $chatId, string $text, ?array $replyMarkup = null): ?int
    {
        return 1;
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        return true;
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool
    {
        return true;
    }

    public function lastSendWasBlockedByUser(): bool
    {
        return false;
    }
}

$failures = 0;
$checks = 0;

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected === $actual) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n       ожидалось: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n       получено:  " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

function makeDb(bool $withPendingColumns = true): MysqlOnSqlite
{
    $db = new MysqlOnSqlite('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
    $db->exec('CREATE TABLE event_types (code TEXT PRIMARY KEY, default_priority TEXT NOT NULL)');
    $db->exec("INSERT INTO event_types VALUES ('E1', 'yellow')");
    $db->exec('CREATE TABLE raw_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, source_ref TEXT, raw_payload TEXT, processing_status TEXT, processed_at TEXT)');
    $db->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, issuer_id INTEGER, event_type_code TEXT, raw_message_id INTEGER, event_date TEXT, published_at TEXT,
        status_text TEXT, amount_actual TEXT, priority TEXT, payload_json TEXT)');
    $db->exec('CREATE TABLE fns_blocks (issuer_id INTEGER PRIMARY KEY, is_fns_blocked INTEGER NOT NULL DEFAULT 0, verification TEXT, date_verification TEXT, last_success_verification TEXT,
        active_bank_count INTEGER NOT NULL DEFAULT 0, ' . ($withPendingColumns ? 'pending_bank_count INTEGER, pending_since TEXT, ' : '') . 'decision_number TEXT, block_date TEXT,
        unblock_date TEXT, blocked_amount TEXT, reason TEXT, reason_category TEXT, source_reference TEXT, event_id INTEGER, updated_at TEXT)');

    return $db;
}

const ISSUER = ['id' => 7, 'inn' => '5017135982'];
const ISSUER_NAME = 'АО "Эффективные технологии"';
// Сообщение уходит с разметкой HTML, поэтому кавычки в названии экранированы; в Telegram клиент видит обычные кавычки.
const NAME_IN_TEXT = 'АО &quot;Эффективные технологии&quot;';

$db = makeDb();
$fns = new ScriptedFns();
$calendar = WorkingCalendar::fromFile(dirname(__DIR__) . '/config/working_calendar.php');
$today = '2026-10-08';
// Паузы между эмитентами выключены: в тесте один эмитент и один проход. «Сегодня» задаёт тест.
$importer = new FnsBlocksImporter($fns, $db, new EventPublisher($db), 0, $calendar, static function () use (&$today): string {
    return $today;
});
$dispatcher = new NotificationDispatcher($db, new NoTelegram());

/** Ответ сервиса: $banks строк (по одной на банк) с одной суммой; 0 — блокировок нет (ключа rows в ответе нет). */
$answer = static function (int $banks, string $amount = '52185487.51'): array {
    $rows = [];
    for ($i = 1; $i <= $banks; $i++) {
        $rows[] = ['BIK' => sprintf('0445250%02d', $i), 'NOMER' => '4411', 'DATA' => '01.10.2026', 'DATABEGIN' => '01.10.2026', 'KODOSNOV' => '01', 'SALDOENS' => "       {$amount}"];
    }

    return $rows === [] ? [] : ['rows' => $rows];
};
/** Одна проверка эмитента в день $day; возвращает текст уведомления по новому событию или null, если события нет. */
$run = static function (string $day, array|Throwable $response) use ($fns, $importer, $db, $dispatcher, &$today): ?string {
    $today = $day;
    $before = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM events')->fetchColumn();
    $fns->answers = [$response];
    ob_start();   // отчёт импортёра в вывод теста не нужен
    try {
        $importer->checkIssuers([ISSUER], 0, 0);
    } finally {
        ob_end_clean();
    }
    $event = $db->query("SELECT payload_json FROM events WHERE id > {$before} ORDER BY id DESC LIMIT 1")->fetch();
    if ($event === false) {
        return null;
    }

    return $dispatcher->renderText('E1', json_decode((string) $event['payload_json'], true), ISSUER_NAME, ISSUER['inn']);
};
/** Строка fns_blocks: [заблокирован, счетов подтверждено, счетов ждёт подтверждения, с какого дня ждёт]. */
$state = static function () use ($db): array {
    $r = $db->query('SELECT is_fns_blocked, active_bank_count, pending_bank_count, pending_since FROM fns_blocks WHERE issuer_id = 7')->fetch();

    return [(int) $r['is_fns_blocked'], (int) $r['active_bank_count'], $r['pending_bank_count'] !== null ? (int) $r['pending_bank_count'] : null, $r['pending_since']];
};
$kinds = static fn (): array => array_map(
    static fn (string $json): string => (string) json_decode($json, true)['kind'],
    $db->query('SELECT payload_json FROM events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
);
$rawCount = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM raw_messages')->fetchColumn();
$lastRaw = static fn (): array => json_decode((string) $db->query('SELECT raw_payload FROM raw_messages ORDER BY id DESC LIMIT 1')->fetchColumn(), true);

// =====================================================================
echo "--- хронология с боя, 09–13.10.2026: сервис ФНС терял и возвращал строки ---\n";
$text = $run('2026-10-08', $answer(10));
check('четверг: новая блокировка, 10 счетов — объявляется сразу', [true, true, [1, 10, null, null]],
    [str_contains((string) $text, 'Блокировка счетов ФНС | Дата блокировки: 01.10.26 | Количество заблокированных счетов: 10'), str_contains((string) $text, '52 185 487.51 ₽'), $state()]);

check('пятница, утро: в ответе 6 счетов вместо 10 — уведомления нет, расхождение ждёт подтверждения', [null, [1, 10, 6, '2026-10-09']], [$run('2026-10-09', $answer(6)), $state()]);
check('пятница, день: в ответе блокировок нет — «снята» не объявляем, блокировка в базе остаётся', [null, [1, 10, 0, '2026-10-09']], [$run('2026-10-09', $answer(0)), $state()]);
check('пятница, вечер: всё ещё пусто, но день тот же — ждём', [null, [1, 10, 0, '2026-10-09']], [$run('2026-10-09', $answer(0)), $state()]);
check('понедельник: вернулись прежние 10 счетов — ни «снята», ни «новая блокировка»; ожидание снято', [null, [1, 10, null, null]], [$run('2026-10-12', $answer(10)), $state()]);

check('понедельник: изменилась сумма — объявляется сразу, текст как в боевом уведомлении',
    "<b>⚠️ Блокировки ФНC:</b>\n" . NAME_IN_TEXT . ' | ИНН 5017135982: сумма блокировки ФНС изменилась — было 52 185 487.51 ₽, стало 52 255 546.67 ₽ (заблокированных счетов: 10).',
    $run('2026-10-12', $answer(10, '52255546.67')));
check('понедельник: в ответе 15 счетов — уведомления нет, ждём', [null, [1, 10, 15, '2026-10-12']], [$run('2026-10-12', $answer(15, '52255546.67')), $state()]);
check('вторник: снова 10 — уведомления нет, ожидание снято', [null, [1, 10, null, null]], [$run('2026-10-13', $answer(10, '52255546.67')), $state()]);
check('итого за эти дни клиент получил два сообщения вместо семи: новая блокировка и изменение суммы', ['started', 'amount_changed'], $kinds());

// =====================================================================
echo "\n--- настоящее снятие: подтверждается первой проверкой следующего рабочего дня ---\n";
check('среда: блокировок нет — ждём', [null, [1, 10, 0, '2026-10-14']], [$run('2026-10-14', $answer(0)), $state()]);
check('четверг, первая проверка — капча: подтвердить нечем, ничего не меняется', [null, [1, 10, 0, '2026-10-14']], [$run('2026-10-15', ['captchaRequired' => true]), $state()]);
$text = $run('2026-10-15', $answer(0));
check('четверг, следующая проверка: блокировок по-прежнему нет — «снята»', true, str_contains((string) $text, NAME_IN_TEXT . ' | ИНН 5017135982: блокировка счетов ФНС снята (по состоянию на '));
check('…в базе: не заблокирован, ожидания нет, дата снятия — день, когда блокировка пропала впервые', [[0, 0, null, null], '2026-10-14'],
    [$state(), $db->query('SELECT unblock_date FROM fns_blocks WHERE issuer_id = 7')->fetchColumn()]);
check('эмитент без блокировки: пустой ответ и капча ничего не создают', [null, null, [0, 0, null, null]],
    [$run('2026-10-15', $answer(0)), $run('2026-10-15', ['captchaRequired' => true]), $state()]);
$text = $run('2026-10-15', $answer(4, '1000.00'));
check('новая блокировка после снятия — объявляется сразу', [true, [1, 4, null, null]], [str_contains((string) $text, 'Количество заблокированных счетов: 4'), $state()]);

// =====================================================================
echo "\n--- настоящее изменение числа счетов ---\n";
check('четверг: счетов 5 вместо 4 — ждём', [null, [1, 4, 5, '2026-10-15']], [$run('2026-10-15', $answer(5, '1000.00')), $state()]);
check('пятница: по-прежнему 5 — подтверждено, «было 4, стало 5» (а не «было 0»)',
    "<b>⚠️ Блокировки ФНC:</b>\n" . NAME_IN_TEXT . ' | ИНН 5017135982: количество заблокированных счетов ФНС изменилось — было 4, стало 5.',
    $run('2026-10-16', $answer(5, '1000.00')));
$payload = json_decode((string) $db->query('SELECT payload_json FROM events ORDER BY id DESC LIMIT 1')->fetchColumn(), true);
check('в данных события есть и прежнее, и новое число счетов', [4, 5, 'count_changed'], [$payload['old_active_bank_count'], $payload['active_bank_count'], $payload['kind']]);

check('пятница: счетов 3 — ждём', [null, [1, 5, 3, '2026-10-16']], [$run('2026-10-16', $answer(3, '1000.00')), $state()]);
check('суббота (разовая проверка при добавлении эмитента в боте): нерабочий день — не подтверждаем', [null, [1, 5, 3, '2026-10-16']], [$run('2026-10-17', $answer(3, '1000.00')), $state()]);
check('понедельник: уже 2 счёта — это другая картина, ожидание начинается заново', [null, [1, 5, 2, '2026-10-19']], [$run('2026-10-19', $answer(2, '1000.00')), $state()]);
check('вторник: по-прежнему 2 — «было 5, стало 2»', true, str_ends_with((string) $run('2026-10-20', $answer(2, '1000.00')), 'было 5, стало 2.'));

$text = $run('2026-10-20', $answer(6, '2500.00'));
check('сумма изменилась, а число счетов ещё не подтверждено: сообщение о сумме с прежним, подтверждённым числом счетов', [true, [1, 2, 6, '2026-10-20']],
    [str_ends_with((string) $text, 'сумма блокировки ФНС изменилась — было 1 000.00 ₽, стало 2 500.00 ₽ (заблокированных счетов: 2).'), $state()]);
check('среда: 6 счетов подтверждены', true, str_ends_with((string) $run('2026-10-21', $answer(6, '2500.00')), 'было 2, стало 6.'));
$run('2026-10-21', $answer(7, '2500.00'));
$text = $run('2026-10-22', $answer(7, '3000.00'));
check('число счетов подтвердилось в той же проверке, где изменилась сумма, — одно сообщение о сумме с новым числом счетов', [true, [1, 7, null, null]],
    [str_ends_with((string) $text, 'было 2 500.00 ₽, стало 3 000.00 ₽ (заблокированных счетов: 7).'), $state()]);

// =====================================================================
echo "\n--- праздник посреди недели ---\n";
check('вторник 03.11: блокировок нет — ждём', [null, [1, 7, 0, '2026-11-03']], [$run('2026-11-03', $answer(0)), $state()]);
check('среда 04.11 — праздник: не подтверждаем', [null, [1, 7, 0, '2026-11-03']], [$run('2026-11-04', $answer(0)), $state()]);
check('четверг 05.11: подтверждено — «снята», дата снятия 03.11', [true, '2026-11-03'],
    [str_contains((string) $run('2026-11-05', $answer(0)), 'блокировка счетов ФНС снята'), $db->query('SELECT unblock_date FROM fns_blocks WHERE issuer_id = 7')->fetchColumn()]);

// =====================================================================
echo "\n--- капча и ошибка сети: «не узнали», а не «блокировок нет» ---\n";
$run('2026-11-05', $answer(3, '500.00'));
$eventsBefore = count($kinds());
check('капча (ответ без строк): события нет, блокировка и число счетов не тронуты, ожидание не начато; попытка отмечена как неудачная',
    [null, [1, 3, null, null], 'error'], [$run('2026-11-06', ['captchaRequired' => true]), $state(), $db->query('SELECT verification FROM fns_blocks WHERE issuer_id = 7')->fetchColumn()]);
check('ошибка сети — то же самое', [null, [1, 3, null, null]], [$run('2026-11-06', new RuntimeException('bi2-proc.json: cURL ошибка')), $state()]);
check('в ответе с признаком капчи всё же есть строки — берём строки (здесь они те же, события нет)', [null, 'success'],
    [$run('2026-11-06', ['captchaRequired' => true] + $answer(3, '500.00')), $db->query('SELECT verification FROM fns_blocks WHERE issuer_id = 7')->fetchColumn()]);
check('…и новых событий за эти проверки нет', $eventsBefore, count($kinds()));

// =====================================================================
echo "\n--- ответ сервиса сохраняется в журнале сырых сообщений ---\n";
$before = $rawCount();
$run('2026-11-06', $answer(3, '500.00'));
$run('2026-11-06', $answer(3, '500.00'));
check('ответ не изменился — в журнал ничего не пишем', $before, $rawCount());
$run('2026-11-06', $answer(5, '500.00'));
$raw = $lastRaw();
check('число счетов в ответе изменилось, события нет — ответ записан целиком: шаг, ИНН, числа и сами строки',
    ['observation', 'pending_count', '5017135982', 5, 3, 5, '2026-11-06', 5, '044525001', false],
    [$raw['kind'], $raw['fns_answer']['step'], $raw['fns_answer']['inn'], $raw['fns_answer']['observed_bank_count'], $raw['fns_answer']['confirmed_bank_count'],
        $raw['fns_answer']['pending_bank_count'], $raw['fns_answer']['pending_since'], count($raw['fns_answer']['rows']), $raw['fns_answer']['rows'][0]['BIK'], $raw['fns_answer']['captcha_required']]);
$before = $rawCount();
$run('2026-11-06', $answer(5, '500.00'));
check('та же картина при следующей проверке — повторно не пишем', $before, $rawCount());
$run('2026-11-06', $answer(3, '500.00'));
check('ответ вернулся к прежнему — записано, что ожидание снято', ['observation', 'pending_cancelled', 3], [$lastRaw()['kind'], $lastRaw()['fns_answer']['step'], count($lastRaw()['fns_answer']['rows'])]);
$run('2026-11-06', $answer(3, '700.00'));
$raw = $lastRaw();
check('у события в журнале — тоже ответ целиком', ['amount_changed', 3, '       700.00'], [$raw['kind'], count($raw['fns_answer']['rows']), $raw['fns_answer']['rows'][0]['SALDOENS']]);
check('событие ссылается на эту строку журнала', (int) $db->query('SELECT MAX(id) FROM raw_messages')->fetchColumn(),
    (int) $db->query('SELECT raw_message_id FROM events ORDER BY id DESC LIMIT 1')->fetchColumn());
check('в данные события (их читает текст уведомления) строки ответа не попадают', false,
    str_contains((string) $db->query('SELECT payload_json FROM events ORDER BY id DESC LIMIT 1')->fetchColumn(), 'fns_answer'));

// =====================================================================
echo "\n--- тексты для прежних событий ---\n";
check('событие, созданное до исправления (прежнего числа счетов в данных нет): только «стало», без «было 0»',
    "<b>⚠️ Блокировки ФНC:</b>\n" . NAME_IN_TEXT . ' | ИНН 5017135982: количество заблокированных счетов ФНС изменилось — стало 15.',
    $dispatcher->renderText('E1', ['kind' => 'count_changed', 'old_blocked_amount' => '52255546.67', 'new_blocked_amount' => '52255546.67', 'active_bank_count' => 15,
        'block_date' => '2026-10-01', 'reason' => null], ISSUER_NAME, ISSUER['inn']));
check('прежнее число счетов действительно ноль — так и пишем', true, str_ends_with(
    $dispatcher->renderText('E1', ['kind' => 'count_changed', 'old_active_bank_count' => 0, 'active_bank_count' => 3], ISSUER_NAME, ISSUER['inn']), 'было 0, стало 3.'));

// =====================================================================
echo "\n--- без календаря и без миграции ---\n";
$db2 = makeDb();
$fns2 = new ScriptedFns();
$day2 = '2026-10-16';
$plain = new FnsBlocksImporter($fns2, $db2, new EventPublisher($db2), 0, null, static function () use (&$day2): string {
    return $day2;
});
$check2 = static function (string $day, array $response) use ($plain, $fns2, $db2, &$day2): int {
    $day2 = $day;
    $fns2->answers = [$response];
    ob_start();
    $plain->checkIssuers([ISSUER], 0, 0);
    ob_end_clean();

    return (int) $db2->query('SELECT COUNT(*) FROM events')->fetchColumn();
};
check('без календаря рабочими считаются понедельник — пятница: в пятницу пропало, в субботу не подтверждаем, в понедельник — да', [1, 1, 1, 2],
    [$check2('2026-10-16', $answer(2)), $check2('2026-10-16', $answer(0)), $check2('2026-10-17', $answer(0)), $check2('2026-10-19', $answer(0))]);

$db3 = makeDb(false);
$message = '';
try {
    ob_start();
    (new FnsBlocksImporter(new ScriptedFns(), $db3, new EventPublisher($db3), 0))->checkIssuers([ISSUER], 0, 0);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
} finally {
    ob_end_clean();
}
check('миграция 033 не применена — понятная ошибка до первой проверки, в базу ничего не записано', [true, 0],
    [str_contains($message, '033_fns_blocks_pending_confirmation.sql'), (int) $db3->query('SELECT COUNT(*) FROM fns_blocks')->fetchColumn()]);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
