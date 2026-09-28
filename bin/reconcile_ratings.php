<?php

declare(strict_types=1);

/**
 * Сверка current_ratings с "истиной" от агентства напрямую — НЕ
 * импортёр (current_ratings не пишет, кроме явного --apply-missing), а
 * аудитор: сравнивает то, что накопилось у нас инкрементально, с
 * актуальным снимком "сейчас" от агентства, печатает расхождения в отчёт.
 * См. докблок src/Ratings/CurrentRatingsReconciler.php.
 *
 * НКР — NkrImporter::fetchSnapshot() (тот же Excel, что и обычный
 * `--agency=nkr`). Эксперт РА — ExpertRaImporter::fetchSnapshot()
 * (полный обход категорий + карточки, ~30-50 минут). АКРА (П7) —
 * AcraImporter::readSnapshotFromFile(), JSON-файл, который пользователь
 * готовит сам (--file=...; в --agency=all АКРА входит, только если
 * передан --file). НРА — у агентства нет отдельного "снимка сейчас",
 * он пересчитывается как последняя по дате строка на каждого эмитента
 * среди NraImporter::fetchCreditRatingCandidates().
 *
 * Запуск:
 *   php bin/reconcile_ratings.php --agency=nkr
 *   php bin/reconcile_ratings.php --agency=expert_ra
 *   php bin/reconcile_ratings.php --agency=nra
 *   php bin/reconcile_ratings.php --agency=acra --file=/path/to/acra_issuers.json
 *   php bin/reconcile_ratings.php --agency=all [--file=/path/to/acra_issuers.json]
 *   php bin/reconcile_ratings.php --agency=nkr --apply-missing
 *
 * === Сначала сверка, потом перезапись (П2, решение пользователя, сентябрь 2026) ===
 *
 * Раньше в комментариях стояли перезапись НКР (01:00) и Эксперт РА
 * (01:15) 1-го числа, а сверка — в 02:00: к её запуску база уже совпадала
 * со снимком, сверка ничего не находила. Теперь сверка сохраняет снимок
 * каждого агентства (кроме НРА) в var/snapshots/ и печатает готовую
 * команду перезаписи — администратор смотрит отчёт и, если всё в
 * порядке, сам запускает:
 *   php bin/seed_ratings.php --agency=nkr --snapshot=var/snapshots/nkr-....json
 * Перезапись пишет ровно проверенный снимок, без повторного скачивания.
 * Короткая сводка сверки уходит администратору в Telegram.
 *
 * По расписанию — только сверка, 1-го числа ночью (строки перезаписи
 * --agency=nkr / --agency=expert_ra из crontab убрать):
 *   0 1 1 * * /usr/bin/php /path/to/bondkeeper/bin/reconcile_ratings.php --agency=all >> /var/log/bondkeeper/reconcile_ratings.log 2>&1
 *
 * --apply-missing (кейс 3, прямой запрос пользователя: "если появился
 * новый эмитент у агентства, которого ранее не было в БД, то добавить")
 * — после сверки записывает missing_in_ours НАПРЯМУЮ из снимка
 * (source='reconcile'). Не трогает ни field_mismatches, ни
 * missing_in_snapshot.
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Events\EventPublisher;
use BondKeeper\Ratings\AcraImporter;
use BondKeeper\Ratings\CurrentRatingsReconciler;
use BondKeeper\Ratings\ExpertRaClient;
use BondKeeper\Ratings\ExpertRaImporter;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\NameMatchResolver;
use BondKeeper\Ratings\NameMatchReviews;
use BondKeeper\Ratings\NkrImporter;
use BondKeeper\Ratings\NraImporter;
use BondKeeper\Ratings\RatingActionsWriter;
use BondKeeper\Ratings\RatingsNormalizer;
use BondKeeper\Ratings\SnapshotRows;
use BondKeeper\Support\Logger;
use BondKeeper\Telegram\AdminNotifier;

const SNAPSHOT_DIR = __DIR__ . '/../var/snapshots';

$agency = 'all';
$applyMissing = false;
$acraFile = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--agency=')) {
        $agency = substr($arg, 9);
    }
    if (str_starts_with($arg, '--file=')) {
        $acraFile = substr($arg, 7);
    }
    if ($arg === '--apply-missing') {
        $applyMissing = true;
    }
}

if (!in_array($agency, ['nkr', 'expert_ra', 'nra', 'acra', 'all'], true) || ($agency === 'acra' && $acraFile === null)) {
    fwrite(STDERR, "Использование: php bin/reconcile_ratings.php --agency=nkr|expert_ra|nra|acra|all [--file=acra.json] [--apply-missing]\n"
        . "Для --agency=acra обязателен --file=/path/to/acra_issuers.json.\n");
    exit(1);
}

$db = Database::connection();
$matcher = new IssuerMatcher($db);
$reviews = new NameMatchReviews($db);
$nameResolver = new NameMatchResolver($matcher, $reviews);
$reconciler = new CurrentRatingsReconciler($db);

/** @var array<int, string> $summary строки сводки для Telegram */
$summary = [];

