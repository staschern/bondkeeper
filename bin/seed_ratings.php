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
 *   php bin/seed_ratings.php --agency=nkr-news [--days=2] [--full]
 *   php bin/seed_ratings.php --agency=expert_ra-news [--delay-ms=400] [--full]
 *
 * *-news — парсинг пресс-релизов, не текущих рейтингов. НКР (--agency=
 * nkr-news) — по решению пользователя переведён на автоматическое
 * расписание каждые 30 минут (см. bin/daemon_nkr_news.php, если на сервере
 * нет доступа к обычному OS cron): --days=N — сколько последних
 * календарных дней рассматривать (по умолчанию 2 — для частого прогона;
 * 14 — для более редкого "глубокого", ловит эмитентов, добавленных в
 * issuers с опозданием). Дедуп — не по дате, а по URL пресс-релиза
 * (rating_news_log, миграция 016): пресс-релиз, успешно записанный,
 * повторно не трогается никогда; пресс-релиз, который не удалось
 * сопоставить с эмитентом, пробуется заново на каждом прогоне в пределах
 * окна — сам подхватится, если эмитента потом добавили в issuers, без
 * ручного --full. --full игнорирует окно полностью (вся история с 2019
 * года) — теперь редкая ручная операция, не часть расписания.
 * Подробности — в докблоке NkrNewsImporter и docs/STAGE3_RATINGS.md.
 *
 * Эксперт РА (--agency=expert_ra-news [--days=2] [--delay-ms=400] [--full])
 * — по решению пользователя (сентябрь 2026) ТОЖЕ переведён на общую
 * механику: дедуп по URL пресс-релиза (rating_news_log), current_ratings
 * через CurrentRatingsSync, статус "под наблюдением" (своя терминология,
 * не "на пересмотре" у НКР — см. докблок ExpertRaNewsImporter), защита от
 * нестандартных шкал (".sf" — реально встречается, в отличие от НКР).
 * Сопоставление — ПЕРВИЧНО по ИНН со страницы пресс-релиза (нашлось
 * вживую в сентябре 2026 — оказалось, что оно там всё-таки есть, просто
 * не в самой ленте новостей; см. ExpertRaClient::fetchReleaseInn()),
 * запасным путём — по названию в кавычках, как раньше. Из-за этого
 * доп. запроса на КАЖДУЮ строку (как у НКР) — то же деление на частый/
 * глубокий проход: по умолчанию --days=2 (частый), --days=14 для более
 * редкого "глубокого".
 *
 * НРА (--agency=nra) — по решению пользователя (сентябрь 2026) ТОЖЕ можно
 * гонять каждые 30 минут: у агентства нет отдельной ленты пресс-релизов
 * (/news/ — общая PR-лента, не рейтинговые действия, проверено вживую),
 * зато Excel-выгрузка (та же, что и раньше) отдаёт всю историю целиком
 * при каждом запросе — свежие действия попадают в неё практически сразу
 * (проверено: в момент разведки самая свежая строка была от сегодняшнего
 * дня). Дедуп — тот же RatingNewsLog/rating_news_log, что и у НКР, ключ —
 * "Ссылка на пресс релиз" — уже обработанные строки не трогают БД
 * повторно, только разбираются в памяти PHP (дёшево). Подробности — в
 * докблоке NraImporter.
 *
 * АКРА для новостей не реализовано: тот же WAF, что и для current_ratings
 * (см. ниже).
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
 * expert_ra (текущие рейтинги, ПОЛНЫЙ обход сайта — не путать с
 * expert_ra-news) поставлен пораньше и с запасом (около часа на сам
 * прогон), чтобы не пересекаться с check_fns_blocks.php в 8:00:
 *   0 6 * * * /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=expert_ra >> /var/log/bondkeeper/seed_ratings_expert_ra.log 2>&1
 *
 * nkr-news, nra, expert_ra-news — ЕСЛИ на сервере есть обычный OS cron:
 *   * /30 * * * *  /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nkr-news --days=2       >> /var/log/bondkeeper/seed_ratings_nkr_news.log 2>&1
 *   50 6 * * *     /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nkr-news --days=14      >> /var/log/bondkeeper/seed_ratings_nkr_news_deep.log 2>&1
 *   * /30 * * * *  /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=nra                     >> /var/log/bondkeeper/seed_ratings_nra.log 2>&1
 *   * /30 * * * *  /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=expert_ra-news --days=2  >> /var/log/bondkeeper/seed_ratings_expert_ra_news.log 2>&1
 *   55 6 * * *     /usr/bin/php /path/to/bondkeeper/bin/seed_ratings.php --agency=expert_ra-news --days=14 >> /var/log/bondkeeper/seed_ratings_expert_ra_news_deep.log 2>&1
 * (у nra нет отдельного "глубокого" прогона — она не делает запрос на
 * КАЖДУЮ строку, дедуп по rating_news_log сам решает, что уже обработано,
 * узкое "частое" окно тут не даёт экономии. У expert_ra-news такой запрос
 * теперь есть — тот же случай, что и у nkr-news).
 *
 * ЕСЛИ доступа к OS cron нет (или он не гарантирован) — вместо этих
 * crontab-строк самостоятельные процессы с циклом: bin/daemon_nkr_news.php
 * и bin/daemon_expert_ra_news.php (оба чередуют частый/глубокий проход),
 * bin/daemon_nra.php (простой цикл каждые 30 минут, без деления).
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
$days = null; // null => используем дефолт конкретного импортёра (разный для nkr-news/expert_ra-news)
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
    if (str_starts_with($arg, '--days=')) {
        $days = (int) substr($arg, strlen('--days='));
    }
    if ($arg === '--full') {
        $full = true;
    }
}

if ($agency === null) {
    fwrite(STDERR, "Использование: php bin/seed_ratings.php --agency=nkr|nra|expert_ra|acra|manual|nkr-news|expert_ra-news [--delay-ms=400] [--file=...] [--full] [--days=2]\n");
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
        (new NkrNewsImporter($db, $matcher, new RatingActionsWriter($db)))->import($full, $days ?? 2);
        break;
    case 'expert_ra-news':
        (new ExpertRaNewsImporter($db, $matcher, new RatingActionsWriter($db), new ExpertRaClient(), $delayMs * 1000))->import($full, $days ?? 2);
        break;
    default:
        fwrite(STDERR, "Неизвестное агентство: {$agency}. Поддерживаются: nkr, nra, expert_ra, acra, manual, nkr-news, expert_ra-news.\n");
        exit(1);
}

Logger::info('Готово.');
