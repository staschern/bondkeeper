<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use DOMDocument;
use DOMXPath;
use PDO;
use RuntimeException;

/**
 * rating_actions из ленты пресс-релизов АКРА
 * (https://www.acra-ratings.ru/press-releases/), по образцу
 * NkrNewsImporter/ExpertRaNewsImporter/NraImporter — тот же
 * `rating_news_log`-дедуп, тот же `CurrentRatingsSync` для
 * rating_from/outlook_from и обновления кэша, то же скользящее окно
 * `--days`. Разбор текста заголовков — в AcraNewsTitleParser (чистые
 * статические методы, проверены на 10 реальных заголовках + 11 живых
 * детальных страницах, см. docs/STAGE3_RATINGS.md).
 *
 * === Раньше это было закрыто, сентябрь 2026 — переоткрыто ===
 *
 * Автоматический опрос сайта АКРА для current_ratings/новостей
 * (`/ratings/issuers/`, старая попытка на `/press-releases/`) раньше
 * упирался в SPA-заглушку без данных (WAF + Yandex SmartCaptcha, см.
 * разделы «АКРА — закрыта»/«АКРА — переоткрыта» выше). Живая проверка
 * (пользователь, сентябрь 2026, `bin/debug_acra_news.php`) показала:
 * ИМЕННО для раздела `/press-releases/` обычный HTTP-запрос без
 * cookie-сессии/JS/капчи ОТДАЁТ реальные данные — и список, и все 10
 * детальных страниц одного списка подряд (с вежливой паузой между
 * запросами, не имитация браузера) прошли без единого сбоя/капчи.
 * Похоже, WAF реагирует не на этот раздел или не на такой объём
 * запросов — открытый вопрос закрыт для практических целей, не строгое
 * доказательство "навсегда" (если ситуация изменится — увидим в логах
 * cron-прогонов, тот же принцип, что и у остальных источников).
 *
 * === Лента листается дальше первой страницы (03.10.2026, сверка АКРА) ===
 * Первая страница — 10 карточек, это около двух дней. Сверка 03.10.2026
 * показала пропуск: понижение ООО «ПКФ» до D(RU) от 23.09.2026
 * (/press-releases/7329/) не попало в базу — между успешными прогонами
 * прошло больше суток (записи создавались 22.09 09:30, 23.09 12:30, затем
 * только 28.09), и карточка ушла с первой страницы. Постраничная навигация
 * у ленты есть: /press-releases/?PAGEN_1=N (проверено вживую, 6 страниц
 * подряд с паузой отдаются без капчи). Теперь:
 *   - окно не короче MIN_WINDOW_DAYS дней, что бы ни стояло в --days
 *     (в crontab --days=2): окно не экономит запросов — уже записанные
 *     новости пропускаются без обращения к сайту;
 *   - следующая страница запрашивается, только если на текущей есть
 *     карточки в окне, которых ещё нет в rating_news_log; обычный прогон
 *     — по-прежнему один запрос списка, после простоя — столько страниц,
 *     сколько пропущено, но не больше MAX_PAGES;
 *   - не открылась вторая и дальше страница — обрабатывается то, что
 *     уже получено.
 * Новости про ипотечные ценные бумаги и шкалу структурированного
 * финансирования не пишутся (RatingsNormalizer::isNonStandardRating(),
 * статус skipped_non_standard) — как у НКР и Эксперт РА.
 * === ИНН — со страницы конкретного пресс-релиза, как у НКР ===
 *
 * Список отдаёт только заголовок/дату/ссылку — ИНН только на детальной
 * странице, в таблице "Регуляторное раскрытие", строка
 * "Идентификационный номер налогоплательщика рейтингуемого лица" (без
 * скобок "(ИНН)", формулировка АКРА немного другая, чем у НКР — общий
 * regex сюда не подошёл бы буквально, тот же урок, что уже был у
 * Эксперт РА). Один доп. запрос на КАЖДУЮ строку, уже прошедшую
 * классификацию (не на все подряд — уже сопоставленные по
 * `rating_news_log` пропускаются раньше, без единого лишнего запроса).
 *
 * === Три пути сопоставления: ИНН → ISIN → название в кавычках ===
 *
 * ПЕРВИЧНО — ИНН со страницы релиза. ЗАПАСНОЙ путь 1 — ISIN выпуска
 * прямо в заголовке (см. AcraNewsTitleParser::extractIsin(),
 * IssuerMatcher::findIssuerIdByIsin()) — реальный найденный случай:
 * облигации субъекта РФ ("...ОБЛИГАЦИЙ РОСТОВСКОЙ ОБЛАСТИ
 * (RU000A10FZZ1)...") не дают ИНН на детальной странице (поле для
 * муниципалитета пустое) И не дают названия в кавычках (регион не
 * цитируется) — без ISIN такая строка осталась бы полностью
 * несопоставленной, как у Эксперт РА с регионами. ЗАПАСНОЙ путь 2 —
 * название в кавычках, если ни ИНН, ни ISIN не подошли (с миграции 024
 * — только уже подтверждённое администратором, см. ниже).
 *
 * === Составные действия — НЕ реализованы, в отличие от НКР ===
 *
 * На всех 10 реальных заголовках составное действие (компания +
 * отдельно её облигации, например ООО «Альфа-Лизинг» + "выпуска его
 * облигаций") называет ОДНУ И ТУ ЖЕ компанию дважды, не второе
 * юридическое лицо (SPV), как бывает у НКР — резолвим только ПЕРВОЕ
 * найденное сопоставление, второе упоминание того же эмитента не даёт
 * новой информации. Если когда-нибудь встретится реальный случай
 * "компания + отдельная SPV" — по аналогии с NkrNewsImporter::
 * resolveIssuerIds(), но без реального примера сейчас так не делаем.
 *
 * === Сопоставление по названию — только после подтверждения (миграция 024) ===
 *
 * resolveIssuer(): ИНН → явная связка issuer_spv_links (миграция 023) →
 * ISIN → NameMatchResolver. Совпадение по названию (точное имя или "по
 * корню") без подтверждения администратора в базу не пишется — это
 * предложение с заголовком и ссылкой на пресс-релиз (решение
 * пользователя, сентябрь 2026, см. докблок NameMatchReviews).
 */
