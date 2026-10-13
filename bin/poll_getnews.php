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
 *   - обычная запись в базу требует config/payments.php: 'getnews_polling' => true;
 *   - Объявления НРД об офертах: строка в offers и — если включены
 *     напоминания об офертах ('offer_reminders') — сразу напоминание R2
 *     по этой бумаге (docs/OFFERS.md, раздел 5а). В тихом прогоне
 *     напоминание не создаётся.
 *   - --quiet («тихий» прогон) пишет статусы и события, но клиентам не
 *     отправляет ничего; работает и при выключенном опросе. Нужен дважды:
 *     один раз заранее — проверить запись в боевую базу, и один раз в день
 *     перехода на боевой доступ — дочитать накопившееся, чтобы клиентам не
 *     ушли уведомления о выплатах за прошлые дни.
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
 *   - структурный выпуск (у биржи нет графика его купонов): купон
 *     создаётся по сообщению НРД о деньгах, остальное пропускается без
 *     сообщения администратору (решение пользователя, 06.10.2026);
 *   - сообщение о деньгах не удалось прочитать — администратору: признак
 *     того, что НРД изменил формат.
 *
 * === Оферты (11.10.2026, docs/OFFERS.md) ===
 * Из той же ленты берутся два вида сообщений об офертах: объявление о
 * досрочном погашении по решению эмитента (MCAL) и отмена оферты или
 * досрочного погашения. Они меняют таблицу offers и признак оферты у
 * бумаги; клиентам по ним ничего не уходит, администратор получает
 * короткую сводку (в тихом прогоне — нет).
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
use BondKeeper\Iss\OffersImporter;
use BondKeeper\Payments\GetNewsPager;
use BondKeeper\Payments\OfferNotice;
use BondKeeper\Payments\OfferNoticeProcessor;
use BondKeeper\Payments\OfferWatch;
use BondKeeper\Payments\PaymentProcessor;
use BondKeeper\Payments\PaymentSchemaCheck;
use BondKeeper\Payments\PaymentsConfig;
use BondKeeper\Payments\PollLock;
use BondKeeper\Payments\ScheduleStatuses;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Support\CronGuard;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\AdminNotifier;
use BondKeeper\Telegram\NotificationPreferences;

const OVERLAP_HOURS = 6;
const STATE_FILE = __DIR__ . '/../var/getnews_poll_state.json';
const LOCK_FILE = __DIR__ . '/../var/getnews_poll.lock';
const STUCK_AFTER_MIN = 30; // запуск, который держит замок дольше, считаем зависшим

$dryRun = in_array('--dry-run', $argv, true);
CronGuard::install('poll_getnews', !$dryRun);
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
// Тихий прогон разрешён и при выключенном опросе: клиентам он ничего не отправляет, а включать
// ради него общий флаг опасно — если в кроне уже стоит строка опроса, пошли бы настоящие уведомления.
if (!$paymentsConfig->getNewsPolling && !$dryRun && !$quiet) {
    Logger::info('Опрос GetNews выключен (config/payments.php: getnews_polling). Ничего не делаю. Проверить вывод можно через --dry-run.');
    exit(0);
}

