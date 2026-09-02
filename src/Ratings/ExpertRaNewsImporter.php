<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use PDO;

/**
 * rating_actions из ленты пресс-релизов Эксперт РА (raexpert.ru/news/,
 * см. ExpertRaClient::fetchNewsItems() и STAGE3_RATINGS.md).
 *
 * В отличие от НКР, тут никогда не даётся ИНН — только название
 * компании в тексте заголовка/подзаголовка, часто в кавычках («АО МФК
 * «МК»»). Сопоставление — по точному (после нормализации) названию,
 * см. IssuerMatcher::findIssuerIdByName(). Заголовок нередко содержит
 * несколько названий в кавычках сразу (например, у "ожидаемого рейтинга
 * облигациям, планируемым к выпуску АО «Х»" — сначала может упоминаться
 * серия облигаций, потом сама компания) — пробуем каждое по порядку
 * появления, первое совпадение побеждает.
 *
 * Инкрементальность — так же, как у НКР: граница по MAX(action_date)
 * уже записанных действий этого агентства; лента отсортирована по
 * убыванию даты, останавливаемся, как только дошли до уже известного.
 */
final class ExpertRaNewsImporter
{
    private const AGENCY = 'expert_ra';
    private const VERBS = ['понизил', 'повысил', 'подтвердил', 'отозвал', 'присвоил'];

    private int $totalItems = 0;
    private int $skippedNotRatingAction = 0;
    private int $skippedNoRatingParsed = 0;
    private int $skippedNoEntityFound = 0;
    private int $matched = 0;
    /** @var array<int, string> */
    private array $unmatchedTitles = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
        private readonly RatingActionsWriter $writer,
        private readonly ExpertRaClient $client,
        private readonly int $delayMicroseconds = 400_000,
    ) {
    }

    public function import(bool $full = false): void
    {
        $sinceDate = $full ? null : $this->lastKnownActionDate();
        Logger::info('Эксперт РА (новости): уже известна история до ' . ($sinceDate ?? ($full ? '(--full: игнорируем границу)' : '(пусто, первый прогон)')));

        $items = $this->client->fetchNewsItems($this->delayMicroseconds, $sinceDate);
        Logger::info('Эксперт РА (новости): строк в ленте (в пределах загруженных страниц): ' . count($items));

        foreach ($items as $item) {
            $date = RatingsNormalizer::parseDate($item['date']);
            if ($date === null) {
                continue;
            }
            if ($sinceDate !== null && $date < $sinceDate) {
                break;
            }

            $this->totalItems++;
            $this->importItem($item, $date);
        }

        $this->printReport();
    }

    /** @param array{title: string, subtitle: string, url: string, date: string} $item */
    private function importItem(array $item, string $date): void
    {
        $verb = $this->matchVerb($item['title']);
        $combined = $item['title'] . ' ' . $item['subtitle'];
        if ($verb === null || !preg_match('/кредитн\w+\s+рейтинг/ui', $combined)) {
            $this->skippedNotRatingAction++;
            return;
        }

        [$ratingFrom, $ratingTo] = $this->extractRatingChange($combined, $verb);
        if ($ratingTo === null) {
            $this->skippedNoRatingParsed++;
            return;
        }

        $outlookTo = $this->extractOutlookTo($combined);

        $issuerId = $this->resolveIssuer($item['title']);
        if ($issuerId === null) {
            $this->skippedNoEntityFound++;
            $this->unmatchedTitles[] = $item['title'];
            return;
        }

        $this->writer->upsert(
            $issuerId,
            self::AGENCY,
            $date,
            $ratingFrom,
            $ratingTo,
            null,
            $outlookTo,
            $item['url'],
        );
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
     * Пробует каждое название в кавычках из заголовка по порядку
     * появления — первое, которое находится в issuers, и используется.
     * Заголовок вида "рейтинг облигациям серии X, планируемым к выпуску
     * АО «Y»" может содержать не только имя компании — поэтому пробуем
     * все кандидаты, а не только первый попавшийся.
     */
    private function resolveIssuer(string $title): ?int
    {
        preg_match_all('/«([^»]+)»/u', $title, $m);
        foreach ($m[1] as $candidate) {
            $issuerId = $this->matcher->findIssuerIdByName($candidate);
            if ($issuerId !== null) {
                return $issuerId;
            }
        }

        return null;
    }

    private function lastKnownActionDate(): ?string
    {
        $stmt = $this->db->prepare('SELECT MAX(action_date) FROM rating_actions WHERE agency = :agency');
        $stmt->execute(['agency' => self::AGENCY]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (string) $value : null;
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту rating_actions (Эксперт РА, новости) ===');
        Logger::info("Строк обработано (в пределах инкрементального окна): {$this->totalItems}");
        Logger::info("Пропущено (не похоже на кредитное рейтинговое действие): {$this->skippedNotRatingAction}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched}");
        Logger::info("Не сопоставлено (ни одно название в кавычках не нашлось в issuers): {$this->skippedNoEntityFound}");
        Logger::info("Пропущено (не удалось разобрать уровень рейтинга): {$this->skippedNoRatingParsed}");
        if ($this->unmatchedTitles !== []) {
            Logger::info('Не сопоставленные заголовки: ' . implode('; ', array_slice($this->unmatchedTitles, 0, 20)));
        }
    }
}