/**
 * Группирует расхождения по полям в ОДНУ строку на компанию (28 сентября
 * 2026, прямой запрос пользователя после разбора первой полной сводки:
 * раньше была отдельная строка на КАЖДОЕ поле — источник нашего значения
 * и признак "ожидаемо"/"требует внимания" одинаковы для всех полей одной
 * пары (issuer_id, agency) в рамках одного прогона, реконсайлер уже
 * считает их один раз на эмитента, тут просто схлопываем).
 *
 * @param array<int, array{issuer_id: int, our_name: string, agency_name: string, field: string, ours: ?string, theirs: ?string, our_source: ?string, our_action_title: ?string, our_action_url: ?string, expected: bool, reason: ?string}> $fieldMismatches
 * @return array<int, string>
 */
function formatFieldMismatchLines(array $fieldMismatches): array
{
    $byIssuer = [];
    foreach ($fieldMismatches as $d) {
        $byIssuer[$d['issuer_id']][] = $d;
    }

    $lines = [];
    foreach ($byIssuer as $rows) {
        $first = $rows[0];
        $fieldsText = implode('; ', array_map(
            static fn (array $d): string => "{$d['field']}: наше=" . var_export($d['ours'], true) . ', агентства=' . var_export($d['theirs'], true),
            $rows,
        ));
        $line = "{$first['our_name']} (id {$first['issuer_id']}; у агентства: «{$first['agency_name']}») — {$fieldsText}; источник: " . ($first['our_source'] ?? 'не указан (до миграции 025)');
        if ($first['our_action_title'] !== null) {
            $line .= "; новость: «{$first['our_action_title']}»" . ($first['our_action_url'] !== null ? " {$first['our_action_url']}" : '');
        }
        $line .= $first['expected'] ? "; ОЖИДАЕМО: {$first['reason']}" : '; ТРЕБУЕТ ВНИМАНИЯ';
        $lines[] = $line;
    }

    return $lines;
}

/**
 * @param array{
 *     snapshot_count: int,
 *     field_mismatches: array<int, array{issuer_id: int, our_name: string, agency_name: string, field: string, ours: ?string, theirs: ?string, our_source: ?string, our_action_title: ?string, our_action_url: ?string, expected: bool, reason: ?string}>,
 *     missing_in_ours: array<int, array{issuer_id: int, our_name: string, agency_name: string, rating: string, outlook: ?string, last_action_date: string}>,
 *     missing_in_snapshot: array<int, array{issuer_id: int, our_name: string, ours: ?string, last_action_date: ?string, source: ?string, expected: bool, reason: ?string, note: ?string}>
 * } $result
 * @return array<int, string> строки "требуют внимания" ПЛЮС ожидаемые — теперь ВСЕ, без обрезки (28 сентября 2026)
 */
