<?php

declare(strict_types=1);

/**
 * Опрос GetNews (НРД): получить сообщения → GetNewsMessageMapper →
 * PaymentProcessor::process(). Этап 5, docs/STAGE5_PAYMENTS.md.
 *
 * API НРД сам ничего не присылает — мы спрашиваем. Интервал опроса и есть
 * наибольшая задержка между публикацией у НРД и нашим уведомлением.
 * Сообщения о деньгах НРД публикует по рабочим дням с 9:44 до 18:31 мск
 * (95 % — до 12:35), объявления о техдефолте — около 11:00 и в
 * 17:20–17:35.
 *
 * ОСТОРОЖНО: рабочий запуск = настоящие уведомления клиентам по купонам,
 * амортизациям и погашениям. Поэтому:
 *   - --dry-run работает ВСЕГДА, даже если опрос выключен: ничего не
 *     пишет, показывает, как сообщения ложатся на наш график;
 *   - запись в базу требует config/payments.php: 'getnews_polling' => true;
 *   - первую загрузку делать с --quiet (см. ниже).
 *
 * === Что читаем ===
 * Переписано 05.10.2026. В сутки выходит 320–570 сообщений по
 * корпоративным действиям, а один запрос отдаёт не больше 1000 — поэтому:
 *   - листание через skip (GetNewsPager);
 *   - «закладка»: время самого свежего обработанного сообщения хранится в
 *     var/getnews_poll_state.json. Очередной опрос читает ленту от новых
 *     сообщений к старым и останавливается, уйдя на 6 часов глубже
 *     закладки, — обычно это одна страница в 200 сообщений. Закладка
 *     только экономит запросы: повторы отсекает PaymentProcessor по
 *     content_id_out, потерять или удалить файл безопасно;
 *   - нет закладки (первый запуск, файл удалён) — читается окно --days
 *     (по умолчанию 3 дня) целиком;
 *   - верхняя граница даты в запрос не ставится: условие «по сегодня»
 *     может отрезать сегодняшние сообщения (не проверено на боевом
 *     доступе), а лента и так отдаётся от новых к старым.
 * Сообщения обрабатываются по порядку публикации, от старых к новым.
 *
 * === Что куда уходит ===
 *   - бумаги нет в нашей базе — не ошибка, только счётчик (лента НРД —
 *     весь рынок);
 *   - бумага наша, а выплаты на эту дату в графике нет — администратору
 *     (один раз на сообщение): повод проверить график с Мосбиржи;
 *   - сообщение о деньгах не удалось прочитать — администратору: признак
 *     того, что НРД изменил формат.
 *
 * Запуск:
 *   php bin/poll_getnews.php --dry-run                           (просмотр последних 3 дней со сверкой по базе)
 *   php bin/poll_getnews.php --dry-run --from=2026-09-18         (просмотр окна; тестовый доступ)
 *   php bin/poll_getnews.php --dry-run --replay=var/getnews_debug_2026-09-18_2026-10-02.json
 *                                                                (то же по сохранённому файлу, без обращения к НРД; файлов можно несколько через запятую)
 *   php bin/poll_getnews.php --quiet --from=2026-09-18           (ТИХАЯ первая загрузка: статусы и события пишутся, клиентам ничего не уходит)
 *   php bin/poll_getnews.php                                     (обычный опрос по закладке)
 *   php bin/poll_getnews.php --full --days=5                     (перечитать 5 дней, не глядя на закладку)
 *
 * По расписанию — по рабочим дням каждые 5 минут с 9:30 до 19:00 мск и
 * раз в час в остальное время. Если сервер живёт по UTC:
 *   *\/5 6-15 * * 1-5 /usr/bin/php /path/to/bondkeeper/bin/poll_getnews.php >> /var/log/bondkeeper/poll_getnews.log 2>&1
 *   0 0-5,16-23 * * * /usr/bin/php /path/to/bondkeeper/bin/poll_getnews.php >> /var/log/bondkeeper/poll_getnews.log 2>&1
 * (в первой строке «*\/5» писать без обратной черты — она здесь только
 * чтобы не закрыть комментарий).
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Events\EventPublisher;
use BondKeeper\Payments\GetNewsClient;
use BondKeeper\Payments\GetNewsClientInterface;
use BondKeeper\Payments\GetNewsConfig;
use BondKeeper\Payments\GetNewsMessageMapper;
use BondKeeper\Payments\GetNewsPager;
use BondKeeper\Payments\PaymentProcessor;
use BondKeeper\Payments\PaymentsConfig;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\AdminNotifier;

const OVERLAP_HOURS = 6;
const STATE_FILE = __DIR__ . '/../var/getnews_poll_state.json';

$dryRun = in_array('--dry-run', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$full = in_array('--full', $argv, true);
$days = 3;
$pageSize = null;
$explicitFrom = null;
$explicitTo = null;
$replay = null;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = (int) $m[1];
    }
    if (preg_match('/^--page-size=(\d+)$/', $arg, $m)) {
        $pageSize = (int) $m[1];
    }
    if (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $explicitFrom = $m[1];
    }
    if (preg_match('/^--to=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $explicitTo = $m[1];
    }
    if (preg_match('/^--replay=(.+)$/', $arg, $m)) {
        $replay = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    }
}

$paymentsConfig = PaymentsConfig::fromFile(__DIR__ . '/../config/payments.php');
if (!$paymentsConfig->getNewsPolling && !$dryRun) {
    Logger::info('Опрос GetNews выключен (config/payments.php: getnews_polling). Ничего не делаю. Проверить вывод можно через --dry-run.');
    exit(0);
}

$moscow = new DateTimeZone('Europe/Moscow');
$now = new DateTimeImmutable('now', $moscow);

// --- Откуда и с какого места читать ---------------------------------------
$bookmark = null;
if ($replay === null && $explicitFrom === null && !$full && is_file(STATE_FILE)) {
    $state = json_decode((string) file_get_contents(STATE_FILE), true);
    if (is_array($state) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($state['last_pub_date'] ?? '')) === 1) {
        $bookmark = (string) $state['last_pub_date'];
    }
}
// Момент, глубже которого читать незачем: всё более старое уже обработано прошлыми опросами.
$stopBefore = $bookmark !== null
    ? (new DateTimeImmutable($bookmark, $moscow))->modify('-' . OVERLAP_HOURS . ' hours')->format('Y-m-d H:i:s')
    : null;
$dateFrom = $explicitFrom ?? ($stopBefore !== null ? substr($stopBefore, 0, 10) : $now->modify("-{$days} days")->format('Y-m-d'));
$pageSize ??= $stopBefore !== null ? 200 : GetNewsPager::MAX_PAGE_SIZE;

$dateCondition = ['$gte' => $dateFrom];
if ($explicitTo !== null) {
    $dateCondition['$lte'] = $explicitTo;
}
$filter = ['$and' => [['category' => 'CORP_ACTION'], ['pub_date' => $dateCondition]]];

if ($replay !== null) {
    $replayItems = [];
    foreach ($replay as $file) {
        if (!is_file($file)) {
            fwrite(STDERR, "Файл не найден: {$file}\n");
            exit(1);
        }
        foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $item) {
            $replayItems[] = $item;
        }
    }
    // Отдаёт сохранённые файлы страницами, как это делал бы НРД; фильтр не применяется.
    $client = new class ($replayItems) implements GetNewsClientInterface {
        /** @param array<int, array<string, mixed>> $items */
        public function __construct(private readonly array $items)
        {
        }

        public function fetchNews(array $filter = [], int $limit = 10, int $skip = 0): array
        {
            return array_slice($this->items, $skip, $limit);
        }
    };
    unset($replayItems);
} else {
    // Страница в тысячу сообщений — около 18 МБ: 20 секунд клиента по умолчанию может не хватить.
    $client = new GetNewsClient(GetNewsConfig::fromFile(__DIR__ . '/../config/nsd_api.php'), 180);
}

