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
 * НРА — у агентства нет отдельной страницы "снимок сейчас" (вся история
 * одним файлом с 2020 года) — "истина сейчас" пересчитывается здесь как
 * последняя по дате строка НА КАЖДОГО ЭМИТЕНТА среди кандидатов
 * NraImporter::fetchCreditRatingCandidates().
 *
 * АКРА/Эксперт РА — сюда пока не добавлены: у АКРА current_ratings
 * наполняется вручную из JSON-файла пользователя (нет автоматического
 * "снимка сейчас" для сверки), у Эксперт РА полный автоматический
 * импортёр current_ratings ещё не реализован (см. README.md/
 * docs/STAGE3_RATINGS.md) — добавить сюда, когда появятся.
 *
 * Запуск:
 *   php bin/reconcile_ratings.php --agency=nkr
 *   php bin/reconcile_ratings.php --agency=nra
 *   php bin/reconcile_ratings.php --agency=all
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
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\NkrImporter;
use BondKeeper\Ratings\NraImporter;
use BondKeeper\Ratings\RatingActionsWriter;
use BondKeeper\Ratings\RatingsNormalizer;
use BondKeeper\Support\Logger;

$agency = 'all';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--agency=')) {
        $agency = substr($arg, 9);
    }
}

if (!in_array($agency, ['nkr', 'nra', 'all'], true)) {
    fwrite(STDERR, "Использование: php bin/reconcile_ratings.php --agency=nkr|nra|all\n");
    exit(1);
}

$db = Database::connection();
$matcher = new IssuerMatcher($db);
$reconciler = new CurrentRatingsReconciler($db);

/**
 * @param array{
 *     snapshot_count: int,
 *     field_mismatches: array<int, array{issuer_id: int, issuer_name: string, field: string, ours: ?string, theirs: ?string}>,
 *     missing_in_ours: array<int, array{issuer_id: int, issuer_name: string}>,
 *     missing_in_snapshot: array<int, array{issuer_id: int, issuer_name: string, ours: ?string}>
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
        Logger::info("[{$agency}]   {$d['issuer_name']} (issuer_id={$d['issuer_id']}) — current_ratings для этой пары не существует");
    }

    Logger::info("[{$agency}] Эмитентов у нас, которых свежий снимок не упоминает: " . count($result['missing_in_snapshot']));
    foreach ($result['missing_in_snapshot'] as $d) {
        Logger::info("[{$agency}]   {$d['issuer_name']} (issuer_id={$d['issuer_id']}) — у нас rating=" . var_export($d['ours'], true) . ', в снимке агентства не найден');
    }
}

if ($agency === 'nkr' || $agency === 'all') {
    Logger::info('=== Сверка current_ratings: НКР ===');
    $snapshot = (new NkrImporter($db, $matcher))->fetchSnapshot();
    printReconcileReport('nkr', $reconciler->reconcile('nkr', $snapshot));
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

    printReconcileReport('nra', $reconciler->reconcile('nra', array_values($latestByIssuer)));
}

Logger::info('Готово.');
