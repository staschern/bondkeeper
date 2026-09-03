<?php

declare(strict_types=1);

/**
 * Самостоятельный процесс на случай, если на сервере НЕТ доступа к
 * обычному OS cron (пользователь не был уверен) — вместо двух crontab-
 * строк (частой и "глубокой", см. докблок bin/seed_ratings.php) один
 * процесс с собственным циклом: висит в фоне, сам себя планирует.
 *
 * Логика:
 *   - каждые 30 минут — php bin/seed_ratings.php --agency=nkr-news --days=2
 *   - раз в сутки (первый проход после 24 часов работы, дальше — каждые
 *     24 часа от этого момента) — тот же прогон, но --days=14 вместо
 *     обычного цикла на этой итерации (см. docblok NkrNewsImporter про
 *     разницу между "частым" и "глубоким" окном).
 *   - между прогонами — sleep(). Если прогон сам занял время (сеть,
 *     нагрузка сайта агентства) — следующий старт всё равно считается от
 *     МОМЕНТА ЗАВЕРШЕНИЯ предыдущего, не от расписания "часы:минуты"
 *     (то есть интервалы не накапливаются и не находят друг на друга).
 *
 * Запуск (полностью заменяет собой crontab-записи для nkr-news):
 *   nohup php bin/daemon_nkr_news.php >> /var/log/bondkeeper/daemon_nkr_news.log 2>&1 &
 * Остановка — обычный kill процесса (PID выводится в лог при старте) или
 * Ctrl+C, если запущен на переднем плане. Если сервер перезагрузится —
 * процесс не поднимется сам собой, для этого и нужен настоящий OS cron
 * ИЛИ супервизор процессов (systemd/supervisord/pm2 — что уже используется
 * на сервере для других процессов, если что-то используется); сам по
 * себе этот скрипт не претендует на замену системного планировщика,
 * только на работу, когда доступа к нему нет вообще.
 *
 * НЕ демонизируется средствами PHP (не форкается, не отсоединяется от
 * терминала) — это делает вызывающая команда (nohup/systemd/screen/tmux).
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\NkrNewsImporter;
use BondKeeper\Ratings\RatingActionsWriter;
use BondKeeper\Support\Logger;

const FAST_INTERVAL_SECONDS = 1800; // 30 минут
const DEEP_INTERVAL_SECONDS = 86400; // 24 часа
const FAST_DAYS = 2;
const DEEP_DAYS = 14;

Logger::info('НКР-демон запущен, PID=' . getmypid() . " (частый цикл каждые " . (FAST_INTERVAL_SECONDS / 60) . " мин, глубокий — раз в " . (DEEP_INTERVAL_SECONDS / 3600) . " ч)");

$lastDeepRunAt = 0; // 0 — первый проход в цикле сразу же будет "глубоким" (безопаснее, чем ждать сутки после рестарта)

while (true) {
    $isDeepRun = (time() - $lastDeepRunAt) >= DEEP_INTERVAL_SECONDS;
    $days = $isDeepRun ? DEEP_DAYS : FAST_DAYS;

    Logger::info('НКР-демон: старт прогона (' . ($isDeepRun ? 'глубокий' : 'частый') . ", --days={$days})");
    try {
        $db = Database::connection();
        $matcher = new IssuerMatcher($db);
        (new NkrNewsImporter($db, $matcher, new RatingActionsWriter($db)))->import(false, $days);
    } catch (\Throwable $e) {
        // Одна неудачная попытка (сеть, временная ошибка сайта агентства)
        // не должна убивать весь процесс — тот же принцип, что и у
        // RatingsHttp::get() с ретраями внутри одного прогона, только на
        // уровень выше: следующий прогон через обычный интервал попробует
        // снова, ничего вручную перезапускать не нужно.
        Logger::warn('НКР-демон: прогон завершился с ошибкой, продолжаем цикл: ' . $e->getMessage());
    }

    if ($isDeepRun) {
        $lastDeepRunAt = time();
    }

    Logger::info('НКР-демон: сплю ' . (FAST_INTERVAL_SECONDS / 60) . ' мин до следующего прогона');
    sleep(FAST_INTERVAL_SECONDS);
}