// Замок от наложения запусков: при опросе раз в минуту новый запуск может начаться, пока прежний ещё
// работает, и одно сообщение НРД обработалось бы дважды. Просмотр (--dry-run) ничего не пишет — ему замок не нужен.
$lock = null;
if (!$dryRun) {
    $lock = PollLock::acquire(LOCK_FILE);
    if ($lock === null) {
        $minutes = PollLock::heldMinutes(LOCK_FILE);
        Logger::info('Предыдущий опрос GetNews ещё не закончился — этот запуск пропущен.');
        if ($minutes !== null && $minutes >= STUCK_AFTER_MIN) {
            CronGuard::report('poll_getnews', "Опрос GetNews не заканчивается уже {$minutes} мин. — похоже, прежний запуск завис. Новые запуски пропускаются, пока он не завершится.");
        }
        exit(0);
    }
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
/** @var array<int, array{0: string, 1: OfferNotice}> $offerNotices объявленные коллы и отмены оферт */
$offerNotices = [];

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
            $offerNotice = OfferNotice::fromRaw($rawMessage);
            if ($offerNotice !== null) {
                $offerNotices[] = [$pubDate . ' ' . str_pad((string) ($rawMessage['content_id_out'] ?? ''), 20, '0', STR_PAD_LEFT), $offerNotice];
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
    // Технический сбой — разработчику; одно и то же сообщение не чаще раза в час (опрос идёт часто).
    CronGuard::report('poll_getnews', 'Опрос GetNews: ошибка запроса — ' . $e->getMessage(), '', !$dryRun);
    exit(1);
}

Logger::info("Получено сообщений: {$received} (запросов: {$pager->requestsMade()}); о выплатах: " . count($messages)
    . '; об офертах (объявленное досрочное погашение, отмена): ' . count($offerNotices));
foreach ($skipped as $reason => $count) {
    Logger::info("  пропущено — {$reason}: {$count}");
}
if (!$pager->isComplete()) {
    Logger::warn('Лента прочитана не до конца: сработал предохранитель по числу страниц. Запустите с более узким окном (--from=).');
}

// От старых к новым: «не выплачено» → «часть» → «остаток» должны лечь в том же порядке, в каком вышли.
usort($messages, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
usort($offerNotices, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
$today = $now->format('Y-m-d');

/**
 * Сообщения НРД об офертах → таблица offers. $apply = false — только показать.
 *
 * @param array<int, array{0: string, 1: OfferNotice}> $offerNotices
 * @param array<string, true> $announcedIsins сюда попадают бумаги, по которым НРД объявил оферту (для напоминания сразу)
 * @return array<int, string> строки для администратора: что изменилось
 */
$processOfferNotices = static function (PDO $db, array $offerNotices, string $today, bool $apply, array &$announcedIsins = []): array {
    if ($offerNotices === []) {
        return [];
    }
    $problem = OffersImporter::schemaProblem($db);
    if ($problem !== null) {
        // Таблица не готова — это вопрос к установке, а не к данным: разработчику, и не чаще раза в час.
        CronGuard::report('poll_getnews', 'Сообщения НРД об офертах пропущены. ' . $problem, '', $apply);

        return [];
    }
    $processor = new OfferNoticeProcessor($db);
    $counts = [];
    $lines = [];
    foreach ($offerNotices as [, $notice]) {
        try {
            $result = $processor->process($notice, $today, $apply);
        } catch (\Throwable $e) {
            Logger::error("{$notice->isin} оферта на {$notice->date}: ошибка обработки — " . $e->getMessage());
            continue;
        }
        $counts[$result['result']] = ($counts[$result['result']] ?? 0) + 1;
        if ($result['result'] === OfferNoticeProcessor::RESULT_APPLIED) {
            $what = $notice->action === OfferNotice::ACTION_CANCELLED
                ? ($notice->type === 'call' ? 'отменено досрочное погашение' : 'отменена оферта')
                : 'объявлено досрочное погашение' . ($notice->percent !== null ? ' (' . rtrim(rtrim($notice->percent, '0'), '.') . ' % номинала)' : '');
            $line = "{$result['security_name']} ({$notice->isin}): {$what}, дата " . date('d.m.Y', (int) strtotime($notice->date));
            $lines[] = '• ' . $line;
            Logger::info(($apply ? '' : '[просмотр] ') . "оферты: {$line} — {$result['note']}");
            if ($notice->action !== OfferNotice::ACTION_CANCELLED) {
                $announcedIsins[$notice->isin] = true;
            }
        }
    }
    Logger::info(sprintf(
        'Оферты по сообщениям НРД: %s %d; без изменений: %d; не оферта или дата прошла: %d; чужие бумаги: %d',
        $apply ? 'применено' : 'было бы применено',
        $counts[OfferNoticeProcessor::RESULT_APPLIED] ?? 0,
        $counts[OfferNoticeProcessor::RESULT_NO_CHANGE] ?? 0,
        $counts[OfferNoticeProcessor::RESULT_IGNORED] ?? 0,
        $counts[OfferNoticeProcessor::RESULT_FOREIGN] ?? 0,
    ));

    return $lines;
};

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

    $stats = ['чужая бумага' => 0, 'найдено: точная дата' => 0, 'найдено: по дню исполнения' => 0, 'досрочное погашение выпуска' => 0,
        'структурный выпуск: купон по сообщению НРД' => 0, 'структурный выпуск: пропущено' => 0, 'наша бумага, выплаты в графике нет' => 0];
    foreach ($messages as [, $message]) {
        $where = '';
        if ($processor !== null) {
            $located = $processor->locate($message);
            if ($located['security'] === null) {
                $stats['чужая бумага']++;
                continue;
            }
            if ($located['early']) {
                $stats['досрочное погашение выпуска']++;
                $where = ' → ДОСРОЧНОЕ ПОГАШЕНИЕ ВЫПУСКА: строка погашения ' . ($located['payment'] === null ? 'будет создана по сообщению' : 'уже есть');
            } elseif ($located['structured'] === 'build') {
                $stats['структурный выпуск: купон по сообщению НРД']++;
                $where = ' → структурный выпуск, графика нет: купон будет создан по сообщению';
            } elseif ($located['structured'] === 'skip') {
                $stats['структурный выпуск: пропущено']++;
                continue;
            } elseif ($located['payment'] === null) {
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
    if ($db !== null) {
        $processOfferNotices($db, $offerNotices, $today, false);
    }
    Logger::info('В базу ничего не записано (--dry-run).');
    exit(0);
}

// --- Запись -----------------------------------------------------------------
$db = Database::connection();
// Без этих типов событий база не даст записать ни одной выплаты — говорим прямо и до начала обработки.
PaymentSchemaCheck::requireEventTypes($db, ['A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'B1', 'B1a', 'B2', 'B4', 'B5', 'C2']);
// То же про статусы выплат: без миграции 031 база не примет «дефолт» и «отменена».
$statusProblem = ScheduleStatuses::schemaProblem($db);
if ($statusProblem !== null) {
    throw new RuntimeException($statusProblem);
}
$processor = new PaymentProcessor($db, new EventPublisher($db), WorkingCalendar::fromFile(__DIR__ . '/../config/working_calendar.php'), $quiet);

$counts = [
    PaymentProcessor::RESULT_PROCESSED => 0,
    PaymentProcessor::RESULT_DUPLICATE => 0,
    PaymentProcessor::RESULT_NO_CHANGE => 0,
    PaymentProcessor::RESULT_FOREIGN => 0,
    PaymentProcessor::RESULT_SKIPPED => 0,
    PaymentProcessor::RESULT_UNMATCHED => 0,
];
/** @var array<string, int> $eventCounts */
$eventCounts = [];
/** @var array<int, string> $gapLines */
$gapLines = [];
/** @var array<string, string> $earlyLines досрочные погашения выпусков: ISIN → строка для администратора */
$earlyLines = [];

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
        Logger::info("{$message->isin} {$message->kind} на {$message->paymentDate}: " . implode(', ', $result['events'])
            . (($result['early_redemption'] ?? false) ? ' — досрочное погашение выпуска' : ''));
    }
    if (($result['early_redemption'] ?? false) && array_intersect(['A6', 'B5'], $result['events']) !== []) {
        $earlyLines[$message->isin] = "• {$result['security_name']} ({$message->isin}): погашен досрочно " . date('d.m.Y', (int) strtotime($message->paymentDate))
            . ($message->amountPerBond !== null ? ', ' . rtrim(rtrim($message->amountPerBond, '0'), '.') . ' ' . ($message->currency ?? '') . ' на бумагу' : '');
    }
}

ksort($eventCounts);
Logger::info(sprintf(
    'Обработано: %d; повторы: %d; без изменений: %d; чужие бумаги: %d; структурные выпуски, пропущено: %d; наша бумага, выплаты в графике нет: %d',
    $counts[PaymentProcessor::RESULT_PROCESSED],
    $counts[PaymentProcessor::RESULT_DUPLICATE],
    $counts[PaymentProcessor::RESULT_NO_CHANGE],
    $counts[PaymentProcessor::RESULT_FOREIGN],
    $counts[PaymentProcessor::RESULT_SKIPPED],
    $counts[PaymentProcessor::RESULT_UNMATCHED],
));
Logger::info('События: ' . ($eventCounts === [] ? 'нет' : implode(', ', array_map(static fn (string $c, int $n): string => "{$c} — {$n}", array_keys($eventCounts), $eventCounts))));

if ($earlyLines !== [] && !$quiet) {
    AdminNotifier::sendLines(array_merge(
        ['📋 НРД: выпуски погашены досрочно (' . count($earlyLines) . '). Бумага получила статус «погашена», остаток графика отменён:'],
        array_slice(array_values($earlyLines), 0, 40),
    ));
}

/** @var array<string, true> $announcedIsins */
$announcedIsins = [];
$offerLines = $processOfferNotices($db, $offerNotices, $today, true, $announcedIsins);
if ($offerLines !== [] && !$quiet) {
    AdminNotifier::sendLines(array_merge(['📋 НРД об офертах (' . count($offerLines) . '):'], array_slice($offerLines, 0, 40),
        count($offerLines) > 40 ? ['… и ещё ' . (count($offerLines) - 40)] : []));
}

// Решение пользователя (12.10.2026): если дата оферты стала известна, когда до неё меньше 30 дней, напоминаем сразу,
// как получили информацию, — не дожидаясь утреннего запуска payment_reminders.php. Только по бумагам из объявлений
// этого прохода; в нерабочий день OfferWatch ничего не создаёт (как и утренний скрипт), в тихой загрузке — тоже.
// Сбой здесь опрос не останавливает: напоминание создаст утренний запуск.
if ($announcedIsins !== [] && !$quiet && $paymentsConfig->offerReminders) {
    try {
        $problem = PaymentSchemaCheck::missingEventTypes($db, ['R2']) !== [] ? PaymentSchemaCheck::describe(['R2']) : NotificationPreferences::schemaProblem($db);
        if ($problem !== null) {
            Logger::warn('Напоминание об оферте сразу после объявления НРД не создано. ' . $problem);
        } else {
            $offerWatch = new OfferWatch($db, new EventPublisher($db), WorkingCalendar::fromFile(__DIR__ . '/../config/working_calendar.php'),
                $paymentsConfig->offerPutRemindersFrom);
            $isins = array_map('strval', array_keys($announcedIsins));
            $created = $offerWatch->remind($today, true, NotificationPreferences::DEFAULT_DAYS[NotificationPreferences::KIND_OFFER], null, $isins)['created'];
            foreach ((new NotificationPreferences($db))->customIntervals()[NotificationPreferences::KIND_OFFER] ?? [] as $days => $issuerIds) {
                $created += $offerWatch->remind($today, true, (int) $days, $issuerIds, $isins)['created'];
            }
            Logger::info('Напоминания об офертах сразу после объявления НРД (бумаг: ' . count($isins) . "): создано {$created}");
        }
    } catch (\Throwable $e) {
        CronGuard::report('poll_getnews', 'Напоминание об оферте сразу после объявления НРД не создано — его создаст утренний запуск payment_reminders.php. ' . $e->getMessage());
    }
}

if ($gapLines !== []) {
    AdminNotifier::sendLines(array_merge(
        ['⚠️ GetNews: сообщения по нашим бумагам, которым не нашлось выплаты в графике (' . count($gapLines) . '). Стоит проверить график с Мосбиржи:'],
        array_slice($gapLines, 0, 40),
        count($gapLines) > 40 ? ['… и ещё ' . (count($gapLines) - 40)] : [],
    ));
}
if ($unreadable !== []) {
    // Смена формата сообщений НРД — вопрос к коду, а не к данным: разработчику.
    AdminNotifier::sendTechLines(array_merge(
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
