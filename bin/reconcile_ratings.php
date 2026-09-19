<?php

declare(strict_types=1);

/**
 * Сверка current_ratings с "истиной" от агентства напрямую — НЕ
 * импортёр (ничего не пишет в БД), а аудитор: сравнивает то, что
 * накопилось у нас инкрементально, с актуальным снимком "сейчас" от
 * агентства, печатает расхождения в отчёт. См. докблок
 * src/Ratings/CurrentRatingsReconciler.php — почему это отдельный класс,
 * а не режим внутри NkrImporter/NraImporter, и почему расхождения не
 * чинятся автоматически (хотя бы для начала).
 *
 * НКР — переиспользует NkrImporter::fetchSnapshot() (тот же самый
 * скачанный и разобранный Excel, что и обычный `--agency=nkr`, просто
 * БЕЗ записи в БД).
 *
 * Эксперт РА — тоже ЕСТЬ отдельный "снимок сейчас" (список действующих
 * рейтингов по категориям на raexpert.ru, не история с датами начала) —
 * переиспользует ExpertRaImporter::fetchSnapshot() буквально, по тому же
 * принципу, что и НКР. Реальный полный обход категорий + резолв ИНН по
 * карточке каждой компании — тот же сетевой объём, что и обычный
 * `--agency=expert_ra` (уже стоит в кроне раз в месяц), не более
 * "дёшево".
 *
 * НРА — у агентства нет отдельной страницы "снимок сейчас" (вся история
 * одним файлом с 2020 года) — "истина сейчас" пересчитывается здесь как
 * последняя по дате строка НА КАЖДОГО ЭМИТЕНТА среди кандидатов
 * NraImporter::fetchCreditRatingCandidates().
 *
 * АКРА — пока не добавлена: current_ratings наполняется вручную из
 * JSON-файла пользователя, нет автоматического "снимка сейчас" для
 * сверки — добавить сюда, если/когда автоматический обход АКРА появится.
 *
 * Запуск:
 *   php bin/reconcile_ratings.php --agency=nkr
 *   php bin/reconcile_ratings.php --agency=expert_ra
 *   php bin/reconcile_ratings.php --agency=nra
 *   php bin/reconcile_ratings.php --agency=all
 *   php bin/reconcile_ratings.php --agency=nkr --apply-missing
 *
 * --apply-missing (кейс 3, сентябрь 2026, прямой запрос пользователя:
 * "если появился новый эмитент у агентства, которого ранее не было в БД,
 * то добавить") — после сверки записывает missing_in_ours НАПРЯМУЮ из
 * снимка через CurrentRatingsReconciler::applyMissingInOurs(). Не трогает
 * ни field_mismatches, ни missing_in_snapshot — оба вида по решению
 * пользователя остаются только в отчёте, не чинятся автоматически (см.
 * докблок CurrentRatingsReconciler).
 *
 * По расписанию — тем же ритмом, что и обычный полный (перезаписывающий)
 * прогон nkr/nra: НКР — 1 число месяца, вместе с seed_ratings.php
 * --agency=nkr (0 1 1 * *); НРА можно чаще, раз в сутки/неделю, у неё и
 * так частый инкрементальный прогон (--agency=nra на общем 30-минутном
 * расписании). Этот инструмент НЕ заменяет полный прогон — тот всё равно
 * нужен, чтобы двигать данные вперёд; сверка ДОПОЛНЯЕТ его отчётом о
 * расхождениях, которые полный прогон тихо перезаписывает не сообщая.
 *   0 2 1 * * /usr/bin/php /path/to/bondkeeper/bin/reconcile_ratings.php --agency=all >> /var/log/bondkeeper/reconcile_ratings.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Events\EventPublisher;
use BondKeeper\Ratings\CurrentRatingsReconciler;
use BondKeeper\Ratings\ExpertRaClient;
use BondKeeper\Ratings\ExpertRaImporter;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\NkrImporter;
use BondKeeper\Ratings\NraImporter;
use BondKeeper\Ratings\RatingActionsWriter;
use BondKeeper\Ratings\RatingsNormalizer;
use BondKeeper\Support\Logger;

$agency = 'all';
$applyMissing = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--agency=')) {
        $agency = substr($arg, 9);
    }
    if ($arg === '--apply-missing') {
        $applyMissing = true;
    }
}

if (!in_array($agency, ['nkr', 'expert_ra', 'nra', 'all'], true)) {
    fwrite(STDERR, "Использование: php bin/reconcile_ratings.php --agency=nkr|expert_ra|nra|all [--apply-missing]\n");
    exit(1);
}

$db = Database::connection();
$matcher = new IssuerMatcher($db);
$reconciler = new CurrentRatingsReconciler($db);

/**
 * @param array{
 *     snapshot_count: int,
 *     field_mismatches: array<int, array{issuer_id: int, issuer_name: string, field: string, ours: ?string, theirs: ?string}>,
 *     missing_in_ours: array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string}>,
 *     missing_in_snapshot: array<int, array{issuer_id: int, issuer_name: string, ours: ?string, expected: bool}>
 * } $result
 */
