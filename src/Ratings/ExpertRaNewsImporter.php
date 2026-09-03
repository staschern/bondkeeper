<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use PDO;

/**
 * rating_actions из ленты пресс-релизов Эксперт РА (raexpert.ru/news/,
 * см. ExpertRaClient::fetchNewsItems()).
 *
 * === ИНН — есть, просто не в ленте (поправка, сентябрь 2026) ===
 *
 * Первая версия этого класса (и докблок, который тут был раньше) считала,
 * что Эксперт РА "никогда не даёт ИНН" — это оказалось верно только для
 * САМОЙ ЛЕНТЫ (AJAX-чанк с title+subtitle), но НЕ для полного текста
 * пресс-релиза: у КАЖДОЙ статьи (`item['url']`) есть раздел "Регуляторное
 * раскрытие" с ИНН рейтингуемого лица — тот же принцип, что у НКР, просто
 * никто не проверял вживую саму статью, а не ленту (проверено на 3
 * реальных релизах, включая структурированное финансирование — везде
 * нашёлся, кроме регионального облигационного выпуска, где поле честно
 * содержит "Отсутствует", см. ExpertRaClient::fetchReleaseInn()).
 *
 * Теперь сопоставление — как у НКР: ПЕРВИЧНО по ИНН со страницы
 * пресс-релиза (один доп. запрос на строку, см. resolveIssuer()),
 * запасным путём — по точному названию в кавычках
 * (IssuerMatcher::findIssuerIdByName() + RatingsNormalizer::
 * extractQuotedNames(), тот же общий метод, что у НКР). Заголовок
 * нередко содержит несколько названий в кавычках сразу («Эксперт РА» —
 * само название агентства — тоже в кавычках первым) — в запасном пути
 * пробуем каждое по порядку, первое совпадение с issuers побеждает. В
 * отличие от НКР — составных действий (компания-поручитель + её SPV в
 * ОДНОМ действии) на живых данных Эксперт РА НЕ встретилось (проверено
 * на 200 реальных новостях) — поэтому здесь только один issuer_id на
 * действие, без множественной записи.
 *
 * === Расписание, дедуп, current_ratings — общая механика НКР/НРА (сентябрь 2026) ===
 *
 * Раньше — инкрементальность по MAX(action_date) уже записанных
 * действий (тот же изъян, что был у НКР до перехода: пресс-релиз,
 * пропущенный из-за несопоставленного эмитента, никогда не
 * пересматривался сам). Теперь: дедуп по URL пресс-релиза через
 * RatingNewsLog/rating_news_log (миграция 016, тот же журнал, что и у
 * НКР/НРА — колонка agency изначально сделана под все агентства).
 *
 * $days — сколько последних календарных дней рассматривать. С появлением
 * запроса на детальную страницу ради ИНН (см. выше) стоимость прогона
 * стала такой же, как у НКР — поэтому вернулось то же деление на
 * частый/глубокий проход: по умолчанию 2 дня (частый, каждые 30 минут —
 * не тратим лишние запросы на детальные страницы давно известных
 * несопоставленных релизов) и 14 дней (более редкий "глубокий" —
 * ловит эмитентов, добавленных в issuers с опозданием), см. bin/
 * daemon_expert_ra_news.php.
 *
 * current_ratings обновляется через CurrentRatingsSync сразу после
 * каждой записи (было — вообще не обновлялось этим импортёром, только
 * отдельным ежедневным ExpertRaImporter). rating_from/outlook_from —
 * из кэша ДО записи действия, тот же приём, что у НКР/НРА. Строки
 * обрабатываются в ХРОНОЛОГИЧЕСКОМ порядке (лента отдаётся по убыванию
 * даты — собираем кандидатов, разворачиваем).
 *
 * === Статус "под наблюдением" — своя терминология (миграция 017) ===
 *
 * Эксперт РА даёт статус "под наблюдением" СВОБОДНЫМ ТЕКСТОМ, но своими
 * оборотами — не "на пересмотре" (НКР): "установил статус «под
 * наблюдением»" / "продлил статус «под наблюдением»" (статус активен) /
 * "снял статус «под наблюдением»" (статус завершён). "продлил" — новый
 * глагол в VERBS, ради заголовков вида "«Эксперт РА» продлил статус
 * «под наблюдением» по кредитному рейтингу ООО «Х»", где сам грейд явно
 * не переподтверждается через "с X до Y" (см. RatingsNormalizer::
 * extractWatchStatusFromExpertRaProse()). Направление (positive/negative)
 * в этой формулировке НИ РАЗУ не встретилось на 18 реальных примерах —
 * "установил"/"продлил" всегда даёт голое under_review.
 *
 * === Нестандартные шкалы (.sf) — реально нужно, в отличие от НКР ===
 *
 * Эксперт РА рейтингует структурированное финансирование ("...на уровне
 * ruAAA.sf") — 12 из 200 проверенных реальных новостей (сентябрь 2026).
 * Без RatingsNormalizer::isNonStandardRating() общий регэксп грейда
 * извлёк бы "ruAAA" (останавливается на точке перед "sf"), молча теряя
 * суффикс — действие целиком пропускается (см. importItem()).
 */
