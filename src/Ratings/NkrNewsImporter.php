<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;

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
 * Скользящее окно (по прямому указанию пользователя — скрипт на cron
 * каждые ~30 минут): обрабатываются только строки списка с датой не
 * старше (now() - $windowHours, по умолчанию 6 часов, с запасом на
 * случай пропущенного/опоздавшего прогона). Список отсортирован по
 * убыванию даты — как только дошли до более старой строки, обход
 * останавливается. Ключ записи в БД — ссылка на пресс-релиз
 * (source_url, см. RatingActionsWriter) — при повторном попадании той
 * же новости в окно (соседние прогоны перекрываются) запись
 * ОБНОВЛЯЕТСЯ, а не дублируется. $full=true отключает окно совсем —
 * разовый проход по всей истории (первоначальное наполнение).
 *
 * Строка, прошедшая классификацию по заголовку (глагол действия +
 * "кредитный рейтинг"), ЗАПИСЫВАЕТСЯ ВСЕГДА, даже если не удалось
 * распознать эмитента или новый уровень рейтинга — с null в
 * нераспознанных полях и отметкой has_unresolved_fields/unresolved_fields
 * (см. RatingActionsWriter) для последующего ручного просмотра.
 *
 * "Старые" значения (rating_from/outlook_from) не парсятся из текста
 * новости — берутся из current_ratings ДО этого действия
 * (CurrentRatingsStore::find()); после успешного разбора действия
 * (эмитент и новый уровень оба распознаны) current_ratings
 * обновляется этим же новым значением (CurrentRatingsStore::upsert()).
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
    private int $written = 0;
    private int $matched = 0;
    /** @var array<int, string> */
    private array $unresolvedTitles = [];

    public function __construct(
        private readonly IssuerMatcher $matcher,
        private readonly RatingActionsWriter $writer,
        private readonly CurrentRatingsStore $currentRatings,
    ) {
    }

    /**
     * $windowHours — глубина скользящего окна (см. докблок класса).
     * $full=true игнорирует окно совсем — разовый проход по всей
     * доступной истории (первоначальное наполнение таблицы).
     */
    public function import(int $windowHours = 6, bool $full = false): void
    {
        $cutoffDate = $full ? null : (new DateTimeImmutable())->modify("-{$windowHours} hours")->format('Y-m-d');
        Logger::info('НКР (новости): обрабатываем новости от ' . ($cutoffDate ?? '(--full: без ограничения по дате)') . ' и позже');

        $html = RatingsHttp::get(self::LIST_URL, 60);
        $rows = $this->parseListRows($html);
        Logger::info('НКР (новости): строк в списке пресс-релизов: ' . count($rows));

        foreach ($rows as $row) {
            if ($cutoffDate !== null && $row['date'] < $cutoffDate) {
                // Список отсортирован по убыванию даты — дальше только
                // старее окна, можно остановиться.
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

        /** @var array<int, string> $unresolved */
        $unresolved = [];

        $ratingTo = $this->extractRatingTo($row['title'], $verb);
        if ($ratingTo === null) {
            $unresolved[] = 'rating_to';
        }
        $outlookTo = $this->extractOutlookTo($row['title']);

        $inn = $this->fetchInnFromDetailPage($row['url']);
        $issuerId = $inn !== null ? $this->matcher->findIssuerIdByInn($inn) : null;
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
            $row['date'],
            $ratingFrom,
            $ratingTo,
            $outlookFrom,
            $outlookTo,
            $row['url'],
            $unresolved,
        );
        $this->written++;

        if ($issuerId !== null && $ratingTo !== null) {
            $this->currentRatings->upsert($issuerId, self::AGENCY, $ratingTo, $outlookTo, $row['date']);
            $this->matched++;
        } else {
            $this->unresolvedTitles[] = $row['title'] . ' (' . implode(',', $unresolved) . ')';
        }
    }

    private function matchVerb(string $title): ?string
    {
        if (!preg_match('/^НКР\s+(' . implode('|', self::VERBS) . ')\s/u', $title, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Возвращает НОВЫЙ уровень рейтинга (rating_to) из заголовка.
     * "Старое" значение (rating_from) в текст не парсится вовсе — оно
     * приходит из current_ratings (см. importRow()).
     */
    private function extractRatingTo(string $title, string $verb): ?string
    {
        if ($verb === 'отозвало') {
            // Отзыв — обычно без указания конкретного уровня в заголовке:
            // пишем это как факт отзыва, тот же приём, что уже
            // используется для current_ratings.rating='отозван' у
            // Эксперт РА — честный текстовый статус, не выдуманный грейд.
            return 'отозван';
        }

        preg_match_all('/([A-Za-zА-Яа-яЁё]{1,4}[+\-]?\.ru)/u', $title, $m);
        $grades = $m[1];
        if ($grades === []) {
            return null;
        }

        if (count($grades) >= 2 && preg_match('/\sс\s.+?\sдо\s/u', $title)) {
            return $grades[1];
        }

        // Несколько уровней без "с X до Y" — обычно относится к разным
        // лицам в одном заголовке (компания + её облигации на другом
        // уровне). Берём первый — как правило, это уровень главного,
        // первым названного в заголовке лица.
        return $grades[0];
    }

    /**
     * НКР тоже может назвать и старый, и новый прогноз в ОДНОМ
     * предложении — "...и изменило прогноз со стабильного на
     * позитивный" (реальный заголовок, проверено вживую). Общий
     * RatingsNormalizer::mapOutlookFromProse() по всему заголовку тут
     * не подходит по той же причине, что и у Эксперт РА (см. докблок
     * ExpertRaNewsImporter::extractOutlookTo()): он ищет первый
     * попавшийся корень по фиксированному приоритету, а не по позиции
     * относительно "на" — может выбрать СТАРОЕ значение, если у его
     * корня приоритет выше. Сначала пробуем прицельный паттерн
     * "прогноз ... на <слово>" (после "на" в такой конструкции — всегда
     * НОВОЕ значение), и только если он не сработал (заголовки вида
     * "прогноз — стабильный", "со стабильным прогнозом" — без "на") —
     * общий разбор по всему заголовку.
     */
    private function extractOutlookTo(string $title): ?string
    {
        if (preg_match('/прогноз[^.]*?\sна\s+(позитивн\w*|негативн\w*|стабильн\w*|развивающ\w*|неопределенн\w*|неопределённ\w*)/ui', $title, $m)) {
            return RatingsNormalizer::mapOutlookFromProse($m[1]);
        }

        return RatingsNormalizer::mapOutlookFromProse($title);
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

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту rating_actions (НКР, новости) ===');
        Logger::info("Строк обработано (в пределах окна): {$this->totalRows}");
        Logger::info("Пропущено (не похоже на кредитное рейтинговое действие): {$this->skippedNotRatingAction}");
        Logger::info("Записано в rating_actions: {$this->written}");
        Logger::info("Из них полностью распознано (эмитент + новый рейтинг, current_ratings обновлён): {$this->matched}");
        Logger::info('Из них с нераспознанными полями (нужен ручной просмотр): ' . count($this->unresolvedTitles));
        if ($this->unresolvedTitles !== []) {
            Logger::info('Нераспознанные: ' . implode('; ', array_slice($this->unresolvedTitles, 0, 20)));
        }
    }
}
