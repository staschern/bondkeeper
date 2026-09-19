<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use DOMDocument;
use DOMXPath;
use PDO;

/**
 * rating_actions из истории пресс-релизов НКР
 * (https://ratings.ru/ratings/press-releases/) — обычная HTML-таблица,
 * вся история одним запросом (1040+ строк на момент проверки, назад до
 * конца 2019 года, без пагинации), см. docs/STAGE3_RATINGS.md.
 *
 * Разбор ТЕКСТА заголовка (глагол, грейды, прогноз, названия в кавычках,
 * защита от нестандартных шкал) — в NkrTitleParser (чистые статические
 * методы без БД/сети, юнит-тестируемые отдельно). Этот класс отвечает
 * за то, что NkrTitleParser сознательно не делает: HTTP, сопоставление
 * с issuers.id и запись в rating_actions/current_ratings.
 *
 * Чтение/обновление кэша current_ratings (CurrentRatingsSync) и журнал
 * просмотренных пресс-релизов (RatingNewsLog) вынесены в отдельные общие
 * классы — ровно та же логика 1:1 понадобилась NraImporter (сентябрь
 * 2026), дублировать её приватными методами в каждом news-импортёре не
 * стали.
 *
 * ИНН здесь НЕ берётся из заголовка (там только название, часто в
 * падеже — "Трансстройбанка", "Селигдара" — сопоставлять по такому
 * названию ненадёжно). Вместо этого — по одному запросу на страницу
 * конкретного пресс-релиза, где ИНН даётся прямым текстом в блоке
 * "Регуляторное раскрытие".
 *
 * === Автоматическое расписание (решение пользователя, сентябрь 2026) ===
 *
 * Раньше инкрементальность держалась на MAX(action_date) уже записанных
 * действий — годилось для редких ручных прогонов, но при переходе на
 * автоматический прогон каждые 30 минут два новых требования:
 *   1. Дедуп по URL пресс-релиза (не по action_date) — см. RatingNewsLog/
 *      rating_news_log (миграция 016). MAX(action_date)-подход не мог
 *      отличить "уже успешно записан" от "уже пытались, но эмитент не
 *      сопоставился" — второе тихо пропускалось навсегда без ручного
 *      --full. Теперь непойманные пресс-релизы (любой статус кроме
 *      'matched' в rating_news_log) пробуются заново на каждом прогоне.
 *   2. Окно по датам, а не "от начала времён": $days — сколько последних
 *      календарных дней пресс-релизов рассматривать. По решению
 *      пользователя — 2 дня для частого (каждые 30 минут) прогона и
 *      14 дней для более редкого "глубокого" прогона (ловит эмитентов,
 *      добавленных в issuers с опозданием). $full=true игнорирует окно
 *      полностью — вся история, редкая ручная операция.
 *
 * bin/daemon_nkr_news.php — самостоятельный процесс с циклом (на случай,
 * если на сервере нет доступа к обычному OS cron): каждые 30 минут
 * $days=2, раз в сутки $days=14.
 *
 * === current_ratings — обновляется здесь же (решение пользователя) ===
 *
 * По исходному замыслу схемы (см. комментарий в 001_schema.sql) —
 * current_ratings обновляется ПРИ КАЖДОЙ новой записи в rating_actions,
 * а не только отдельным ежедневным NkrImporter (полная выгрузка "текущих
 * рейтингов"). Реализовано через CurrentRatingsSync — строки в пределах
 * одного прогона обрабатываются в ХРОНОЛОГИЧЕСКОМ порядке (список с сайта
 * отдаётся по убыванию даты, поэтому кандидаты собираются, а потом
 * проходятся в обратном порядке), апсерт в кэш — только если действие не
 * старше уже сохранённого там (защита при пакетной обработке смеси старых
 * пропущенных и свежих действий).
 *
 * Это же упростило outlook_from/rating_from: раз current_ratings на
 * момент обработки каждой строки гарантированно актуален, достаточно
 * прочитать его ДО записи новой строки:
 *   - outlook_from = current_ratings.outlook на тот момент.
 *   - rating_from = то, что явно дано в заголовке ("с X до Y"), а если
 *     не дано — тоже current_ratings.rating (честнее, чем просто NULL —
 *     рейтинг подтверждён на том же уровне). Действует и для отзыва —
 *     rating_from покажет последний реальный грейд перед отзывом.
 *
 * === Глагол "изменило" и статус "на пересмотре" (решение пользователя) ===
 *
 * "изменило" добавлен в NkrTitleParser::VERBS ради заголовков вида "НКР
 * изменило прогноз по кредитному рейтингу ООО «Х» на «рейтинг на
 * пересмотре с возможностью понижения»" — сам грейд там не называется
 * вообще. Для этого глагола отсутствие грейда в заголовке — НЕ ошибка
 * разбора (в отличие от остальных 5 глаголов): rating_to берётся из
 * current_ratings.rating (уровень не меняется, меняется только статус).
 * Если и в кэше пусто (первое вообще известное действие по эмитенту) —
 * честно нечего писать в NOT NULL rating_to, такой issuer пропускается
 * (см. importRow()).
 *
 * Статус "на пересмотре" (CreditWatch/Rating Watch, миграция 017) —
 * RatingsNormalizer::extractReviewStatusFromProse() проверяется ПЕРЕД
 * обычным mapOutlookFromProse() — своя, более специфичная категория
 * outlook (under_review/under_review_negative/under_review_positive), не
 * обычные 4 направления прогноза.
 *
 * === Составные действия (компания-поручитель + её SPV) ===
 *
 * НКР в заголовке часто явно называет и операционную компанию, и её
 * SPV/облигации в одном действии ("НКР подтвердило кредитные рейтинги
 * ООО «ПК Борец» и облигаций ООО «Борец Капитал» на уровне A-.ru») —
 * первичное лицо сопоставляется по ИНН со страницы пресс-релиза
 * (надёжно), остальные названия в кавычках заголовка — запасным путём,
 * по точному совпадению имени (IssuerMatcher::findIssuerIdByName).
 * Строка в rating_actions пишется на КАЖДЫЙ различный сопоставившийся
 * issuer_id, для которого нашёлся rating_to (см. выше про "изменило") —
 * оба есть в БД → 2 строки с одинаковым rating_to/outlook_to; только
 * один (любой) → 1 строка; ни одного (или ни для одного не нашёлся
 * rating_to) → в rating_news_log со статусом skipped_unmatched/
 * skipped_no_grade (см. resolveIssuerIds()).
 *
 * === Третий уровень — "по корню" названия, для SPV (миграция 022) ===
 *
 * Составные действия выше решают только случай "агентство САМО назвало
 * обе стороны в одном заголовке". По прямому запросу пользователя
 * (сентябрь 2026) добавлен более общий случай: в issuers заведена только
 * ОДНА сторона (например, только SPV «Х Финанс»), а заголовок называет
 * ТОЛЬКО ДРУГУЮ («Х») — ни ИНН, ни точное имя не находят такую строку.
 * Если ни один candidate не сопоставился выше вообще — пробуем явную
 * ручную связку issuer_spv_links (миграция 023), а если и она не
 * подошла — IssuerMatcher::findIssuerIdByRootName() для каждого названия
 * в кавычках. Строка пишется как обычно, но с флагом matched_by_root_name
 * — самый неточный из уровней, отдельно собирается в уведомление
 * администратору (getRootMatchNotices(), см. bin/seed_ratings.php).
 */