function printReconcileReport(string $agency, array $result): void
{
    Logger::info("[{$agency}] Эмитентов в свежем снимке агентства: {$result['snapshot_count']}");

    Logger::info("[{$agency}] Расхождений по полям: " . count($result['field_mismatches']));
    foreach ($result['field_mismatches'] as $d) {
        Logger::info(
            "[{$agency}]   {$d['issuer_name']} (issuer_id={$d['issuer_id']}) — поле '{$d['field']}':"
            . ' у нас = ' . var_export($d['ours'], true) . ', у агентства = ' . var_export($d['theirs'], true)
        );
    }

    Logger::info("[{$agency}] Эмитентов у агентства, которых у нас нет вообще: " . count($result['missing_in_ours']));
    foreach ($result['missing_in_ours'] as $d) {
        Logger::info("[{$agency}]   {$d['issuer_name']} (issuer_id={$d['issuer_id']}) — current_ratings для этой пары не существует (rating={$d['rating']})");
    }

    // Кейс 5b: 'expected' (matched_by_root_name/issuer_spv_links) —
    // ожидаемое расхождение (мы взяли рейтинг SPV из старой новости,
    // снимок агентства его под этим именем не показывает), не сигнал
    // сбоя снимка — см. докблок CurrentRatingsReconciler.
    $expected = array_filter($result['missing_in_snapshot'], static fn (array $d): bool => $d['expected']);
    $unexplained = array_filter($result['missing_in_snapshot'], static fn (array $d): bool => !$d['expected']);
    Logger::info("[{$agency}] Эмитентов у нас, которых свежий снимок не упоминает: " . count($result['missing_in_snapshot']) . ' (из них ожидаемых SPV/root-совпадений: ' . count($expected) . ')');
    foreach ($unexplained as $d) {
        Logger::info("[{$agency}]   {$d['issuer_name']} (issuer_id={$d['issuer_id']}) — у нас rating=" . var_export($d['ours'], true) . ', в снимке агентства не найден');
    }
    foreach ($expected as $d) {
        Logger::info("[{$agency}]   {$d['issuer_name']} (issuer_id={$d['issuer_id']}) — у нас rating=" . var_export($d['ours'], true) . ', в снимке агентства не найден (ОЖИДАЕМО — SPV/root-совпадение)');
    }
}

if ($agency === 'nkr' || $agency === 'all') {
    Logger::info('=== Сверка current_ratings: НКР ===');
    $snapshot = (new NkrImporter($db, $matcher))->fetchSnapshot();
    $result = $reconciler->reconcile('nkr', $snapshot);
    printReconcileReport('nkr', $result);
    if ($applyMissing && $result['missing_in_ours'] !== []) {
        $applied = $reconciler->applyMissingInOurs('nkr', $result['missing_in_ours']);
        Logger::info("[nkr] --apply-missing: записано новых строк current_ratings: {$applied}");
    }
}

if ($agency === 'expert_ra' || $agency === 'all') {
    Logger::info('=== Сверка current_ratings: Эксперт РА ===');
    $snapshot = (new ExpertRaImporter($db, $matcher, new ExpertRaClient()))->fetchSnapshot();
    $result = $reconciler->reconcile('expert_ra', $snapshot);
    printReconcileReport('expert_ra', $result);
    if ($applyMissing && $result['missing_in_ours'] !== []) {
        $applied = $reconciler->applyMissingInOurs('expert_ra', $result['missing_in_ours']);
        Logger::info("[expert_ra] --apply-missing: записано новых строк current_ratings: {$applied}");
    }
}

if ($agency === 'nra' || $agency === 'all') {
    Logger::info('=== Сверка current_ratings: НРА ===');
    $writer = new RatingActionsWriter($db, new EventPublisher($db));
    $candidates = (new NraImporter($db, $matcher, $writer))->fetchCreditRatingCandidates();

    /** @var array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string}> $latestByIssuer */
    $latestByIssuer = [];
    foreach ($candidates as $row) {
        $issuerId = $matcher->findIssuerIdByInn($row['_inn']);
        if ($issuerId === null) {
            continue;
        }

        if (!isset($latestByIssuer[$issuerId]) || $row['_date'] > $latestByIssuer[$issuerId]['last_action_date']) {
            $baseOutlook = RatingsNormalizer::mapOutlook(RatingsNormalizer::stripWatchSuffix(trim($row['Прогноз'] ?? '')));
            $latestByIssuer[$issuerId] = [
                'issuer_id' => $issuerId,
                'issuer_name' => (string) ($row['Название организации'] ?? ''),
                'rating' => mb_substr(trim($row['Рейтинг'] ?? ''), 0, 20),
                'outlook' => RatingsNormalizer::combineWithWatchStatus($baseOutlook, trim($row['Под наблюдением'] ?? '')),
                'last_action_date' => $row['_date'],
            ];
        }
    }

    $result = $reconciler->reconcile('nra', array_values($latestByIssuer));
    printReconcileReport('nra', $result);
    if ($applyMissing && $result['missing_in_ours'] !== []) {
        $applied = $reconciler->applyMissingInOurs('nra', $result['missing_in_ours']);
        Logger::info("[nra] --apply-missing: записано новых строк current_ratings: {$applied}");
    }
}

Logger::info('Готово.');
