<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use PDO;
use RuntimeException;

/**
 * current_ratings И rating_actions из Excel-выгрузки НРА (ra-national.ru).
 *
 * Найдено вживую (август 2026, см. docs/STAGE3_RATINGS.md): кнопка
 * "Выгрузить в Excel" на https://www.ra-national.ru/ratings/ ведёт на
 * /wp-load.php?security_key=...&action=get_data — URL с security_key
 * зашит прямо в HTML страницы списка, но ключ может поменяться (не
 * проверяли стабильность), поэтому не хардкодим его, а каждый раз
 * вытаскиваем свежую ссылку со страницы перед запросом.
 *
 * У НРА НЕТ отдельной страницы со списком пресс-релизов (в отличие от
 * НКР) — /news/ оказалась общей PR-лентой агентства (форумы, обновления
 * методологий), а не лентой рейтинговых действий по эмитентам (проверено
 * вживую, сентябрь 2026 — ни одного "НРА повысило/понизило/присвоило
 * рейтинг..." на всей первой странице). Настоящие пресс-релизы по
 * действиям живут на отдельных URL (ra-national.ru/press_release/<id>/),
 * и единственный способ их получить — тот же самый Excel-файл, который
 * УЖЕ содержит "Ссылка на пресс релиз" на каждую строку. Файл — НЕ
 * снимок "только текущее", а вся история с 2020 года ОДНИМ файлом,
 * каждый раз целиком (941 строка на момент проверки, растёт со временем;
 * отдельного "только новое" файла или окна по датам агентство не даёт).
 *
 * === Дедуп и частое расписание (решение пользователя, сентябрь 2026) ===
 *
 * Раньше (до этой правки) импортёр перезаписывал ВСЮ историю при каждом
 * прогоне — работало корректно (UNIQUE-ключ не даёт задублировать), но
 * пользователя справедливо смутила идея гонять это каждые 30 минут:
 * лишняя нагрузка на БД ради 1-2 реально новых строк. Решение —
 * переиспользовать RatingNewsLog/rating_news_log (миграция 016), тот же
 * механизм, что и у NkrNewsImporter, ключ — "Ссылка на пресс релиз":
 * строки со status='matched' в логе просто пропускаются в цикле, ни один
 * upsert в current_ratings/rating_actions для них не выполняется. Сам
 * Excel-файл всё равно скачивается целиком при каждом прогоне (агентство
 * не даёт разбить его на части) — это один дешёвый HTTP-запрос и разбор
 * в памяти PHP, не нагрузка на БД; нагрузку на БД устраняет именно
 * пропуск уже обработанных строк через лог.
 *
 * rating_from/outlook_from и current_ratings — та же логика, что у
 * NkrNewsImporter (см. CurrentRatingsSync): читаем кэш ДО записи строки,
 * обновляем его ПОСЛЕ (апсерт — только если строка не старше уже
 * сохранённого). Раньше это считалось иначе — "значения ПРЕДЫДУЩЕЙ по
 * дате строки ЭТОГО ЖЕ эмитента в этом же файле" (ручной проход по
 * отсортированной истории) — переход на общий кэш дал тот же результат
 * при первом полном прогоне, но не требует держать в памяти всю историю
 * эмитента и корректно продолжает работу на следующих (уже частичных)
 * прогонах. Обязательное условие корректности — строки обрабатываются в
 * ХРОНОЛОГИЧЕСКОМ порядке (см. import()).
 *
 * "Вид рейтинга" в файле смешивает кредитные рейтинги ЭМИТЕНТА с ESG,
 * рейтингами качества услуг (УК/ИК/НПФ/депозитариев/регистраторов) и
 * кредитным рейтингом ОТДЕЛЬНЫХ ВЫПУСКОВ ОБЛИГАЦИЙ (это про security_id,
 * не issuer_id — ни current_ratings, ни rating_actions такого не хранят).
 * И current_ratings, и rating_actions — про кредитоспособность эмитента
 * для оценки риска дефолта, поэтому оставляем только явные "Кредитный
 * рейтинг ... компаний/организаций". Строки не того вида НЕ логируются в
 * rating_news_log (в отличие от rating-строк) — это статичная, дешёвая
 * классификация по колонке файла, не требующая кэша от повторной
 * проверки на каждом прогоне, в отличие от дорогого сопоставления с
 * issuers.
 *
 * === Статус "на пересмотре" (CreditWatch, миграция 017) — из колонки, не текста ===
 *
 * В отличие от НКР (только свободный текст заголовка), у НРА статус
 * наблюдения дан СТРУКТУРИРОВАННО — отдельной колонкой "Под наблюдением"
 * (значения: "", "Под наблюдением", "Снято с наблюдения" — проверено на
 * живой выгрузке, 130/209/602 строк соответственно). Колонка "Прогноз"
 * иногда (не всегда!) ДУБЛИРУЕТ этот статус в тексте ("Стабильный - под
 * наблюдением") — RatingsNormalizer::stripWatchSuffix() отрезает такой
 * суффикс перед обычным mapOutlook(), а окончательное значение outlook
 * собирает combineWithWatchStatus(база, колонка "Под наблюдением") —
 * колонка "Под наблюдением" авторитетнее текста колонки "Прогноз" (по
 * ней проверялось на живых данных — 4 из 10 уникальных комбинаций дают
 * under_review_negative/under_review_positive именно через эту колонку,
 * при том что в тексте "Прогноз" словосочетание "Негативный - под
 * наблюдением"/"Позитивный - под наблюдением" ни разу не встретилось).
 */