$prefix = $dryRun ? '=== ПРОСМОТР (в базу ничего не пишется) === ' : ($quiet ? '=== ТИХАЯ ЗАГРУЗКА (клиентам ничего не уходит) === ' : '');
Logger::info($prefix . 'GetNews: ' . ($replay !== null ? 'повтор из файлов ' . implode(', ', $replay) : "с {$dateFrom}"
    . ($explicitTo !== null ? " по {$explicitTo}" : '') . ($stopBefore !== null ? ", закладка {$bookmark}" : ', закладки нет — окно целиком')));

// --- Чтение и разбор --------------------------------------------------------
$pager = new GetNewsPager($client, $pageSize, 60, $replay !== null ? 0 : 1);
$received = 0;
$newestPubDate = null;
/** @var array<string, int> $skipped */
$skipped = [];
/** @var array<int, string> $unreadable */
$unreadable = [];
/** @var array<int, array{0: string, 1: \BondKeeper\Payments\PaymentMessage}> $messages */
$messages = [];

try {
    foreach ($pager->pages($filter) as $items) {
        $received += count($items);
        $oldestOnPage = null;
        foreach ($items as $rawMessage) {
            $pubDate = (string) ($rawMessage['pub_date'] ?? '');
            if ($pubDate !== '') {
                $newestPubDate = $newestPubDate === null || $pubDate > $newestPubDate ? $pubDate : $newestPubDate;
                $oldestOnPage = $oldestOnPage === null || $pubDate < $oldestOnPage ? $pubDate : $oldestOnPage;
            }
            $classified = GetNewsMessageMapper::classify($rawMessage);
            if ($classified['message'] === null) {
                $reason = (string) $classified['reason'];
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                if ($reason === GetNewsMessageMapper::REASON_UNREADABLE) {
                    $unreadable[] = $pubDate . ' ' . mb_substr((string) ($rawMessage['title_ru'] ?? ''), 0, 140);
                }
                continue;
            }
            $messages[] = [$pubDate . ' ' . str_pad($classified['message']->sourceRef, 20, '0', STR_PAD_LEFT), $classified['message']];
        }
        if ($stopBefore !== null && $oldestOnPage !== null && $oldestOnPage < $stopBefore) {
            break; // ушли глубже закладки с запасом — дальше всё уже обработано
        }
    }
} catch (\Throwable $e) {
    Logger::error('GetNews: ошибка запроса — ' . $e->getMessage());
    if (!$dryRun) {
        AdminNotifier::send('⚠️ Опрос GetNews: ошибка запроса — ' . $e->getMessage());
    }
    exit(1);
}

