<?php

declare(strict_types=1);

/**
 * Самостоятельный процесс на случай, если на сервере НЕТ доступа к
 * обычному OS cron (см. bin/daemon_nkr_news.php — та же причина). Проще,
 * чем НКР-шный демон: у НРА нет деления на "частый"/"глубокий" прогон —
 * дедуп по rating_news_log сам решает, какие строки уже обработаны,
 * окна по датам не нужно (см. докблок NraImporter).
 *
 * Запуск:
 *   nohup php bin/daemon_nra.php >> /var/log/bondkeeper/daemon_nra.log 2>&1 &
 * Остановка — обычный kill процесса (PID выводится в лог при старте).
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\NraImporter;
use BondKeeper\Support\Logger;

const INTERVAL_SECONDS = 1800; // 30 минут

Logger::info('НРА-демон запущен, PID=' . getmypid() . ' (цикл каждые ' . (INTERVAL_SECONDS / 60) . ' мин)');

while (true) {
    Logger::info('НРА-демон: старт прогона');
    try {
        $db = Database::connection();
        $matcher = new IssuerMatcher($db);
        (new NraImporter($db, $matcher))->import();
    } catch (\Throwable $e) {
        // Одна неудачная попытка не должна убивать весь процесс —
        // следующий прогон через обычный интервал попробует снова.
        Logger::warn('НРА-демон: прогон завершился с ошибкой, продолжаем цикл: ' . $e->getMessage());
    }

    Logger::info('НРА-демон: сплю ' . (INTERVAL_SECONDS / 60) . ' мин до следующего прогона');
    sleep(INTERVAL_SECONDS);
}
