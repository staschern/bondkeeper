<?php

declare(strict_types=1);

/**
 * Самостоятельный процесс на случай, если на сервере НЕТ доступа к
 * обычному OS cron (см. bin/daemon_nkr_news.php — та же причина).
 *
 * До сентября 2026 этот демон был простым (без деления на частый/
 * глубокий, как у НРА) — тогда сопоставление шло только по имени, без
 * запроса на детальную страницу. После того как нашёлся ИНН на странице
 * пресс-релиза (см. докблок ExpertRaNewsImporter/ExpertRaClient::
 * fetchReleaseInn()), стоимость прогона сравнялась с НКР — демон
 * переписан по тому же образцу: чередует частый (--days=2, каждые
 * 30 минут) и глубокий (--days=14, раз в сутки) прогоны.
 *
 * Запуск:
 *   nohup php bin/daemon_expert_ra_news.php >> /var/log/bondkeeper/daemon_expert_ra_news.log 2>&1 &
 * Остановка — обычный kill процесса (PID выводится в лог при старте).
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\ExpertRaClient;
use BondKeeper\Ratings\ExpertRaNewsImporter;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\RatingActionsWriter;
use BondKeeper\Support\Logger;

const FAST_INTERVAL_SECONDS = 1800; // 30 минут
const DEEP_INTERVAL_SECONDS = 86400; // 24 часа
const FAST_DAYS = 2;
const DEEP_DAYS = 14;
const DELAY_MICROSECONDS = 400_000; // пауза между запросами к сайту агентства

Logger::info('Эксперт РА-демон (новости) запущен, PID=' . getmypid() . ' (частый цикл каждые ' . (FAST_INTERVAL_SECONDS / 60) . ' мин, глубокий — раз в ' . (DEEP_INTERVAL_SECONDS / 3600) . ' ч)');

$lastDeepRunAt = 0; // 0 — первый проход в цикле сразу же будет "глубоким" (безопаснее, чем ждать сутки после рестарта)

while (true) {
    $isDeepRun = (time() - $lastDeepRunAt) >= DEEP_INTERVAL_SECONDS;
    $days = $isDeepRun ? DEEP_DAYS : FAST_DAYS;

    Logger::info('Эксперт РА-демон: старт прогона (' . ($isDeepRun ? 'глубокий' : 'частый') . ", --days={$days})");
    try {
        $db = Database::connection();
        $matcher = new IssuerMatcher($db);
        (new ExpertRaNewsImporter($db, $matcher, new RatingActionsWriter($db), new ExpertRaClient(), DELAY_MICROSECONDS))->import(false, $days);
    } catch (\Throwable $e) {
        // Одна неудачная попытка не должна убивать весь процесс —
        // следующий прогон через обычный интервал попробует снова.
        Logger::warn('Эксперт РА-демон: прогон завершился с ошибкой, продолжаем цикл: ' . $e->getMessage());
    }

    if ($isDeepRun) {
        $lastDeepRunAt = time();
    }

    Logger::info('Эксперт РА-демон: сплю ' . (FAST_INTERVAL_SECONDS / 60) . ' мин до следующего прогона');
    sleep(FAST_INTERVAL_SECONDS);
}