final class NraImporter
{
    private const LISTING_URL = 'https://www.ra-national.ru/ratings/';
    private const AGENCY = 'nra';

    /**
     * Типы из колонки "Вид рейтинга", которые относятся к
     * кредитоспособности САМОГО ЭМИТЕНТА (не отдельного выпуска облигаций,
     * не ESG, не рейтинг качества услуг) — см. полный список найденных
     * значений в docs/STAGE3_RATINGS.md.
     */
    private const ISSUER_CREDIT_RATING_TYPES = [
        'Кредитный рейтинг нефинансовых компаний',
        'Кредитный рейтинг кредитных организаций',
        'Кредитный рейтинг лизинговых компаний',
        'Кредитный рейтинг страховых организаций',
        'Кредитный рейтинг инвестиционно-финансовых компаний',
    ];

    private int $totalRows = 0;
    private int $skippedWrongType = 0;
    private int $unmatchedNoInn = 0;
    private int $skippedNoDate = 0;
    private int $skippedAlreadyLogged = 0;

    private int $actionsWritten = 0;
    private int $actionsUnmatchedIssuer = 0;
    private int $actionsSameDayCollisions = 0;
    /** @var array<int, string> issuer_id => дата последнего обработанного в ЭТОМ прогоне действия (только для счётчика коллизий выше) */
    private array $lastActionDatePerIssuerThisRun = [];
    /** @var array<int, true> issuer_id => затронут (для отчёта "current_ratings обновлён для N эмитентов") */
    private array $currentRatingsIssuersTouched = [];

