<?php

declare(strict_types=1);

/**
 * Этап 3: наполнить current_ratings (и, для НРА, rating_actions) из
 * выгрузок/сайтов рейтинговых агентств. Один флаг --agency на запуск —
 * у каждого агентства свой источник и свой формат, общего импортёра на
 * все 4 нет.
 *
 * АКРА — особый случай: www.acra-ratings.ru блокирует автоматические
 * запросы через 2-3 попытки (WAF + Yandex SmartCaptcha) — та же граница,
 * что действовала для service.nalog.ru с самого начала проекта: не
 * обходим и не подстраиваем технику под защиту от ботов, независимо от
 * того, какой это сервис. Поэтому seed_ratings.php сам НИКОГДА не
 * обращается к acra-ratings.ru — --agency=acra читает уже готовый
 * JSON-файл, который пользователь готовит сам (не автоматизированным
 * опросом их сайта), см. docs/STAGE3_RATINGS.md.
 *
 * Запуск (current_ratings):
 *   php bin/seed_ratings.php --agency=nkr
 *   php bin/seed_ratings.php --agency=nra
 *   php bin/seed_ratings.php --agency=expert_ra [--delay-ms=400]
 *   php bin/seed_ratings.php --agency=acra --file=/path/to/acra_issuers.json
 *   php bin/seed_ratings.php --agency=manual --file=/path/to/ratings.xlsx
 *
 * Запуск (rating_actions — история рейтинговых действий из новостей):
 *   php bin/seed_ratings.php --agency=nkr-news [--full]
 *   php bin/seed_ratings.php --agency=expert_ra-news [--delay-ms=400] [--full]
 *
 * *-news — парсинг пресс-релизов, не текущих рейтингов. По умолчанию
 * инкрементально (останавливается на уже известной дате — не перекачивает
 * всю историю заново каждый день). --full игнорирует эту границу: нужно
 * периодически (не каждый день), потому что граница основана на том, что
 * реально ЗАПИСАНО, а не на том, что реально просмотрено — новость,
 * пропущенная из-за несопоставленного на тот момент эмитента, сама не
 * пересматривается, если эмитента потом добавили. См. docs/STAGE3_RATINGS.md.
 * НКР даёт ИНН на странице каждого пресс-релиза (надёжно); Эксперт РА —
 * только название в тексте, сопоставление по точному совпадению названия,
 * не по ИНН (см. IssuerMatcher::findIssuerIdByName).
 *
 * АКРА и НРА для новостей пока не реализованы: у АКРА тот же WAF, что и
 * для current_ratings (см. ниже); у НРА пользователь ведёт мониторинг
 * новых действий через email-подписку отдельно от этого скрипта.
 *
 * manual — не настоящее агентство, а разовая ручная подгрузка: xlsx-файл
 * (ИНН|issuer_id|Полное наименование|agency|rating|outlook|last_action_date)
 * с рейтингами, которые не нашлись через автоматические источники —
 * пользователь собирает их сам, файл может содержать записи сразу для
 * нескольких агентств (столбец agency в самом файле). См. docs/STAGE3_RATINGS.md.
 *
 * expert_ra — единственный источник без прямой Excel-выгрузки: обходит
 * боевой сайт агентства постранично (список рейтингов по каждой из ~10
 * категорий) плюс по одному запросу на карточку каждой компании (ради
 * ИНН — единственный надёжный способ сопоставить с issuers.id). Больше
 * тысячи запросов за прогон — с задержкой между КАЖДЫМ запросом
 * (--delay-ms, по умолчанию 400мс). Полный прогон вживую (27 августа
 * 2026, все 10 категорий, 1163 уникальные карточки) занял ~54 минуты —
 * не из-за задержки, а из-за собственной медлительности сервера
 * raexpert.ru на страницах карточек (секунды на запрос, не доли
 * секунды). Это норма для этого источника, не зависание — см.
 * docs/STAGE3_RATINGS.md.
 *
 * По расписанию — по одному агентству за раз, не одновременно
 * (вежливость к чужим серверам, та же логика, что и у check_fns_blocks.php).
 * expert_ra поставлен пораньше и с запасом (около часа на сам прогон),
 * чтобы не пересекаться с check_fns_blocks.php в 8:00:
 *   0 5 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nkr >> /var/log/bondkeeper/seed_ratings_nkr.log 2>&1
 *   30 5 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nra >> /var/log/bondkeeper/seed_ratings_nra.log 2>&1
 *   0 6 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=expert_ra >> /var/log/bondkeeper/seed_ratings_expert_ra.log 2>&1
 *   45 6 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nkr-news >> /var/log/bondkeeper/seed_ratings_nkr_news.log 2>&1
 *   0 7 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=expert_ra-news >> /var/log/bondkeeper/seed_ratings_expert_ra_news.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\AcraImporter;
use BondKeeper\Ratings\ExpertRaClient;
use BondKeeper\Ratings\ExpertRaImporter;
use BondKeeper\Ratings\ExpertRaNewsImporter;
use BondKeeper\Ratings\IssuerMatcher;
use BondKeeper\Ratings\ManualRatingsImporter;
use BondKeeper\Ratings\NkrImporter;
use BondKeeper\Ratings\NkrNewsImporter;
use BondKeeper\Ratings\NraImporter;
use BondKeeper\Ratings\RatingActionsWriter;
use BondKeeper\Support\Logger;

$agency = null;
$delayMs = 400;
$file = null;
$full = false;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--agency=')) {
        $agency = substr($arg, strlen('--agency='));
    }
    if (str_starts_with($arg, '--delay-ms=')) {
        $delayMs = (int) substr($arg, strlen('--delay-ms='));
    }
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, strlen('--file='));
    }
    if ($arg === '--full') {
        $full = true;
    }
}

if ($agency === null) {
    fwrite(STDERR, "Использование: php bin/seed_ratings.php --agency=nkr|nra|expert_ra|acra|manual|nkr-news|expert_ra-news [--delay-ms=400] [--file=...] [--full]\n");
    exit(1);
}

$db = Database::connection();
$matcher = new IssuerMatcher($db);

Logger::info("Старт: сидирование рейтингов ({$agency})");

switch ($agency) {
    case 'nkr':
        (new NkrImporter($db, $matcher))->import();
        break;
    case 'nra':
        (new NraImporter($db, $matcher))->import();
        break;
    case 'expert_ra':
        (new ExpertRaImporter($db, $matcher, new ExpertRaClient(), $delayMs * 1000))->import();
        break;
    case 'acra':
        if ($file === null) {
            fwrite(STDERR, "Для --agency=acra обязателен --file=/path/to/acra_issuers.json (см. docs/STAGE3_RATINGS.md — этот импортёр никогда не обращается к acra-ratings.ru сам)\n");
            exit(1);
        }
        (new AcraImporter($db, $matcher))->importFromFile($file);
        break;
    case 'manual':
        if ($file === null) {
            fwrite(STDERR, "Для --agency=manual обязателен --file=/path/to/ratings.xlsx\n");
            exit(1);
        }
        (new ManualRatingsImporter($db, $matcher))->importFromFile($file);
        break;
    case 'nkr-news':
        (new NkrNewsImporter($db, $matcher, new RatingActionsWriter($db)))->import($full);
        break;
    case 'expert_ra-news':
        (new ExpertRaNewsImporter($db, $matcher, new RatingActionsWriter($db), new ExpertRaClient(), $delayMs * 1000))->import($full);
        break;
    default:
        fwrite(STDERR, "Неизвестное агентство: {$agency}. Поддерживаются: nkr, nra, expert_ra, acra, manual, nkr-news, expert_ra-news.\n");
        exit(1);
}

Logger::info('Готово.');
