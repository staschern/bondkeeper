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
 * конца 2019 года), см. STAGE3_RATINGS.md.
 *
 * ИНН здесь НЕ берётся из заголовка (там только название, часто в
 * падеже — "Трансстройбанка", "Селигдара" — сопоставлять по такому
 * названию ненадёжно). Вместо этого — по одному запросу на страницу
 * конкретного пресс-релиза, где ИНН даётся прямым текстом в блоке
 * "Регуляторное раскрытие": "Идентификационный номер налогоплательщика
 * (ИНН) рейтингуемого лица <10-12 цифр>". Дороже по числу запросов, но
 * надёжно — не нужно бороться с русскими падежами.
 *
 * Инкрементальность: запрашивается MAX(action_date) уже сохранённых
 * действий НКР, и разбираются только строки списка (он отсортирован по
 * убыванию даты) вплоть до этой границы — на повторных прогонах не
 * перезаказываем детальные страницы уже известных действий заново.
 *
 * Кейс "ООО-пустышка для привлечения долга" (см. запрос пользователя):
 * НКР в заголовке часто явно называет и операционную компанию, и её
 * SPV/облигации в одном действии ("НКР подтвердило кредитные рейтинги
 * ООО «ПК Борец» и облигаций ООО «Борец Капитал» на уровне A-.ru") —
 * для первого прохода берём ПЕРВОЕ (обычно главное, операционное) лицо
 * из заголовка; отдельная обработка второго лица — не в этом заходе.
 */
final class NkrNewsImporter
{
    private const AGENCY = 'nkr';
    private const LIST_URL = 'https://ratings.ru/ratings/press-releases/';
    private const VERBS = ['повысило', 'снизило', 'подтвердило', 'отозвало', 'присвоило'];

    private int $totalRows = 0;
    private int $skippedNotRatingAction = 0;
    private int $skippedNoRatingParsed = 0;
    private int $skippedNoInnOnDetailPage = 0;
    private int $matched = 0;
    private int $unmatchedNoIssuer = 0;
    /** @var array<int, string> */
    private array $unmatchedTitles = [];
    /** @var array<int, string> */
    private array $unparsedTitles = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
        private readonly RatingActionsWriter $writer,
    ) {
    }

    /**
     * $full=true разбирает весь список заново, игнорируя инкрементальную
     * границу — нужно периодически (не каждый день!), потому что граница
     * основана на том, что реально ЗАПИСАНО, а не на том, что реально
     * ПРОСМОТРЕНО: строка, пропущенная из-за несопоставленного эмитента
     * (issuers.inn ещё нет в базе), самостоятельно не пересматривается —
     * граница просто уходит вперёд после следующего успешно записанного
     * действия. Если эмитента позже добавили — старые пропущенные
     * действия для него сами не подхватятся, нужен $full=true.
     */
    public function import(bool $full = false): void
    {
        $sinceDate = $full ? null : $this->lastKnownActionDate();
        Logger::info('НКР (новости): уже известна история до ' . ($sinceDate ?? ($full ? '(--full: игнорируем границу)' : '(пусто, первый прогон)')));

        $html = RatingsHttp::get(self::LIST_URL, 60);
        $rows = $this->parseListRows($html);
        Logger::info('НКР (новости): строк в списке пресс-релизов: ' . count($rows));

        foreach ($rows as $row) {
            if ($sinceDate !== null && $row['date'] < $sinceDate) {
                // Список отсортирован по убыванию даты — дальше только
                // старее уже известного, можно остановиться.
                break;
            }

            $this->totalRows++;
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
        $verb = $this->matchVerb($row['title']);
        if ($verb === null || !preg_match('/кредитн\w+\s+рейтинг/ui', $row['title'])) {
            $this->skippedNotRatingAction++;
            return;
        }

        [$ratingFrom, $ratingTo] = $this->extractRatingChange($row['title'], $verb);
        if ($ratingTo === null) {
            $this->skippedNoRatingParsed++;
            $this->unparsedTitles[] = $row['title'];
            return;
        }
        $outlookTo = RatingsNormalizer::mapOutlookFromProse($row['title']);

        $inn = $this->fetchInnFromDetailPage($row['url']);
        if ($inn === null) {
            $this->skippedNoInnOnDetailPage++;
            $this->unmatchedTitles[] = $row['title'] . ' (ИНН на странице не найден)';
            return;
        }

        $issuerId = $this->matcher->findIssuerIdByInn($inn);
        if ($issuerId === null) {
            $this->unmatchedNoIssuer++;
            $this->unmatchedTitles[] = $row['title'] . " (ИНН={$inn})";
            return;
        }

        $this->writer->upsert(
            $issuerId,
            self::AGENCY,
            $row['date'],
            $ratingFrom,
            $ratingTo,
            null,
            $outlookTo,
            $row['url'],
        );
        $this->matched++;
    }

    private function matchVerb(string $title): ?string
    {
        if (!preg_match('/^НКР\s+(' . implode('|', self::VERBS) . ')\s/u', $title, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * @return array{0: ?string, 1: ?string} [rating_from, rating_to]
     */
    private function extractRatingChange(string $title, string $verb): array
    {
        if ($verb === 'отозвало') {
            // Отзыв — обычно без указания конкретного уровня в заголовке
            // ("и прогноз по нему без подтверждения" / "в связи с его
            // погашением"): пишем это как факт отзыва, тот же приём,
            // что уже используется для current_ratings.rating='отозван'
            // у Эксперт РА — честный текстовый статус, не выдуманный грейд.
            return [null, 'отозван'];
        }

        preg_match_all('/([A-Za-zА-Яа-яЁё]{1,4}[+\-]?\.ru)/u', $title, $m);
        $grades = $m[1];
        if ($grades === []) {
            return [null, null];
        }

        if (count($grades) >= 2 && preg_match('/\sс\s.+?\sдо\s/u', $title)) {
            return [$grades[0], $grades[1]];
        }

        // Несколько уровней без "с X до Y" — обычно относится к разным
        // лицам в одном заголовке (компания + её облигации на другом
        // уровне). Берём первый — как правило, это уровень главного,
        // первым названного в заголовке лица.
        return [null, $grades[0]];
    }

    private function fetchInnFromDetailPage(string $url): ?string
    {
        $html = RatingsHttp::get($url, 60);
        $text = $this->toPlainText($html);

        if (preg_match('/Идентификационный номер налогоплательщика\s*\(ИНН\)\s*рейтингуемого лица\s*(\d{10,12})/u', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function toPlainText(string $html): string
    {
        $html = preg_replace('/<script.*?<\/script>/su', ' ', $html) ?? $html;
        $text = preg_replace('/<[^>]+>/', ' ', $html) ?? $html;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
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
        Logger::info('=== Отчёт по импорту rating_actions (НКР, новости) ===');
        Logger::info("Строк обработано (в пределах инкрементального окна): {$this->totalRows}");
        Logger::info("Пропущено (не похоже на кредитное рейтинговое действие): {$this->skippedNotRatingAction}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched}");
        Logger::info("Не сопоставлено (ИНН есть, issuers.inn нет в базе / ИНН не найден на странице): {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (не найден ИНН на странице пресс-релиза): {$this->skippedNoInnOnDetailPage}");
        Logger::info("Пропущено (не удалось разобрать уровень рейтинга из заголовка): {$this->skippedNoRatingParsed}");
        if ($this->unmatchedTitles !== []) {
            Logger::info('Не сопоставленные: ' . implode('; ', array_slice($this->unmatchedTitles, 0, 20)));
        }
        if ($this->unparsedTitles !== []) {
            Logger::info('Не разобранные заголовки: ' . implode('; ', array_slice($this->unparsedTitles, 0, 20)));
        }
    }
}