function printReconcileReport(string $agency, array $result): array
{
    $attention = [];
    $source = static fn (?string $s): string => $s ?? 'не указан (до миграции 025)';

    Logger::info("[{$agency}] Эмитентов в свежем снимке агентства: {$result['snapshot_count']}");

    $mismatchLines = formatFieldMismatchLines($result['field_mismatches']);
    Logger::info("[{$agency}] Расхождений по полям: " . count($result['field_mismatches']) . ' (эмитентов: ' . count($mismatchLines) . ')');
    foreach ($mismatchLines as $line) {
        Logger::info("[{$agency}]   {$line}");
        $attention[] = $line;
    }

    Logger::info("[{$agency}] Эмитентов у агентства, которых у нас нет вообще: " . count($result['missing_in_ours']));
    foreach ($result['missing_in_ours'] as $d) {
        $line = "{$d['our_name']} (id {$d['issuer_id']}; у агентства: «{$d['agency_name']}») — current_ratings для этой пары нет (rating={$d['rating']}, дата {$d['last_action_date']})";
        Logger::info("[{$agency}]   {$line}");
        $attention[] = $line;
    }

    $expected = array_filter($result['missing_in_snapshot'], static fn (array $d): bool => $d['expected']);
    $unexplained = array_filter($result['missing_in_snapshot'], static fn (array $d): bool => !$d['expected']);
    Logger::info("[{$agency}] Эмитентов у нас, которых свежий снимок не упоминает: " . count($result['missing_in_snapshot'])
        . ' (требуют внимания: ' . count($unexplained) . ', ожидаемые: ' . count($expected) . ')');
    foreach ($unexplained as $d) {
        $line = "{$d['our_name']} (id {$d['issuer_id']}) — у нас rating=" . var_export($d['ours'], true)
            . " от {$d['last_action_date']}, источник: " . $source($d['source']) . ', в снимке агентства не найден — ТРЕБУЕТ ВНИМАНИЯ';
        if ($d['note'] !== null) {
            $line .= " ({$d['note']})";
        }
        Logger::info("[{$agency}]   {$line}");
        $attention[] = $line;
    }
    foreach ($expected as $d) {
        $line = "{$d['our_name']} (id {$d['issuer_id']}) — у нас rating=" . var_export($d['ours'], true)
            . " от {$d['last_action_date']}, в снимке агентства не найден — ОЖИДАЕМО: {$d['reason']}";
        Logger::info("[{$agency}]   {$line}");
        $attention[] = $line;
    }

    return $attention;
}

/**
 * @param array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, source_url: ?string}> $snapshot
 * @return array<int, string> строки сводки для Telegram
 */
function reconcileAgency(CurrentRatingsReconciler $reconciler, string $agency, string $label, array $snapshot, bool $saveSnapshot, bool $applyMissing): array
{
    $summary = [];
    $result = $reconciler->reconcile($agency, $snapshot);
    $attention = printReconcileReport($agency, $result);

    $expectedCount = count(array_filter($result['missing_in_snapshot'], static fn (array $d): bool => $d['expected']));
    $summary[] = "{$label}: в снимке {$result['snapshot_count']}; расхождений по полям " . count($result['field_mismatches'])
        . '; нет у нас ' . count($result['missing_in_ours'])
        . '; нет в снимке ' . count($result['missing_in_snapshot']) . " (ожидаемых {$expectedCount})";
    // Все строки, без обрезки — раньше было --10--, а сводка ссылалась на
    // лог сверки, недоступный владельцу бота (не разработчику с SSH).
    foreach ($attention as $line) {
        $summary[] = "  • {$line}";
    }

    if ($applyMissing && $result['missing_in_ours'] !== []) {
        $applied = $reconciler->applyMissingInOurs($agency, $result['missing_in_ours']);
        Logger::info("[{$agency}] --apply-missing: записано новых строк current_ratings: {$applied}");
        $summary[] = "  --apply-missing: добавлено строк {$applied}";
    }

    if ($saveSnapshot) {
        $path = SnapshotRows::saveToFile($agency, $snapshot, SNAPSHOT_DIR);
        $command = "php bin/seed_ratings.php --agency={$agency} --snapshot=var/snapshots/" . basename($path);
        Logger::info("[{$agency}] Снимок сохранён: {$path}");
        Logger::info("[{$agency}] Если отчёт в порядке — перезапись этим же снимком: {$command}");
        $summary[] = "  Перезапись после проверки: {$command}";
    }

    return $summary;
}

if ($agency === 'nkr' || $agency === 'all') {
    Logger::info('=== Сверка current_ratings: НКР ===');
    $importer = new NkrImporter($db, $matcher, $nameResolver);
    $snapshot = $importer->fetchSnapshot();
    $importer->printReport();
    $summary = array_merge($summary, reconcileAgency($reconciler, 'nkr', 'НКР', $snapshot, true, $applyMissing));
}