Logger::info("Получено сообщений: {$received} (запросов: {$pager->requestsMade()}); о выплатах: " . count($messages));
foreach ($skipped as $reason => $count) {
    Logger::info("  пропущено — {$reason}: {$count}");
}
if (!$pager->isComplete()) {
    Logger::warn('Лента прочитана не до конца: сработал предохранитель по числу страниц. Запустите с более узким окном (--from=).');
}

// От старых к новым: «не выплачено» → «часть» → «остаток» должны лечь в том же порядке, в каком вышли.
usort($messages, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

// --- Просмотр ---------------------------------------------------------------
if ($dryRun) {
    $db = null;
    try {
        $db = Database::connection();
    } catch (\Throwable $e) {
        Logger::warn('База недоступна — показываю разбор без сверки с графиком: ' . $e->getMessage());
    }
    $processor = $db !== null
        ? new PaymentProcessor($db, new EventPublisher($db), WorkingCalendar::fromFile(__DIR__ . '/../config/working_calendar.php'))
        : null;

    $stats = ['чужая бумага' => 0, 'найдено: точная дата' => 0, 'найдено: по дню исполнения' => 0, 'наша бумага, выплаты в графике нет' => 0];
    foreach ($messages as [, $message]) {
        $where = '';
        if ($processor !== null) {
            $located = $processor->locate($message);
            if ($located['security'] === null) {
                $stats['чужая бумага']++;
                continue;
            }
            if ($located['payment'] === null) {
                $stats['наша бумага, выплаты в графике нет']++;
                $where = ' → В ГРАФИКЕ НЕТ';
            } else {
                $stats[$located['payment']['match'] === 'exact' ? 'найдено: точная дата' : 'найдено: по дню исполнения']++;
                $where = " → {$located['payment']['table']} {$located['payment']['payment_date']}, у нас {$located['payment']['status']}"
                    . ', плановая ' . ($located['payment']['value_per_bond'] ?? '—');
            }
        }
        Logger::info(sprintf(
            '[просмотр] %s %s %s %s/%s%s%s сумма %s из %s %s%s',
            $message->messageDate,
            $message->isin,
            $message->kind,
            $message->stage,
            $message->execution,
            $message->late ? ' ПОЗЖЕ СРОКА' : '',
            $message->beforeDue ? ' ДО СРОКА' : '',
            $message->amountPerBond ?? '—',
            $message->plannedPerBond ?? '—',
            $message->currency ?? '',
            $where,
        ));
    }
    if ($processor !== null) {
        foreach ($stats as $label => $count) {
            Logger::info("  {$label}: {$count}");
        }
    }
    foreach ($unreadable as $line) {
        Logger::warn("  НЕ ПРОЧИТАНО: {$line}");
    }
    Logger::info('В базу ничего не записано (--dry-run).');
    exit(0);
}

// --- Запись -----------------------------------------------------------------
$db = Database::connection();
$processor = new PaymentProcessor($db, new EventPublisher($db), WorkingCalendar::fromFile(__DIR__ . '/../config/working_calendar.php'), $quiet);

$counts = [
    PaymentProcessor::RESULT_PROCESSED => 0,
    PaymentProcessor::RESULT_DUPLICATE => 0,
    PaymentProcessor::RESULT_NO_CHANGE => 0,
    PaymentProcessor::RESULT_FOREIGN => 0,
    PaymentProcessor::RESULT_UNMATCHED => 0,
];
/** @var array<string, int> $eventCounts */
$eventCounts = [];
/** @var array<int, string> $gapLines */
$gapLines = [];

foreach ($messages as [, $message]) {
    try {
        $result = $processor->process($message);
    } catch (\Throwable $e) {
        Logger::error("{$message->isin} {$message->kind} на {$message->paymentDate}: ошибка обработки — " . $e->getMessage());
        continue;
    }
    $counts[$result['result']] = ($counts[$result['result']] ?? 0) + 1;
    if ($result['result'] === PaymentProcessor::RESULT_UNMATCHED && !($result['retry'] ?? false)) {
        $gapLines[] = "{$message->isin} ({$message->kind}, {$message->paymentDate})";
    }
    foreach ($result['events'] as $code) {
        $eventCounts[$code] = ($eventCounts[$code] ?? 0) + 1;
    }
    if ($result['events'] !== []) {
        Logger::info("{$message->isin} {$message->kind} на {$message->paymentDate}: " . implode(', ', $result['events']));
    }
}

ksort($eventCounts);
Logger::info(sprintf(
    'Обработано: %d; повторы: %d; без изменений: %d; чужие бумаги: %d; наша бумага, выплаты в графике нет: %d',
    $counts[PaymentProcessor::RESULT_PROCESSED],
    $counts[PaymentProcessor::RESULT_DUPLICATE],
    $counts[PaymentProcessor::RESULT_NO_CHANGE],
    $counts[PaymentProcessor::RESULT_FOREIGN],
    $counts[PaymentProcessor::RESULT_UNMATCHED],
));
Logger::info('События: ' . ($eventCounts === [] ? 'нет' : implode(', ', array_map(static fn (string $c, int $n): string => "{$c} — {$n}", array_keys($eventCounts), $eventCounts))));

if ($gapLines !== []) {
    AdminNotifier::sendLines(array_merge(
        ['⚠️ GetNews: сообщения по нашим бумагам, которым не нашлось выплаты в графике (' . count($gapLines) . '). Стоит проверить график с Мосбиржи:'],
        array_slice($gapLines, 0, 40),
        count($gapLines) > 40 ? ['… и ещё ' . (count($gapLines) - 40)] : [],
    ));
}
if ($unreadable !== []) {
    AdminNotifier::sendLines(array_merge(
        ['⚠️ GetNews: не удалось прочитать сообщения о деньгах (' . count($unreadable) . ') — возможно, НРД изменил формат:'],
        array_slice($unreadable, 0, 20),
    ));
}

// Закладка сдвигается только после полного прохода по настоящей ленте.
if ($replay === null && $explicitTo === null && $newestPubDate !== null && ($bookmark === null || $newestPubDate > $bookmark)) {
    if (!is_dir(dirname(STATE_FILE))) {
        @mkdir(dirname(STATE_FILE), 0775, true);
    }
    file_put_contents(STATE_FILE, json_encode(['last_pub_date' => $newestPubDate, 'saved_at' => $now->format('Y-m-d H:i:s')]));
}