    /** @var array<int, string> */
    private array $unmatchedNames = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
    ) {
    }

    public function import(): void
    {
        $exportUrl = $this->discoverExportUrl();
        Logger::info("НРА: ссылка на выгрузку найдена: {$exportUrl}");

        $tmpFile = sys_get_temp_dir() . '/bondkeeper_nra_export_' . uniqid('', true) . '.xlsx';
        file_put_contents($tmpFile, RatingsHttp::get($exportUrl));

        try {
            $rows = XlsxReader::readFirstSheetAsRows($tmpFile);
        } finally {
            unlink($tmpFile);
        }

        Logger::info('НРА: строк в выгрузке (вся история, все виды рейтингов): ' . count($rows));

        /** @var array<int, array<string, string>> */
        $candidates = [];
        foreach ($rows as $row) {
            $this->totalRows++;
            if (!in_array($row['Вид рейтинга'] ?? '', self::ISSUER_CREDIT_RATING_TYPES, true)) {
                $this->skippedWrongType++;
                continue;
            }

            $inn = IssuerMatcher::normalizeInn($row['ИНН'] ?? '');
            $date = RatingsNormalizer::parseDate($row['Дата опубликования пресс-релиза'] ?? '');
            if ($inn === null) {
                $this->unmatchedNoInn++;
                continue;
            }
            if ($date === null) {
                $this->skippedNoDate++;
                continue;
            }

            $row['_inn'] = $inn;
            $row['_date'] = $date;
            $candidates[] = $row;
        }

        // Хронологический порядок (от старых к новым) — обязательное
        // условие корректности CurrentRatingsSync (см. докблок класса).
        // В отличие от НКР, порядок строк в самом файле НЕ гарантирован,
        // сортируем явно, а не полагаемся на порядок в источнике.
        usort($candidates, static fn (array $a, array $b) => $a['_date'] <=> $b['_date']);

        foreach ($candidates as $row) {
            $this->importRow($row);
        }

        $this->printReport();
    }

    /** @param array<string, string> $row строка выгрузки + '_inn'/'_date' */
    private function importRow(array $row): void
    {
        $url = trim($row['Ссылка на пресс релиз'] ?? '');

        if ($url !== '' && RatingNewsLog::isAlreadyMatched($this->db, self::AGENCY, $url)) {
            $this->skippedAlreadyLogged++;
            return;
        }

        $issuerId = $this->matcher->findIssuerIdByInn($row['_inn']);
        if ($issuerId === null) {
            $this->actionsUnmatchedIssuer++;
            $this->unmatchedNames[] = ($row['Название организации'] ?? '?') . " (ИНН={$row['_inn']})";
            if ($url !== '') {
                RatingNewsLog::log($this->db, self::AGENCY, $url, $row['_date'], 'skipped_unmatched');
            }
            return;
        }

        if (($this->lastActionDatePerIssuerThisRun[$issuerId] ?? null) === $row['_date']) {
            // Два действия одной датой для одного эмитента — при апсерте
            // по (issuer_id, agency, action_date) второе тихо перезапишет
            // первое (см. комментарий в миграции 014). Считаем, чтобы
            // это было видно в отчёте, а не терялось молча.
            $this->actionsSameDayCollisions++;
        }
        $this->lastActionDatePerIssuerThisRun[$issuerId] = $row['_date'];

        $ratingTo = mb_substr(trim($row['Рейтинг'] ?? ''), 0, 20);
        $baseOutlook = RatingsNormalizer::mapOutlook(
            RatingsNormalizer::stripWatchSuffix(trim($row['Прогноз'] ?? ''))
        );
        $outlookTo = RatingsNormalizer::combineWithWatchStatus($baseOutlook, trim($row['Под наблюдением'] ?? ''));

        $cached = CurrentRatingsSync::fetch($this->db, $issuerId, self::AGENCY);
        $ratingFrom = $cached['rating'];
        $outlookFrom = $cached['outlook'];

        $sourceTitle = mb_substr(trim($row['Название пресс-релиза'] ?? ''), 0, 500) ?: null;
        $sourceUrl = mb_substr($url, 0, 500) ?: null;

        $stmt = $this->db->prepare(
            'INSERT INTO rating_actions
                (issuer_id, agency, action_date, rating_from, rating_to, outlook_from, outlook_to, source_url, source_title)
             VALUES
                (:issuer_id, :agency, :action_date, :rating_from, :rating_to, :outlook_from, :outlook_to, :source_url, :source_title)
             ON DUPLICATE KEY UPDATE
                rating_from = VALUES(rating_from),
                rating_to = VALUES(rating_to),
                outlook_from = VALUES(outlook_from),
                outlook_to = VALUES(outlook_to),
                source_url = VALUES(source_url),
                source_title = VALUES(source_title)'
        );
        $stmt->execute([
            'issuer_id' => $issuerId,
            'agency' => self::AGENCY,
            'action_date' => $row['_date'],
            'rating_from' => $ratingFrom,
            'rating_to' => $ratingTo,
            'outlook_from' => $outlookFrom,
            'outlook_to' => $outlookTo,
            'source_url' => $sourceUrl,
            'source_title' => $sourceTitle,
        ]);
        $this->actionsWritten++;

        CurrentRatingsSync::sync($this->db, $issuerId, self::AGENCY, $row['_date'], $ratingTo, $outlookTo, $cached);
        $this->currentRatingsIssuersTouched[$issuerId] = true;

        if ($url !== '') {
            RatingNewsLog::log($this->db, self::AGENCY, $url, $row['_date'], 'matched');
        }
    }

    private function discoverExportUrl(): string
    {
        $html = RatingsHttp::get(self::LISTING_URL);
        if (!preg_match('/href="(https:\/\/www\.ra-national\.ru\/wp-load\.php\?[^"]*action=get_data[^"]*)"/', $html, $m)) {
            throw new RuntimeException('НРА: на странице ' . self::LISTING_URL . ' не нашлась ссылка на Excel-выгрузку — вёрстка страницы могла измениться, см. bin/debug_rating_page.php');
        }

        return html_entity_decode($m[1]);
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту current_ratings + rating_actions (НРА) ===');
        Logger::info("Строк в выгрузке обработано: {$this->totalRows}");
        Logger::info("  - из них не про кредитный рейтинг эмитента (ESG/услуги/отдельный выпуск облигаций): {$this->skippedWrongType}");
        Logger::info("  - без валидного ИНН: {$this->unmatchedNoInn}");
        Logger::info("  - без распознанной даты: {$this->skippedNoDate}");
        Logger::info("Уже были окончательно обработаны раньше (status=matched в rating_news_log): {$this->skippedAlreadyLogged}");
        Logger::info("rating_actions строк записано в этом прогоне: {$this->actionsWritten}");
        Logger::info("current_ratings затронуто эмитентов в этом прогоне: " . count($this->currentRatingsIssuersTouched));
        Logger::info("Пропущено из-за несопоставленного эмитента (попробуем снова на следующем прогоне): {$this->actionsUnmatchedIssuer}");
        Logger::info("Совпадений по дате внутри одного эмитента в этом прогоне (вторая запись тихо перезаписала первую): {$this->actionsSameDayCollisions}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