if ($agency === 'expert_ra' || $agency === 'all') {
    Logger::info('=== Сверка current_ratings: Эксперт РА ===');
    $importer = new ExpertRaImporter($db, $matcher, new ExpertRaClient(), $nameResolver);
    $snapshot = $importer->fetchSnapshot();
    $importer->printReport();
    $summary = array_merge($summary, reconcileAgency($reconciler, 'expert_ra', 'Эксперт РА', $snapshot, true, $applyMissing));
}

if (($agency === 'acra' || $agency === 'all') && $acraFile !== null) {
    Logger::info('=== Сверка current_ratings: АКРА (из файла) ===');
    $importer = new AcraImporter($db, $matcher, $nameResolver);
    $snapshot = $importer->readSnapshotFromFile($acraFile);
    $importer->printReport();
    $summary = array_merge($summary, reconcileAgency($reconciler, 'acra', 'АКРА', $snapshot, true, $applyMissing));
}

if ($agency === 'nra' || $agency === 'all') {
    Logger::info('=== Сверка current_ratings: НРА ===');
    $writer = new RatingActionsWriter($db, new EventPublisher($db));
    $candidates = (new NraImporter($db, $matcher, $writer, $nameResolver))->fetchCreditRatingCandidates();

    // ИНН → связка issuer_spv_links. Новых предложений по названию сверка
    // НРА не создаёт: в выгрузке вся история с 2020 года, предложения по
    // давно неактуальным строкам были бы шумом — актуальные предложит
    // сам импортёр НРА (каждые 30 минут), с заголовком и ссылкой.
    $rows = [];
    foreach ($candidates as $row) {
        $issuerId = $matcher->findIssuerIdByInn($row['_inn']) ?? $matcher->findIssuerIdBySpvLink($row['_inn']);
        if ($issuerId === null) {
            continue;
        }

        $baseOutlook = RatingsNormalizer::mapOutlook(RatingsNormalizer::stripWatchSuffix(trim($row['Прогноз'] ?? '')));
        $rows[] = [
            'issuer_id' => $issuerId,
            'issuer_name' => (string) ($row['Название организации'] ?? ''),
            'rating' => mb_substr(trim($row['Рейтинг'] ?? ''), 0, 20),
            'outlook' => RatingsNormalizer::combineWithWatchStatus($baseOutlook, trim($row['Под наблюдением'] ?? '')),
            'last_action_date' => $row['_date'],
            'source_url' => ($row['Ссылка на пресс релиз'] ?? '') !== '' ? $row['Ссылка на пресс релиз'] : null,
        ];
    }

    // У НРА перезаписи снимком нет — она и так обновляется каждые 30 минут.
    $summary = array_merge($summary, reconcileAgency($reconciler, 'nra', 'НРА', SnapshotRows::latestPerIssuer($rows), false, $applyMissing));
}

/**
 * Разбивает текст на части не длиннее $maxLen символов, по границам
 * строк (не внутри одной строки) — та же защита от "Bad Request:
 * MESSAGE_TOO_LONG", что и в BotCommandHandler::splitIntoTelegramChunks()
 * (28 сентября 2026, найдено вживую на "Весь список"): сводка сверки
 * теперь печатает ВСЕ расхождения без обрезки на 10 строках (см. выше),
 * а значит сама вполне может превысить лимит Telegram 4096 символов —
 * AdminNotifier::send() раньше просто молча резала текст до 4000
 * (mb_substr), теряя хвост сводки без предупреждения.
 *
 * @return array<int, string>
 */
function splitTextIntoTelegramChunks(string $text, int $maxLen = 3500): array
{
    if ($text === '') {
        return [''];
    }

    $lines = explode("\n", $text);
    $chunks = [];
    $current = '';
    foreach ($lines as $line) {
        $candidate = $current === '' ? $line : $current . "\n" . $line;
        if (mb_strlen($candidate) > $maxLen && $current !== '') {
            $chunks[] = $current;
            $current = $line;
        } else {
            $current = $candidate;
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }

    return $chunks;
}

$summaryText = "Сверка рейтингов " . date('d.m.Y') . "\n\n" . implode("\n", $summary);
foreach (splitTextIntoTelegramChunks($summaryText) as $chunk) {
    AdminNotifier::send($chunk);
}
$reviews->notifyNewProposals([AdminNotifier::class, 'send']);

Logger::info('Готово.');
