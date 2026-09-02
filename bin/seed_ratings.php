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
 * Запуск (rating_actions — история рейтинговых действий из новостей,
 * рассчитан на cron каждые ~30 минут):
 *   php bin/seed_ratings.php --agency=nkr-news [--window-hours=6] [--full]
 *   php bin/seed_ratings.php --agency=expert_ra-news [--delay-ms=400] [--window-hours=6] [--full]
 *
 * *-news — парсинг пресс-релизов, не текущих рейтингов. По прямому
 * указанию пользователя — скользящее окно, а не "с последней известной
 * даты": каждый прогон обрабатывает только новости не старше
 * (сейчас − --window-hours, по умолчанию 6 часов — с запасом, чтобы
 * пропущенный/опоздавший прогон cron не потерял новость). Ключ записи —
 * ссылка на пресс-релиз (source_url) — при повторном попадании той же
 * новости в перекрывающееся окно соседних прогонов запись ОБНОВЛЯЕТСЯ,
 * а не дублируется. Строка, классифицированная по заголовку как
 * рейтинговое действие, пишется в БД ВСЕГДА — даже если не удалось
 * распознать эмитента или новый уровень рейтинга (тогда — null в этих
 * полях и пометка has_unresolved_fields/unresolved_fields для ручного
 * просмотра, см. RatingActionsWriter и docs/STAGE3_RATINGS.md).
 * --full игнорирует окно совсем и проходит всю доступную историю —
 * для первоначального наполнения таблицы, не для обычных прогонов по
 * расписанию (иначе на каждом прогоне будет перекачиваться вся лента).
 * НКР даёт ИНН на странице каждого пресс-релиза (надёжно); Эксперт РА —
 * только название в тексте, сопоставление по точному совпадению названия,
 * не по ИНН (см. IssuerMatcher::findIssuerIdByName).
 * "Старые" значения (rating_from/outlook_from) не парсятся из текста
 * новости — берутся из current_ratings ДО этого действия, а после
 * успешного разбора (эмитент и новый уровень оба распознаны)
 * current_ratings обновляется этим же новым значением.
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
 * По расписанию — current_ratings (nkr/nra/expert_ra) раз в сутки, не
 * одновременно друг с другом (вежливость к чужим серверам, та же логика,
 * что и у check_fns_blocks.php); *-news — раз в ~30 минут (окно по
 * умолчанию 6 часов с запасом с лихвой перекрывает такой интервал):
 *   0 5 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nkr >> /var/log/bondkeeper/seed_ratings_nkr.log 2>&1
 *   30 5 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nra >> /var/log/bondkeeper/seed_ratings_nra.log 2>&1
 *   0 6 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=expert_ra >> /var/log/bondkeeper/seed_ratings_expert_ra.log 2>&1
 *   0,30 * * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nkr-news >> /var/log/bondkeeper/seed_ratings_nkr_news.log 2>&1
 *   3,33 * * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=expert_ra-news >> /var/log/bondkeeper/seed_ratings_expert_ra_news.log 2>&1
 *
 * (expert_ra-news сдвинут на 3 минуты от круглой границы получаса, чтобы
 * не стартовать одновременно с nkr-news — оба ходят в сеть, но к разным
 * сайтам, так что реальной необходимости строго разносить нет, разве что
 * ради чуть более читаемых логов.)
 */

require __DIR__ . '/bootstrap.php';

use BondKeeper\Database;
use BondKeeper\Ratings\AcraImporter;
use BondKeeper\Ratings\CurrentRatingsStore;
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
$windowHours = 6;
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
    if (str_starts_with($arg, '--window-hours=')) {
        $windowHours = (int) substr($arg, strlen('--window-hours='));
    }
    if ($arg === '--full') {
        $full = true;
    }
}

if ($agency === null) {
    fwrite(STDERR, "Использование: php bin/seed_ratings.php --agency=nkr|nra|expert_ra|acra|manual|nkr-news|expert_ra-news [--delay-ms=400] [--file=...] [--window-hours=6] [--full]\n");
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
        (new NkrNewsImporter($matcher, new RatingActionsWriter($db), new CurrentRatingsStore($db)))->import($windowHours, $full);
        break;
    case 'expert_ra-news':
        (new ExpertRaNewsImporter($matcher, new RatingActionsWriter($db), new CurrentRatingsStore($db), new ExpertRaClient(), $delayMs * 1000))->import($windowHours, $full);
        break;
    default:
        fwrite(STDERR, "Неизвестное агентство: {$agency}. Поддерживаются: nkr, nra, expert_ra, acra, manual, nkr-news, expert_ra-news.\n");
        exit(1);
}

Logger::info('Готово.');