final class ExpertRaNewsImporter
{
    private const AGENCY = 'expert_ra';
    private const VERBS = ['понизил', 'повысил', 'подтвердил', 'отозвал', 'присвоил', 'продлил'];

    private int $totalItems = 0;
    private int $skippedAlreadyLogged = 0;
    private int $skippedNotRatingAction = 0;
    private int $skippedNonStandardRating = 0;
    private int $skippedNoRatingParsed = 0;
    private int $skippedNoEntityFound = 0;
    private int $matched = 0;
    private int $matchedByInn = 0;
    private int $matchedByName = 0;
    /** @var array<int, string> */
    private array $unmatchedTitles = [];
    /** @var array<int, string> */
    private array $nonStandardTitles = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
        private readonly RatingActionsWriter $writer,
        private readonly ExpertRaClient $client,
        private readonly int $delayMicroseconds = 400_000,
    ) {
    }

    /**
     * $days — сколько последних календарных дней новостей рассматривать;
     * $full=true игнорирует окно полностью (вся доступная лента) —
     * редкая ручная операция, не часть расписания.
     */
    public function import(bool $full = false, int $days = 2): void
    {
        $cutoffDate = $full ? null : date('Y-m-d', strtotime("-{$days} days"));
        Logger::info('Эксперт РА (новости): окно — ' . ($full ? 'вся доступная лента (--full)' : "последние {$days} дн. (с {$cutoffDate})"));

        $items = $this->client->fetchNewsItems($this->delayMicroseconds, $cutoffDate);
        Logger::info('Эксперт РА (новости): строк в ленте (в пределах загруженных страниц): ' . count($items));

        // Лента отсортирована по убыванию даты — собираем кандидатов в
        // пределах окна, потом проходим в обратном порядке
        // (хронологически), см. докблок класса про current_ratings.
        $candidates = [];
        foreach ($items as $item) {
            $date = RatingsNormalizer::parseDate($item['date']);
            if ($date === null) {
                continue;
            }
            if ($cutoffDate !== null && $date < $cutoffDate) {
                break;
            }
            $item['_date'] = $date;
            $candidates[] = $item;
        }
        $this->totalItems = count($candidates);

        foreach (array_reverse($candidates) as $item) {
            $this->importItem($item);
        }

        $this->printReport();
    }

    /** @param array{title: string, subtitle: string, url: string, date: string, _date: string} $item */
    private function importItem(array $item): void
    {
        if (RatingNewsLog::isAlreadyMatched($this->db, self::AGENCY, $item['url'])) {
            $this->skippedAlreadyLogged++;
            return;
        }

        $verb = $this->matchVerb($item['title']);
        $combined = $item['title'] . ' ' . $item['subtitle'];
        if ($verb === null || !preg_match('/кредитн\w+\s+рейтинг/ui', $combined)) {
            RatingNewsLog::log($this->db, self::AGENCY, $item['url'], $item['_date'], 'skipped_not_rating');
            $this->skippedNotRatingAction++;
            return;
        }

        if (RatingsNormalizer::isNonStandardRating($combined)) {
            RatingNewsLog::log($this->db, self::AGENCY, $item['url'], $item['_date'], 'skipped_non_standard');
            $this->skippedNonStandardRating++;
            $this->nonStandardTitles[] = $item['title'];
            return;
        }

        [$ratingFromParsed, $ratingToParsed] = $this->extractRatingChange($combined, $verb);
        if ($ratingToParsed === null && $verb !== 'продлил') {
            // Для остальных 5 глаголов отсутствие грейда — реальная
            // неудача разбора. Для "продлил" грейд обычно всё же есть
            // (фраза "продолжает действовать на уровне X"), но если вдруг
            // и его нет — не считаем ошибкой, попробуем current_ratings.
            RatingNewsLog::log($this->db, self::AGENCY, $item['url'], $item['_date'], 'skipped_no_grade');
            $this->skippedNoRatingParsed++;
            return;
        }

        $baseOutlookTo = $this->extractOutlookTo($combined);
        $watchStatus = RatingsNormalizer::extractWatchStatusFromExpertRaProse($combined, $baseOutlookTo);
        $outlookTo = $watchStatus ?? $baseOutlookTo;

        $issuerId = $this->resolveIssuer($item['url'], $item['title']);
        if ($issuerId === null) {
            RatingNewsLog::log($this->db, self::AGENCY, $item['url'], $item['_date'], 'skipped_unmatched');
            $this->skippedNoEntityFound++;
            $this->unmatchedTitles[] = $item['title'];
            return;
        }

        $cached = CurrentRatingsSync::fetch($this->db, $issuerId, self::AGENCY);
        $ratingFrom = $ratingFromParsed ?? $cached['rating'];
        $ratingTo = $ratingToParsed ?? $cached['rating'];
        if ($ratingTo === null) {
            // verb='продлил', грейд не нашёлся в тексте, и в кэше пусто
            // (первое вообще известное действие по эмитенту) — честно
            // нечего писать в NOT NULL rating_to.
            RatingNewsLog::log($this->db, self::AGENCY, $item['url'], $item['_date'], 'skipped_no_grade');
            $this->skippedNoRatingParsed++;
            return;
        }

        $this->writer->upsert(
            $issuerId,
            self::AGENCY,
            $item['_date'],
            $ratingFrom,
            $ratingTo,
            $cached['outlook'],
            $outlookTo,
            $item['url'],
            $item['title'],
        );
        CurrentRatingsSync::sync($this->db, $issuerId, self::AGENCY, $item['_date'], $ratingTo, $outlookTo, $cached);
        RatingNewsLog::log($this->db, self::AGENCY, $item['url'], $item['_date'], 'matched');
        $this->matched++;
    }

    private function matchVerb(string $title): ?string
    {
        if (!preg_match('/^«Эксперт РА»\s+(' . implode('|', self::VERBS) . ')\s/u', $title, $m)) {
            return null;
        }

        return $m[1];
    }

    /** @return array{0: ?string, 1: ?string} [rating_from, rating_to] */
    private function extractRatingChange(string $combined, string $verb): array
    {
        if ($verb === 'отозвал') {
            return [null, 'отозван'];
        }

        preg_match_all('/ru[A-Za-zА-Яа-яЁё]{1,4}[+\-]?(?:\(EXP\))?/u', $combined, $m);
        $grades = array_values(array_unique($m[0]));
        if ($grades === []) {
            return [null, null];
        }

        if (count($grades) >= 2 && preg_match('/\sс\s.+?\sдо\s/u', $combined)) {
            return [$grades[0], $grades[1]];
        }

        // "Ранее у Компании действовал рейтинг на уровне X" — старый
        // уровень часто даётся отдельным предложением в подзаголовке,
        // а не через "с X до Y" в самом заголовке.
        if (preg_match('/Ранее[^.]*?уровне\s+(ru[A-Za-zА-Яа-яЁё]{1,4}[+\-]?(?:\(EXP\))?)/u', $combined, $mm)) {
            $from = $mm[1];
            $to = $grades[0] !== $from ? $grades[0] : ($grades[1] ?? $grades[0]);

            return [$from, $to];
        }

        return [null, $grades[0]];
    }

    /**
     * Эксперт РА нередко упоминает и старый, и новый прогноз в ОДНОМ
     * предложении подзаголовка — "прогноз по рейтингу изменён с
     * НЕГАТИВНОГО на РАЗВИВАЮЩИЙСЯ" (реальный пример, проверено вживую).
     * Общий RatingsNormalizer::mapOutlookFromProse() тут не подходит:
     * он просто ищет первый попавшийся корень слова по фиксированному
     * порядку и находил бы "негативн" раньше "развивающ", хотя это
     * СТАРЫЙ прогноз, а не новый. Поэтому: сначала пробуем прицельный
     * паттерн "прогноз ... на <слово>" (после "на" в такой конструкции —
     * всегда НОВОЕ значение), и только если он не сработал (заголовки
     * вида "со стабильным прогнозом" — там нет "на" вообще) — общий
     * разбор по всему тексту (без отдельного предложения "Ранее...",
     * которое всегда про старое состояние, а не про текущее действие).
     */
    private function extractOutlookTo(string $combined): ?string
    {
        if (preg_match('/прогноз[^.]*?\sна\s+(позитивн\w*|негативн\w*|стабильн\w*|развивающ\w*|неопределенн\w*|неопределённ\w*)/ui', $combined, $m)) {
            return RatingsNormalizer::mapOutlookFromProse($m[1]);
        }

        $withoutPriorState = preg_replace('/Ранее[^.]*\./u', '', $combined) ?? $combined;

        return RatingsNormalizer::mapOutlookFromProse($withoutPriorState);
    }

    /**
     * ПЕРВИЧНО — по ИНН со страницы пресс-релиза (см. докблок класса и
     * ExpertRaClient::fetchReleaseInn()), надёжно, как у НКР. Один
     * неудачный HTTP-запрос на детальную страницу (сеть/сайт недоступны)
     * не должен ронять весь прогон — остальные ещё не обработанные строки
     * в этом же прогоне были бы потеряны зря; в этом случае просто
     * переходим к запасному пути (тот же принцип, что уже применяет
     * ExpertRaImporter::resolveInn() для карточек компаний).
     *
     * ЗАПАСНЫМ путём — по точному названию в кавычках, каждое по порядку
     * появления, первое совпадение с issuers побеждает. «Эксперт РА»
     * (само название агентства) тоже попадает в кандидаты первым —
     * безвредно, просто один неудачный lookup перед реальным кандидатом
     * (RatingsNormalizer::extractQuotedNames() не отфильтрует его —
     * начинается с заглавной, не содержит рейтинговых слов; агентство
     * само по себе не заведено как issuer).
     */
    private function resolveIssuer(string $releaseUrl, string $title): ?int
    {
        usleep($this->delayMicroseconds);
        try {
            $inn = $this->client->fetchReleaseInn($releaseUrl);
        } catch (\Throwable $e) {
            Logger::warn("Эксперт РА: не удалось получить страницу пресс-релиза {$releaseUrl}: {$e->getMessage()}");
            $inn = null;
        }
        if ($inn !== null) {
            $issuerId = $this->matcher->findIssuerIdByInn($inn);
            if ($issuerId !== null) {
                $this->matchedByInn++;
                return $issuerId;
            }
        }

        foreach (RatingsNormalizer::extractQuotedNames($title) as $candidate) {
            $issuerId = $this->matcher->findIssuerIdByName($candidate);
            if ($issuerId !== null) {
                $this->matchedByName++;
                return $issuerId;
            }
        }

        return null;
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту rating_actions (Эксперт РА, новости) ===');
        Logger::info("Кандидатов в окне: {$this->totalItems}");
        Logger::info("Уже были окончательно обработаны раньше (status=matched в rating_news_log): {$this->skippedAlreadyLogged}");
        Logger::info("Пропущено (не похоже на кредитное рейтинговое действие): {$this->skippedNotRatingAction}");
        Logger::info("Пропущено (нестандартная шкала, напр. '.sf'): {$this->skippedNonStandardRating}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched} (по ИНН: {$this->matchedByInn}, по имени запасным путём: {$this->matchedByName})");
        Logger::info("Не сопоставлено (ни одно название в кавычках не нашлось в issuers, попробуем снова на следующем прогоне): {$this->skippedNoEntityFound}");
        Logger::info("Пропущено (не удалось разобрать уровень рейтинга и нет данных в current_ratings): {$this->skippedNoRatingParsed}");
        if ($this->unmatchedTitles !== []) {
            Logger::info('Не сопоставленные заголовки: ' . implode('; ', array_slice($this->unmatchedTitles, 0, 20)));
        }
        if ($this->nonStandardTitles !== []) {
            Logger::info('Нестандартная шкала (пропущены целиком): ' . implode('; ', array_slice($this->nonStandardTitles, 0, 20)));
        }
    }
}