final class NkrNewsImporter
{
    private const AGENCY = 'nkr';
    private const LIST_URL = 'https://ratings.ru/ratings/press-releases/';

    private int $totalCandidates = 0;
    private int $skippedAlreadyLogged = 0;
    private int $skippedNotRatingAction = 0;
    private int $skippedBondRedemption = 0;
    private int $skippedNonStandardRating = 0;
    private int $skippedNoRatingParsed = 0;
    /** Штук пресс-релизов, по которым записана хотя бы одна строка rating_actions. */
    private int $matchedActions = 0;
    /** Штук строк rating_actions — больше matchedActions, если были составные действия. */
    private int $matchedRows = 0;
    /** Штук составных действий (2+ разных issuer_id сопоставилось на одно действие). */
    private int $compositeActions = 0;
    private int $skippedNoIssuerResolved = 0;
    /** Штук действий, сопоставленных ТОЛЬКО третьим, самым неточным уровнем (см. resolveIssuerIds()). */
    private int $matchedByRoot = 0;
    /** @var array<int, true> issuer_id => сопоставлен по корню в ТЕКУЩЕМ вызове resolveIssuerIds() */
    private array $lastRootMatchedIds = [];
    /** @var array<int, string> заголовки для уведомления администратору о root-совпадениях (см. bin/seed_ratings.php) */
    private array $rootMatchNotices = [];
    /** @var array<int, string> */
    private array $unmatchedTitles = [];
    /** @var array<int, string> */
    private array $unparsedTitles = [];
    /** @var array<int, string> */
    private array $nonStandardTitles = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
        private readonly RatingActionsWriter $writer,
    ) {
    }

    /**
     * $days — сколько последних календарных дней пресс-релизов (по дате
     * из списка) рассматривать; $full=true игнорирует $days полностью и
     * разбирает всю историю (редкая ручная операция, не для расписания).
     */
    public function import(bool $full = false, int $days = 2): void
    {
        $cutoffDate = $full ? null : date('Y-m-d', strtotime("-{$days} days"));
        Logger::info('НКР (новости): окно — ' . ($full ? 'вся история (--full)' : "последние {$days} дн. (с {$cutoffDate})"));

        $html = RatingsHttp::get(self::LIST_URL, 60);
        $rows = $this->parseListRows($html);
        Logger::info('НКР (новости): строк в списке пресс-релизов: ' . count($rows));

        // Список отсортирован по убыванию даты — собираем кандидатов в
        // пределах окна, потом проходим их в обратном порядке
        // (хронологически, от старых к новым), см. докблок класса про
        // current_ratings.
        $candidates = [];
        foreach ($rows as $row) {
            if ($cutoffDate !== null && $row['date'] < $cutoffDate) {
                break;
            }
            $candidates[] = $row;
        }
        $this->totalCandidates = count($candidates);

        foreach (array_reverse($candidates) as $row) {
            $this->importRow($row);
        }

        $this->printReport();
    }

    /** @return array<int, array{title: string, url: string, date: string}> */
    private function parseListRows(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $rows = [];
        foreach ($xpath->query('//table//tr[td]') as $tr) {
            $cells = $xpath->query('./td', $tr);
            if ($cells->length < 3) {
                continue;
            }

            $links = $xpath->query('.//a', $cells->item(0));
            if ($links->length === 0) {
                continue;
            }
            $link = $links->item(0);
            $title = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');
            $href = $link->getAttribute('href');

            $date = RatingsNormalizer::parseDate(trim($cells->item(2)->textContent ?? ''));
            if ($title === '' || $href === '' || $date === null) {
                continue;
            }

            $rows[] = [
                'title' => $title,
                'url' => 'https://ratings.ru' . $href,
                'date' => $date,
            ];
        }

        return $rows;
    }

    /** @param array{title: string, url: string, date: string} $row */
    private function importRow(array $row): void
    {
        if (RatingNewsLog::isAlreadyMatched($this->db, self::AGENCY, $row['url'])) {
            $this->skippedAlreadyLogged++;
            return;
        }

        $verb = NkrTitleParser::matchVerb($row['title']);
        if ($verb === null || !NkrTitleParser::isCreditRatingAction($row['title'])) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_not_rating');
            $this->skippedNotRatingAction++;
            return;
        }

        // Отзыв рейтинга КОНКРЕТНОГО ВЫПУСКА облигаций из-за его
        // погашения — технический шум, не отзыв рейтинга эмитента (см.
        // докблок RatingsNormalizer::isBondIssueRedemptionWithdrawal() —
        // NkrTitleParser уже предвидел этот случай в своём докблоке
        // ("в связи с его погашением"), но раньше не обрабатывал).
        if (str_starts_with($verb, 'отозвал') && RatingsNormalizer::isBondIssueRedemptionWithdrawal($row['title'])) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_bond_redemption');
            $this->skippedBondRedemption++;
            return;
        }

        if (RatingsNormalizer::isNonStandardRating($row['title'])) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_non_standard');
            $this->skippedNonStandardRating++;
            $this->nonStandardTitles[] = $row['title'];
            return;
        }

        [$ratingFromParsed, $ratingToParsed] = NkrTitleParser::extractRatingChange($row['title'], $verb);
        if ($ratingToParsed === null && $verb !== 'изменило') {
            // Для остальных 5 глаголов отсутствие грейда — реальная
            // неудача разбора (см. NkrTitleParser::VERBS). Для "изменило"
            // это ожидаемо (см. докблок класса) — не выходим отсюда,
            // rating_to попробуем взять из current_ratings ниже, per issuer.
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_no_grade');
            $this->skippedNoRatingParsed++;
            $this->unparsedTitles[] = $row['title'];
            return;
        }

        $outlookTo = RatingsNormalizer::extractReviewStatusFromProse($row['title'])
            ?? RatingsNormalizer::mapOutlookFromProse($row['title']);

        $inn = $this->fetchInnFromDetailPage($row['url']);
        $issuerIds = $this->resolveIssuerIds($inn, $row['title']);

        if ($issuerIds === []) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_unmatched');
            $this->skippedNoIssuerResolved++;
            $suffix = $inn !== null ? " (ИНН={$inn}, не найден в issuers)" : ' (ИНН на странице не найден)';
            $this->unmatchedTitles[] = $row['title'] . $suffix;
            return;
        }

        $rootMatchedIds = $this->lastRootMatchedIds;
        $writtenCount = 0;
        foreach ($issuerIds as $issuerId) {
            $cached = CurrentRatingsSync::fetch($this->db, $issuerId, self::AGENCY);
            $ratingFrom = $ratingFromParsed ?? $cached['rating'];
            $ratingTo = $ratingToParsed ?? $cached['rating'];
            if ($ratingTo === null) {
                // verb='изменило', заголовок не дал грейд, и в кэше пусто
                // (первое вообще известное действие по этому эмитенту) —
                // честно нечего писать в NOT NULL rating_to, пропускаем
                // именно этого issuer'а (не всё действие целиком — при
                // составном действии другое лицо могло сопоставиться успешно).
                continue;
            }

            $matchedByRoot = isset($rootMatchedIds[$issuerId]);
            $this->writer->upsert(
                $issuerId,
                self::AGENCY,
                $row['date'],
                $ratingFrom,
                $ratingTo,
                $cached['outlook'],
                $outlookTo,
                $row['url'],
                $row['title'],
                $matchedByRoot,
            );
            CurrentRatingsSync::sync($this->db, $issuerId, self::AGENCY, $row['date'], $ratingTo, $outlookTo, $cached, $matchedByRoot);
            if ($matchedByRoot) {
                $this->matchedByRoot++;
                $this->rootMatchNotices[] = "НКР: «{$row['title']}» → issuer_id={$issuerId} (сопоставлено по корню названия, проверьте) — {$row['url']}";
            }
            $writtenCount++;
        }

        if ($writtenCount === 0) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_no_grade');
            $this->skippedNoRatingParsed++;
            $this->unparsedTitles[] = $row['title'] . ' (глагол "изменило" без грейда и без известного current_ratings)';
            return;
        }

        $this->matchedRows += $writtenCount;
        $this->matchedActions++;
        if ($writtenCount > 1) {
            $this->compositeActions++;
        }
        RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'matched');
    }

    /**
     * Первичное лицо — по ИНН со страницы пресс-релиза (надёжно). Любые
     * другие названия в кавычках заголовка — запасным путём, по точному
     * совпадению имени (см. IssuerMatcher::findIssuerIdByName). Если
     * название в кавычках резолвится в тот же issuer_id, что и первичное
     * лицо — array_unique просто уберёт дубль, вторая строка на того же
     * issuer_id не пишется.
     *
     * @return array<int, int> уникальные issuer_id, первичный (по ИНН) первым, если сопоставился
     */
    private function resolveIssuerIds(?string $inn, string $title): array
    {
        $issuerIds = [];
        $this->lastRootMatchedIds = [];

        $primaryId = $inn !== null ? $this->matcher->findIssuerIdByInn($inn) : null;
        if ($primaryId === null && $inn !== null) {
            // Явная ручная связка "неизвестный ИНН → issuer_id" (миграция
            // 023, см. IssuerMatcher::findIssuerIdBySpvLink() за подробным
            // объяснением и важной оговоркой про направление) — для
            // случаев вроде «ЕВА»/«ФСК Активы», где имена НЕ ИМЕЮТ НИЧЕГО
            // ОБЩЕГО (не вариация одного названия, а разные бренды) и
            // "по корню" их связать нельзя в принципе. Реальный сценарий,
            // из-за которого это понадобилось: НКР публикует новость о
            // присвоении рейтинга облигациям, где "Информация о
            // рейтингуемом лице" на детальной странице называет ОДНУ
            // сторону пары (например, ООО «ЕВА» — структурно поручитель,
            // но именно её ИНН в issuers нет), а другая сторона (ООО «ФСК
            // Активы» — та, что физически в issuers, её облигации на
            // бирже) упоминается в заголовке только в связи с выпуском —
            // ни составное действие, ни корень здесь не сработали бы.
            $primaryId = $this->matcher->findIssuerIdBySpvLink($inn);
        }
        if ($primaryId !== null) {
            $issuerIds[] = $primaryId;
        }

        $candidates = RatingsNormalizer::extractQuotedNames($title);

        foreach ($candidates as $candidate) {
            $id = $this->matcher->findIssuerIdByName($candidate);
            if ($id !== null) {
                $issuerIds[] = $id;
            }
        }

        // Третий, самый неточный уровень — по "корню" названия (без ОПФ
        // и маркерных слов SPV "Финанс"/"Капитал", см. IssuerMatcher::
        // rootCompanyName()) — только когда ИНН и точное имя выше не дали
        // НИЧЕГО (по прямому указанию пользователя, сентябрь 2026): кейс
        // "в issuers заведена только SPV/только материнская компания, а
        // заголовок называет другую сторону".
        if ($issuerIds === []) {
            foreach ($candidates as $candidate) {
                $id = $this->matcher->findIssuerIdByRootName($candidate);
                if ($id !== null) {
                    $issuerIds[] = $id;
                    $this->lastRootMatchedIds[$id] = true;
                }
            }
        }

        return array_values(array_unique($issuerIds));
    }

    /** @return array<int, string> тексты для админ-уведомления о совпадениях "по корню" в этом прогоне */
    public function getRootMatchNotices(): array
    {
        return $this->rootMatchNotices;
    }

    private function fetchInnFromDetailPage(string $url): ?string
    {
        $html = RatingsHttp::get($url, 60);

        return NkrTitleParser::extractInnFromDetailHtml($html);
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту rating_actions (НКР, новости) ===');
        Logger::info("Кандидатов в окне: {$this->totalCandidates}");
        Logger::info("Уже были окончательно обработаны раньше (status=matched в rating_news_log): {$this->skippedAlreadyLogged}");
        Logger::info("Пропущено (не похоже на кредитное рейтинговое действие): {$this->skippedNotRatingAction}");
        Logger::info("Пропущено (отзыв рейтинга выпуска облигаций из-за погашения — шум, не эмитентское действие): {$this->skippedBondRedemption}");
        Logger::info("Пропущено (нестандартная шкала, напр. 'sf'): {$this->skippedNonStandardRating}");
        Logger::info("Пропущено (не удалось разобрать уровень рейтинга и нет данных в current_ratings): {$this->skippedNoRatingParsed}");
        Logger::info("Действий записано (matchedActions): {$this->matchedActions}");
        Logger::info("Строк rating_actions записано (matchedRows): {$this->matchedRows}");
        Logger::info("Из них составных действий (2+ юрлица сопоставились): {$this->compositeActions}");
        Logger::info("Из них сопоставлено третьим уровнем, 'по корню' названия (SPV/материнская компания, требует выборочной проверки): {$this->matchedByRoot}");
        Logger::info("Не сопоставлено ни с одним issuer_id (попробуем снова на следующем прогоне): {$this->skippedNoIssuerResolved}");
        if ($this->unmatchedTitles !== []) {
            Logger::info('Не сопоставленные: ' . implode('; ', array_slice($this->unmatchedTitles, 0, 20)));
        }
        if ($this->unparsedTitles !== []) {
            Logger::info('Не разобранные заголовки: ' . implode('; ', array_slice($this->unparsedTitles, 0, 20)));
        }
        if ($this->nonStandardTitles !== []) {
            Logger::info('Нестандартная шкала (пропущены целиком): ' . implode('; ', array_slice($this->nonStandardTitles, 0, 20)));
        }
    }
}
