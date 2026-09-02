<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use DateTimeImmutable;

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
 * Скользящее окно, ключ записи по source_url, never-skip-запись с
 * пометкой нераспознанных полей и обновление current_ratings —
 * та же логика, что и у NkrNewsImporter (см. его докблок для деталей,
 * не дублируем описание тут).
 */
final class ExpertRaNewsImporter
{
    private const AGENCY = 'expert_ra';
    private const VERBS = ['понизил', 'повысил', 'подтвердил', 'отозвал', 'присвоил'];

    private int $totalItems = 0;
    private int $skippedNotRatingAction = 0;
    private int $written = 0;
    private int $matched = 0;
    /** @var array<int, string> */
    private array $unresolvedTitles = [];

    public function __construct(
        private readonly IssuerMatcher $matcher,
        private readonly RatingActionsWriter $writer,
        private readonly CurrentRatingsStore $currentRatings,
        private readonly ExpertRaClient $client,
        private readonly int $delayMicroseconds = 400_000,
    ) {
    }

    /**
     * $windowHours — глубина скользящего окна. $full=true игнорирует
     * окно совсем — разовый проход по всей доступной ленте
     * (первоначальное наполнение таблицы).
     */
    public function import(int $windowHours = 6, bool $full = false): void
    {
        $cutoffDate = $full ? null : (new DateTimeImmutable())->modify("-{$windowHours} hours")->format('Y-m-d');
        Logger::info('Эксперт РА (новости): обрабатываем новости от ' . ($cutoffDate ?? '(--full: без ограничения по дате)') . ' и позже');

        $items = $this->client->fetchNewsItems($this->delayMicroseconds, $cutoffDate);
        Logger::info('Эксперт РА (новости): строк в ленте (в пределах загруженных страниц): ' . count($items));

        foreach ($items as $item) {
            $date = RatingsNormalizer::parseDate($item['date']);
            if ($date === null) {
                continue;
            }
            if ($cutoffDate !== null && $date < $cutoffDate) {
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

        /** @var array<int, string> $unresolved */
        $unresolved = [];

        $ratingTo = $this->extractRatingTo($combined, $verb);
        if ($ratingTo === null) {
            $unresolved[] = 'rating_to';
        }
        $outlookTo = $this->extractOutlookTo($combined);

        $issuerId = $this->resolveIssuer($item['title']);
        if ($issuerId === null) {
            $unresolved[] = 'issuer_id';
        }

        $ratingFrom = null;
        $outlookFrom = null;
        if ($issuerId !== null) {
            $prior = $this->currentRatings->find($issuerId, self::AGENCY);
            if ($prior !== null) {
                $ratingFrom = $prior['rating'];
                $outlookFrom = $prior['outlook'];
            }
        }

        $this->writer->upsert(
            $issuerId,
            self::AGENCY,
            $date,
            $ratingFrom,
            $ratingTo,
            $outlookFrom,
            $outlookTo,
            $item['url'],
            $unresolved,
        );
        $this->written++;

        if ($issuerId !== null && $ratingTo !== null) {
            $this->currentRatings->upsert($issuerId, self::AGENCY, $ratingTo, $outlookTo, $date);
            $this->matched++;
        } else {
            $this->unresolvedTitles[] = $item['title'] . ' (' . implode(',', $unresolved) . ')';
        }
    }

    private function matchVerb(string $title): ?string
    {
        if (!preg_match('/^«Эксперт РА»\s+(' . implode('|', self::VERBS) . ')\s/u', $title, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Возвращает НОВЫЙ уровень рейтинга (rating_to) из текста.
     * "Старое" значение в текст больше не парсится — оно приходит из
     * current_ratings (см. importItem()), поэтому конструкция
     * "Ранее...уровне X", которая раньше использовалась именно за этим,
     * тут больше не нужна вовсе.
     */
    private function extractRatingTo(string $combined, string $verb): ?string
    {
        if ($verb === 'отозвал') {
            return 'отозван';
        }

        preg_match_all('/ru[A-Za-zА-Яа-яЁё]{1,4}[+\-]?(?:\(EXP\))?/u', $combined, $m);
        $grades = array_values(array_unique($m[0]));
        if ($grades === []) {
            return null;
        }

        if (count($grades) >= 2 && preg_match('/\sс\s.+?\sдо\s/u', $combined)) {
            return $grades[1];
        }

        // Без "с X до Y" первый встретившийся грейд — это всегда
        // текущее (новое) значение: любое "Ранее действовал на уровне
        // X" всегда идёт отдельным, более поздним по тексту предложением.
        return $grades[0];
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

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту rating_actions (Эксперт РА, новости) ===');
        Logger::info("Строк обработано (в пределах окна): {$this->totalItems}");
        Logger::info("Пропущено (не похоже на кредитное рейтинговое действие): {$this->skippedNotRatingAction}");
        Logger::info("Записано в rating_actions: {$this->written}");
        Logger::info("Из них полностью распознано (эмитент + новый рейтинг, current_ratings обновлён): {$this->matched}");
        Logger::info('Из них с нераспознанными полями (нужен ручной просмотр): ' . count($this->unresolvedTitles));
        if ($this->unresolvedTitles !== []) {
            Logger::info('Нераспознанные: ' . implode('; ', array_slice($this->unresolvedTitles, 0, 20)));
        }
    }
}