final class AcraNewsImporter
{
    private const AGENCY = 'acra';
    private const BASE_URL = 'https://www.acra-ratings.ru';
    private const LIST_URL = self::BASE_URL . '/press-releases/';
    /** Сколько страниц ленты читать самое большее: 8 × 10 карточек — около двух недель. */
    private const MAX_PAGES = 8;
    /** Окно не короче этого числа дней — см. докблок класса. */
    private const MIN_WINDOW_DAYS = 14;

    private int $totalCandidates = 0;
    private int $skippedAlreadyLogged = 0;
    private int $skippedNotRatingAction = 0;
    /** @var array<string, int> статус rating_news_log => сколько новостей про облигации пропущено (RatingsNormalizer::bondNewsSkipStatus()) */
    private array $skippedBondNews = [];
    private int $skippedNonStandard = 0;
    private int $skippedNoRatingParsed = 0;
    private int $pagesFetched = 0;
    private int $matched = 0;
    private int $matchedByInn = 0;
    private int $matchedByIsin = 0;
    private int $matchedBySpvLink = 0;
    private int $matchedByApprovedName = 0;
    /** Новых предложений сопоставления по названию (ждут подтверждения администратора). */
    private int $proposedByName = 0;
    private int $skippedNoIssuerResolved = 0;
    /** @var array<int, string> */
    private array $unmatchedTitles = [];
    /** @var array<int, string> */
    private array $unparsedTitles = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
        private readonly RatingActionsWriter $writer,
        private readonly NameMatchResolver $nameResolver,
        private readonly int $delayMicroseconds = 2_000_000,
    ) {
    }

    /**
     * $days — сколько последних календарных дней рассматривать, но не
     * меньше MIN_WINDOW_DAYS; $full=true игнорирует окно и читает все
     * MAX_PAGES страниц ленты. См. докблок класса про листание ленты.
     */
    public function import(bool $full = false, int $days = 2): void
    {
        $days = max($days, self::MIN_WINDOW_DAYS);
        $cutoffDate = $full ? null : date('Y-m-d', strtotime("-{$days} days"));
        Logger::info('АКРА (новости): окно — ' . ($full ? 'вся доступная лента (--full)' : "последние {$days} дн. (с {$cutoffDate})"));

        $candidates = $this->collectCandidates(
            static fn (int $page): string => RatingsHttp::get($page === 1 ? self::LIST_URL : self::LIST_URL . '?PAGEN_1=' . $page, 30),
            $cutoffDate,
            $full,
        );
        $this->totalCandidates = count($candidates);

        foreach ($candidates as $row) {
            $this->importRow($row);
        }

        $this->printReport();
    }

    /**
     * Карточки ленты в пределах окна, от старых к новым (хронологически
     * — то же требование корректности для CurrentRatingsSync, что и у
     * остальных агентств). Страницы читаются по правилам из докблока
     * класса. Отбор по дате — без остановки на первой старой карточке:
     * внутри одного дня лента не строго упорядочена.
     *
     * @param callable(int): string $fetchPage HTML страницы ленты по её номеру (с 1)
     * @return array<int, array{title: string, url: string, date: string}>
     */
    private function collectCandidates(callable $fetchPage, ?string $cutoffDate, bool $full): array
    {
        $candidates = [];
        $seen = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            if ($page > 1) {
                usleep($this->delayMicroseconds);
            }
            try {
                $html = $fetchPage($page);
            } catch (RuntimeException $e) {
                if ($page === 1) {
                    throw $e;
                }
                Logger::warn("АКРА (новости): страница {$page} ленты не открылась — обрабатываем уже полученные: {$e->getMessage()}");
                break;
            }
            $rows = $this->parseListPage($html);
            $this->pagesFetched++;
            if ($rows === []) {
                break;
            }

            $inWindow = 0;
            $unknown = 0;
            $olderThanWindow = false;
            foreach ($rows as $row) {
                if ($cutoffDate !== null && $row['date'] < $cutoffDate) {
                    $olderThanWindow = true;
                    continue;
                }
                $inWindow++;
                if (isset($seen[$row['url']])) {
                    continue; // лента сдвинулась между запросами страниц
                }
                $seen[$row['url']] = true;
                $candidates[] = $row;
                if (!RatingNewsLog::isKnown($this->db, self::AGENCY, $row['url'])) {
                    $unknown++;
                }
            }
            Logger::info("АКРА (новости): страница {$page} — карточек: " . count($rows) . ", в окне: {$inWindow}, ещё не виденных: {$unknown}");

            // Дальше не листаем: окно кончилось на этой странице (лента
            // идёт от новых дат к старым) или на ней всё уже видено.
            if ($inWindow === 0 || $olderThanWindow || (!$full && $unknown === 0)) {
                break;
            }
        }

        $candidates = array_reverse($candidates);
        usort($candidates, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $candidates;
    }

    /** @param array{title: string, url: string, date: string} $row */
    private function importRow(array $row): void
    {
        if (RatingNewsLog::isAlreadyMatched($this->db, self::AGENCY, $row['url'])) {
            $this->skippedAlreadyLogged++;
            return;
        }

        $verb = AcraNewsTitleParser::matchVerb($row['title']);
        if ($verb === null || !AcraNewsTitleParser::isCreditRatingAction($row['title'])) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_not_rating');
            $this->skippedNotRatingAction++;
            return;
        }

        // Ипотечные ценные бумаги и шкала структурированного
        // финансирования — не пишем вообще (решение пользователя,
        // 03.10.2026), см. RatingsNormalizer::isNonStandardRating().
        if (RatingsNormalizer::isNonStandardRating($row['title'])) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_non_standard');
            $this->skippedNonStandard++;
            return;
        }

        // Новости про облигации, которые не должны влиять на рейтинг
        // компании (решение пользователя, 28.09.2026): отзыв из-за
        // погашения или неразмещения, отзыв ожидаемого рейтинга, любые
        // новости про субординированные облигации — одно общее правило
        // для НКР, Эксперт РА и АКРА, см. RatingsNormalizer::bondNewsSkipStatus().
        $bondSkip = RatingsNormalizer::bondNewsSkipStatus($row['title'], $row['title'], str_starts_with($verb, 'отозвал'));
        if ($bondSkip !== null) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], $bondSkip);
            $this->skippedBondNews[$bondSkip] = ($this->skippedBondNews[$bondSkip] ?? 0) + 1;
            return;
        }

        $ratingTo = AcraNewsTitleParser::extractGrade($row['title'], $verb);
        if ($ratingTo === null) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_no_grade');
            $this->skippedNoRatingParsed++;
            $this->unparsedTitles[] = $row['title'];
            return;
        }

        $outlookTo = AcraNewsTitleParser::extractOutlook($row['title']);

        usleep($this->delayMicroseconds);
        $inn = $this->fetchInnFromDetailPage($row['url']);
        $issuerId = $this->resolveIssuer($inn, $row['title'], $row['url']);

        if ($issuerId === null) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_unmatched');
            $this->skippedNoIssuerResolved++;
            $suffix = $inn !== null ? " (ИНН={$inn}, не найден в issuers)" : ' (ИНН на странице не найден/отсутствует)';
            $this->unmatchedTitles[] = $row['title'] . $suffix;
            return;
        }

        $cached = CurrentRatingsSync::fetch($this->db, $issuerId, self::AGENCY);
        $ratingFrom = $cached['rating'];
        $outlookFrom = $cached['outlook'];

        $this->writer->upsert(
            $issuerId,
            self::AGENCY,
            $row['date'],
            $ratingFrom,
            $ratingTo,
            $outlookFrom,
            $outlookTo,
            $row['url'],
            $row['title'],
        );
        CurrentRatingsSync::sync($this->db, $issuerId, self::AGENCY, $row['date'], $ratingTo, $outlookTo, $cached);
        RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'matched');
        $this->matched++;
    }

    /**
     * ПЕРВИЧНО — ИНН со страницы релиза, затем явная связка
     * issuer_spv_links. Запасной путь — ISIN выпуска прямо в заголовке
     * (регионы/облигации без ИНН на детальной странице). Последний —
     * NameMatchResolver по названиям в кавычках: записывается только уже
     * подтверждённое название, остальное — предложения администратору.
     */
    private function resolveIssuer(?string $inn, string $title, string $url): ?int
    {
        if ($inn !== null) {
            $issuerId = $this->matcher->findIssuerIdByInn($inn);
            if ($issuerId !== null) {
                $this->matchedByInn++;
                return $issuerId;
            }

            // Явная ручная связка SPV → материнская компания (миграция
            // 023) — см. NkrNewsImporter::resolveIssuerIds() за подробным
            // объяснением реального случая («ЕВА»/«ФСК Активы»).
            $issuerId = $this->matcher->findIssuerIdBySpvLink($inn);
            if ($issuerId !== null) {
                $this->matchedBySpvLink++;
                return $issuerId;
            }
        }

        $isin = AcraNewsTitleParser::extractIsin($title);
        if ($isin !== null) {
            $issuerId = $this->matcher->findIssuerIdByIsin($isin);
            if ($issuerId !== null) {
                $this->matchedByIsin++;
                return $issuerId;
            }
        }

        $result = $this->nameResolver->resolve(
            self::AGENCY,
            $inn,
            AcraNewsTitleParser::extractQuotedNames($title),
            $title,
            $url,
        );
        $this->proposedByName += $result['proposed'];
        if ($result['issuerId'] !== null) {
            $this->matchedByApprovedName++;
        }

        return $result['issuerId'];
    }

    /** @return array<int, array{title: string, url: string, date: string}> */
    private function parseListPage(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        // Точное совпадение ОБОИХ классов-токенов (не подстрокой) — тот
        // же приём, что уже нужен был для Эксперт РА
        // (ExpertRaClient::parseNewsChunk() — contains(@class, "X")
        // иначе ловит и "X__suffix", реальный дубликат на живых данных).
        $rowClassExact = 'contains(concat(" ", normalize-space(@class), " "), " documents-row ")'
            . ' and contains(concat(" ", normalize-space(@class), " "), " search-table-row ")';

        $rows = [];
        foreach ($xpath->query("//div[{$rowClassExact}]") as $row) {
            $links = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " item__title ")]', $row);
            if ($links->length === 0) {
                continue;
            }
            $link = $links->item(0);
            $title = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');
            $href = $link->getAttribute('href');
            // Живой сайт отдаёт ОТНОСИТЕЛЬНЫЕ ссылки — найдено вживую
            // при первом прогоне (bin/debug_acra_news.php падал с "No
            // host part in the URL" без этой правки).
            if ($href !== '' && !str_starts_with($href, 'http')) {
                $href = self::BASE_URL . $href;
            }

            $dateNodes = $xpath->query('.//div[@data-type="date"]', $row);
            $dateRaw = $dateNodes->length > 0 ? trim(preg_replace('/\s+/u', ' ', $dateNodes->item(0)->textContent) ?? '') : '';
            $date = RatingsNormalizer::parseRussianMonthDate($dateRaw);

            if ($title === '' || $href === '' || $date === null) {
                continue;
            }

            $rows[] = ['title' => $title, 'url' => $href, 'date' => $date];
        }

        return $rows;
    }

    private function fetchInnFromDetailPage(string $url): ?string
    {
        $html = RatingsHttp::get($url, 30);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        foreach ($xpath->query('//table//tr[td]') as $row) {
            $cells = $xpath->query('./td', $row);
            if ($cells->length < 2) {
                continue;
            }
            $label = trim(preg_replace('/\s+/u', ' ', $cells->item(0)->textContent) ?? '');
            if (stripos($label, 'Идентификационный номер налогоплательщика') === false) {
                continue;
            }
            $value = trim(preg_replace('/\s+/u', ' ', $cells->item(1)->textContent) ?? '');
            if (preg_match('/(\d{10,12})/', $value, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту rating_actions (АКРА, новости) ===');
        Logger::info("Страниц ленты прочитано: {$this->pagesFetched}");
        Logger::info("Кандидатов в окне: {$this->totalCandidates}");
        Logger::info("Уже были окончательно обработаны раньше (status=matched в rating_news_log): {$this->skippedAlreadyLogged}");
        Logger::info("Пропущено (не похоже на кредитное рейтинговое действие): {$this->skippedNotRatingAction}");
        Logger::info('Пропущено как новости про облигации, не влияющие на рейтинг компании (погашение / не размещены / субординированные / ожидаемый рейтинг): '
            . ($this->skippedBondNews['skipped_bond_redemption'] ?? 0) . ' / '
            . ($this->skippedBondNews['skipped_bond_not_placed'] ?? 0) . ' / '
            . ($this->skippedBondNews['skipped_subordinated'] ?? 0) . ' / '
            . ($this->skippedBondNews['skipped_expected'] ?? 0));
        Logger::info("Пропущено (ипотечные ценные бумаги / шкала структурированного финансирования): {$this->skippedNonStandard}");
        Logger::info("Пропущено (не удалось разобрать уровень рейтинга): {$this->skippedNoRatingParsed}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched} (по ИНН: {$this->matchedByInn}, по связке issuer_spv_links: {$this->matchedBySpvLink}, по ISIN: {$this->matchedByIsin}, по подтверждённому названию: {$this->matchedByApprovedName})");
        Logger::info("Новых предложений сопоставления по названию (ждут подтверждения, bin/review_matches.php): {$this->proposedByName}");
        Logger::info("Не сопоставлено ни с одним issuer_id (попробуем снова на следующем прогоне): {$this->skippedNoIssuerResolved}");
        if ($this->unmatchedTitles !== []) {
            Logger::info('Не сопоставленные: ' . implode('; ', array_slice($this->unmatchedTitles, 0, 20)));
        }
        if ($this->unparsedTitles !== []) {
            Logger::info('Не разобранные заголовки: ' . implode('; ', array_slice($this->unparsedTitles, 0, 20)));
        }
    }
}
