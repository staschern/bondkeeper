<?php

declare(strict_types=1);

/**
 * Опрос GetNews (НРД): получить сообщения -> GetNewsMessageMapper ->
 * PaymentProcessor::process(). Последний кусок конвейера "подключения"
 * к API (docs/STAGE5_PAYMENTS.md, раздел 8, пункт 3) — GetNewsClient и
 * GetNewsMessageMapper были готовы раньше, этот скрипт их соединяет и
 * пишет результат в БД.
 *
 * ОСТОРОЖНО: включение этого скрипта в прод = настоящие уведомления
 * клиентам по купонам/погашениям/амортизациям. Коды A2/A4/A6/B1/B2/B4/B5
 * уведомляют клиентов (notify_client=TRUE) с самой первой миграции —
 * это общий справочник event_types, отдельно для сообщений от НРД его
 * не выключить. Поэтому:
 *   - --dry-run работает ВСЕГДА, даже если config/payments.php не создан
 *     или getnews_polling=false — только печатает, что получилось бы,
 *     ничего не трогает в БД;
 *   - реальная запись требует config/payments.php: 'getnews_polling' => true
 *     (см. config/payments.example.php).
 *
 * Окно по умолчанию — ПЕРЕКРЫВАЮЩЕЕСЯ (--days=2, как у seed_ratings.php
 * --agency=X-news --days=2): не нужно хранить "последний успешный
 * запуск" отдельно — PaymentProcessor сам отсекает повторы по
 * content_id_out (source_ref в raw_messages), повторный проход по той
 * же дате безопасен.
 *
 * Сообщения по бумагам, которых у нас вообще нет в securities (огромное
 * большинство ленты — весь рынок НРД, а не только наши отслеживаемые
 * выпуски) — это НЕ ошибка, тихо считаются и логируются, администратору
 * не шлются. Администратору шлются только "пробелы в графике": бумага
 * У НАС есть, а нужной даты выплаты в coupons/amortizations/redemptions
 * не нашлось — это повод проверить bondization-данные.
 *
 * Запуск:
 *   php bin/poll_getnews.php --dry-run                      (просмотр, не пишет в БД)
 *   php bin/poll_getnews.php --dry-run --from=2026-09-18 --to=2026-10-02  (тестовый доступ — фиксированное окно)
 *   php bin/poll_getnews.php                                 (пишет в БД, если getnews_polling=true; --days=2 от сегодня)
 *   php bin/poll_getnews.php --days=5
 *
 * По расписанию (когда появится боевой, не тестовый доступ) — пример,
 * каждые 30 минут в будни, как и у новостных лент рейтингов:
 *   * /30 * * * 1-5 /usr/bin/php /path/to/bondkeeper/bin/poll_getnews.php >> /var/log/bondkeeper/poll_getnews.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Events\EventPublisher;
use BondKeeper\Payments\GetNewsClient;
use BondKeeper\Payments\GetNewsConfig;
use BondKeeper\Payments\GetNewsMessageMapper;
use BondKeeper\Payments\PaymentProcessor;
use BondKeeper\Payments\PaymentsConfig;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\AdminNotifier;

$dryRun = in_array('--dry-run', $argv, true);
$days = 2;
$limit = 1000;
$explicitFrom = null;
$explicitTo = null;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = (int) $m[1];
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
    if (preg_match('/^--from=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $explicitFrom = $m[1];
    }
    if (preg_match('/^--to=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $explicitTo = $m[1];
    }
}

$paymentsConfig = PaymentsConfig::fromFile(__DIR__ . '/../config/payments.php');
if (!$paymentsConfig->getNewsPolling && !$dryRun) {
    Logger::info('Опрос GetNews выключен (config/payments.php: getnews_polling). Ничего не делаю. Проверить вывод можно через --dry-run.');
    exit(0);
}

$dateTo = $explicitTo ?? (new DateTimeImmutable('now'))->format('Y-m-d');
$dateFrom = $explicitFrom ?? (new DateTimeImmutable('now'))->modify("-{$days} days")->format('Y-m-d');
$filter = [
    '$and' => [
        ['category' => 'CORP_ACTION'],
        ['pub_date' => ['$gte' => $dateFrom, '$lte' => $dateTo]],
    ],
];

$getNewsConfig = GetNewsConfig::fromFile(__DIR__ . '/../config/nsd_api.php');
$client = new GetNewsClient($getNewsConfig);

try {
    $items = $client->fetchNews($filter, $limit, 0);
} catch (\Throwable $e) {
    Logger::error('GetNews: ошибка запроса — ' . $e->getMessage());
    if (!$dryRun) {
        AdminNotifier::send('⚠️ Опрос GetNews: ошибка запроса — ' . $e->getMessage());
    }
    exit(1);
}

$prefix = $dryRun ? '=== ПРОСМОТР (ничего не пишет в БД) === ' : '';
Logger::info("{$prefix}GetNews {$dateFrom}..{$dateTo}: получено сообщений: " . count($items));
if (count($items) === $limit) {
    Logger::warn("Получено ровно --limit={$limit} — возможно, окно обрезано, нужна пагинация (--skip= в GetNewsClient пока нет).");
}

$mapped = 0;
$skipped = 0;

if ($dryRun) {
    foreach ($items as $rawMessage) {
        $message = GetNewsMessageMapper::map($rawMessage);
        if ($message === null) {
            $skipped++;
            continue;
        }
        $mapped++;
        Logger::info(sprintf(
            '[dry-run] %s %s %s/%s %s на %s (sourceRef=%s)',
            $message->isin,
            $message->kind,
            $message->stage,
            $message->execution,
            $message->amountPerBond ?? '?',
            $message->paymentDate,
            $message->sourceRef,
        ));
    }
    Logger::info("Сопоставлено сообщений (PaymentMessage): {$mapped}; пропущено (N/C/неизвестный ca_type/нет ISIN): {$skipped}. В БД ничего не записано (--dry-run).");
    exit(0);
}

$db = Database::connection();
$calendar = WorkingCalendar::fromFile(__DIR__ . '/../config/working_calendar.php');
$processor = new PaymentProcessor($db, new EventPublisher($db), $calendar);

$counts = [
    PaymentProcessor::RESULT_PROCESSED => 0,
    PaymentProcessor::RESULT_DUPLICATE => 0,
    PaymentProcessor::RESULT_NO_CHANGE => 0,
];
$unmatchedForeign = 0;
/** @var array<int, string> $gapLines */
$gapLines = [];

