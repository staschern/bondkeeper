<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use DOMDocument;
use DOMXPath;
use PDO;

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
 * точное название в кавычках (как у НКР/Эксперт РА), на случай если ни
 * ИНН, ни ISIN не подошли.
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
 * === Четвёртый уровень сопоставления — "по корню" названия (миграция 022) ===
 *
 * Отдельно от составных действий выше: по прямому запросу пользователя
 * (сентябрь 2026), если в issuers заведена только SPV (или только
 * материнская компания), а заголовок называет ДРУГУЮ сторону — ни ИНН,
 * ни ISIN, ни точное имя её не найдут. resolveIssuer() пробует явную
 * ручную связку (issuer_spv_links, миграция 023), а затем
 * IssuerMatcher::findIssuerIdByRootName() ПОСЛЕДНИМ, только если все
 * предыдущие пути не дали ничего. Строка пишется как обычно, но с
 * флагом matched_by_root_name — собирается в getRootMatchNotices() для
 * уведомления администратора (см. bin/seed_ratings.php).
 */
final class AcraNewsImporter
{
    private const AGENCY = 'acra';
    private const BASE_URL = 'https://www.acra-ratings.ru';
    private const LIST_URL = self::BASE_URL . '/press-releases/';

    private int $totalCandidates = 0;
    private int $skippedAlreadyLogged = 0;
    private int $skippedNotRatingAction = 0;
    private int $skippedBondRedemption = 0;
    private int $skippedNoRatingParsed = 0;
    private int $matched = 0;
    private int $matchedByInn = 0;
    private int $matchedByIsin = 0;
    private int $matchedByName = 0;
    private int $matchedByRoot = 0;
    private int $matchedBySpvLink = 0;
    /** true, если ПОСЛЕДНИЙ вызов resolveIssuer() вернул issuer_id именно четвёртым, "по корню" уровнем. */
    private bool $lastMatchWasByRoot = false;
    /** @var array<int, string> заголовки для уведомления администратору о root-совпадениях (см. bin/seed_ratings.php) */
    private array $rootMatchNotices = [];
    private int $skippedNoIssuerResolved = 0;
    /** @var array<int, string> */
    private array $unmatchedTitles = [];
    /** @var array<int, string> */
    private array $unparsedTitles = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
        private readonly RatingActionsWriter $writer,
        private readonly int $delayMicroseconds = 2_000_000,
    ) {
    }

    /**
     * $days — сколько последних календарных дней рассматривать (по
     * умолчанию 2 — частый прогон каждые 30 минут); $full=true
     * игнорирует окно полностью (вся доступная лента — на момент
     * написания это всего 10 карточек, сайт не хранит архив на этой
     * странице глубже первой "страницы" списка, пагинация не найдена
     * вживую и не реализована — не гадаем про то, чего не видели).
     */
    public function import(bool $full = false, int $days = 2): void
    {
        $cutoffDate = $full ? null : date('Y-m-d', strtotime("-{$days} days"));
        Logger::info('АКРА (новости): окно — ' . ($full ? 'вся доступная лента (--full)' : "последние {$days} дн. (с {$cutoffDate})"));

        $html = RatingsHttp::get(self::LIST_URL, 30);
        $rows = $this->parseListPage($html);
        Logger::info('АКРА (новости): карточек в списке пресс-релизов: ' . count($rows));

        // Список отсортирован по убыванию даты — собираем кандидатов в
        // пределах окна, потом проходим в обратном порядке
        // (хронологически, от старых к новым) — то же требование
        // корректности для CurrentRatingsSync, что и у остальных трёх
        // агентств (см. их докблоки).
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

        // Отзыв рейтинга КОНКРЕТНОГО ВЫПУСКА облигаций из-за его
        // погашения — технический шум, не отзыв рейтинга эмитента (см.
        // докблок RatingsNormalizer::isBondIssueRedemptionWithdrawal(),
        // живой найденный баг с ФосАгро, 17 сентября 2026). Полностью
        // исключается из БД, а не просто помечается ошибкой разбора.
        if (str_starts_with($verb, 'отозвал') && RatingsNormalizer::isBondIssueRedemptionWithdrawal($row['title'])) {
            RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'skipped_bond_redemption');
            $this->skippedBondRedemption++;
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
        $issuerId = $this->resolveIssuer($inn, $row['title']);

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

        $matchedByRoot = $this->lastMatchWasByRoot;
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
            $matchedByRoot,
        );
        CurrentRatingsSync::sync($this->db, $issuerId, self::AGENCY, $row['date'], $ratingTo, $outlookTo, $cached, $matchedByRoot);
        if ($matchedByRoot) {
            $this->rootMatchNotices[] = "АКРА: «{$row['title']}» → issuer_id={$issuerId} (сопоставлено по корню названия, проверьте) — {$row['url']}";
        }
        RatingNewsLog::log($this->db, self::AGENCY, $row['url'], $row['date'], 'matched');
        $this->matched++;
    }

    /**
     * ПЕРВИЧНО — ИНН со страницы релиза. Запасной путь 1 — ISIN выпуска
     * прямо в заголовке (регионы/облигации без ИНН на детальной
     * странице). Запасной путь 2 — точное название в кавычках.
     */
    private function resolveIssuer(?string $inn, string $title): ?int
    {
        $this->lastMatchWasByRoot = false;

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

        $candidates = AcraNewsTitleParser::extractQuotedNames($title);

        foreach ($candidates as $candidate) {
            $issuerId = $this->matcher->findIssuerIdByName($candidate);
            if ($issuerId !== null) {
                $this->matchedByName++;
                return $issuerId;
            }
        }

        // Четвёртый, самый неточный уровень — "по корню" названия (без
        // ОПФ и маркерных слов SPV "Финанс"/"Капитал"), только если ИНН/
        // ISIN/точное имя выше не дали НИЧЕГО — см. IssuerMatcher::
        // findIssuerIdByRootName() и запрос пользователя (сентябрь 2026).
        foreach ($candidates as $candidate) {
            $issuerId = $this->matcher->findIssuerIdByRootName($candidate);
            if ($issuerId !== null) {
                $this->matchedByRoot++;
                $this->lastMatchWasByRoot = true;
                return $issuerId;
            }
        }

        return null;
    }

    /** @return array<int, string> тексты для админ-уведомления о совпадениях "по корню" в этом прогоне */
    public function getRootMatchNotices(): array
    {
        return $this->rootMatchNotices;
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
        Logger::info("Кандидатов в окне: {$this->totalCandidates}");
        Logger::info("Уже были окончательно обработаны раньше (status=matched в rating_news_log): {$this->skippedAlreadyLogged}");
        Logger::info("Пропущено (не похоже на кредитное рейтинговое действие): {$this->skippedNotRatingAction}");
        Logger::info("Пропущено (отзыв рейтинга выпуска облигаций из-за погашения — шум, не эмитентское действие): {$this->skippedBondRedemption}");
        Logger::info("Пропущено (не удалось разобрать уровень рейтинга): {$this->skippedNoRatingParsed}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched} (по ИНН: {$this->matchedByInn}, по ручной связке SPV: {$this->matchedBySpvLink}, по ISIN: {$this->matchedByIsin}, по имени запасным путём: {$this->matchedByName}, по корню названия SPV/материнская компания: {$this->matchedByRoot})");
        Logger::info("Не сопоставлено ни с одним issuer_id (попробуем снова на следующем прогоне): {$this->skippedNoIssuerResolved}");
        if ($this->unmatchedTitles !== []) {
            Logger::info('Не сопоставленные: ' . implode('; ', array_slice($this->unmatchedTitles, 0, 20)));
        }
        if ($this->unparsedTitles !== []) {
            Logger::info('Не разобранные заголовки: ' . implode('; ', array_slice($this->unparsedTitles, 0, 20)));
        }
    }
}