foreach ($items as $rawMessage) {
    $message = GetNewsMessageMapper::map($rawMessage);
    if ($message === null) {
        $skipped++;
        continue;
    }
    $mapped++;

    $result = $processor->process($message);
    if ($result['result'] === PaymentProcessor::RESULT_UNMATCHED) {
        if (str_contains((string) $result['note'], 'не найдена в securities')) {
            // Огромное большинство ленты — весь рынок НРД, не только
            // наши отслеживаемые выпуски. Не ошибка, не сообщаем.
            $unmatchedForeign++;
        } else {
            // Бумага у нас есть, а нужной даты выплаты в графике нет —
            // это повод проверить bondization-данные.
            $gapLines[] = "{$message->isin} ({$message->kind}, {$message->paymentDate}): {$result['note']}";
        }
        continue;
    }
    $counts[$result['result']] = ($counts[$result['result']] ?? 0) + 1;
    if ($result['events'] !== []) {
        Logger::info("{$message->isin} {$message->kind} на {$message->paymentDate}: " . implode(', ', $result['events']));
    }
}

Logger::info("Сопоставлено сообщений: {$mapped}; пропущено (N/C/неизвестный ca_type): {$skipped}");
Logger::info(sprintf(
    'Обработано: %d; повторы: %d; без изменений: %d; чужие бумаги (не наши): %d; пробелы в графике (наша бумага, выплата не найдена): %d',
    $counts[PaymentProcessor::RESULT_PROCESSED],
    $counts[PaymentProcessor::RESULT_DUPLICATE],
    $counts[PaymentProcessor::RESULT_NO_CHANGE],
    $unmatchedForeign,
    count($gapLines),
));

if ($gapLines !== []) {
    AdminNotifier::sendLines(array_merge(
        ['⚠️ GetNews: сообщения по нашим бумагам, которые не удалось привязать к графику выплат (' . count($gapLines) . '):'],
        $gapLines,
    ));
}
