<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use PDO;
use RuntimeException;

/**
 * current_ratings И rating_actions из Excel-выгрузки НРА (ra-national.ru).
 *
 * Найдено вживую (август 2026, см. STAGE3_RATINGS.md): кнопка "Выгрузить
 * в Excel" на https://www.ra-national.ru/ratings/ ведёт на
 * /wp-load.php?security_key=...&export_id=20&action=get_data — URL с
 * security_key зашит прямо в HTML страницы списка, но ключ может
 * поменяться (не проверяли стабильность), поэтому не хардкодим его, а
 * каждый раз вытаскиваем свежую ссылку со страницы перед запросом.
 *
 * В отличие от НКР, этот файл — НЕ снимок "только текущее", а вся
 * история рейтинговых действий НРА с 2020 года (935 строк на момент
 * проверки, у части эмитентов — несколько строк за разные даты). По
 * прямому решению пользователя (после того как это обнаружилось) —
 * досеиваем и current_ratings (самая свежая строка на эмитента), и
 * rating_actions (вся отфильтрованная история, с rating_from/outlook_from,
 * вычисленными как значения ПРЕДЫДУЩЕГО по дате действия того же
 * эмитента) — одним проходом по уже скачанному файлу, без второго HTTP-запроса.
 *
 * "Вид рейтинга" в файле смешивает кредитные рейтинги ЭМИТЕНТА с ESG,
 * рейтингами качества услуг (УК/ИК/НПФ/депозитариев/регистраторов) и
 * кредитным рейтингом ОТДЕЛЬНЫХ ВЫПУСКОВ ОБЛИГАЦИЙ (это про security_id,
 * не issuer_id — ни current_ratings, ни rating_actions такого не хранят).
 * И current_ratings, и rating_actions — про кредитоспособность эмитента
 * для оценки риска дефолта, поэтому оставляем только явные "Кредитный
 * рейтинг ... компаний/организаций".
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

    private int $currentRatingsWritten = 0;
    private int $currentRatingsUnmatchedIssuer = 0;

    private int $actionsWritten = 0;
    private int $actionsUnmatchedIssuer = 0;
    private int $actionsSameDayCollisions = 0;

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

        // Группируем отфильтрованные (только кредитный рейтинг эмитента)
        // строки по ИНН — это основа и для current_ratings (нужна только
        // последняя по дате), и для rating_actions (нужна вся история).
        /** @var array<string, array<int, array<string, string>>> */
        $byInn = [];
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

            $row['_date'] = $date;
            $byInn[$inn][] = $row;
        }

        foreach ($byInn as $inn => $history) {
            // PHP приводит чисто цифровые строковые ключи массива к int
            // (ИНН вида "7744000912" станет ключом-числом) — приводим
            // обратно к string явно, а не полагаемся на тип ключа foreach.
            $this->importIssuerHistory((string) $inn, $history);
        }

        $this->printReport();
    }

    /** @param array<int, array<string, string>> $history строки одного эмитента, порядок не гарантирован */
    private function importIssuerHistory(string $inn, array $history): void
    {
        $issuerId = $this->matcher->findIssuerIdByInn($inn);
        if ($issuerId === null) {
            $this->currentRatingsUnmatchedIssuer++;
            $this->actionsUnmatchedIssuer += count($history);
            $this->unmatchedNames[] = ($history[0]['Название организации'] ?? '?') . " (ИНН={$inn})";
            return;
        }

        usort($history, static fn (array $a, array $b) => $a['_date'] <=> $b['_date']);

        // current_ratings — самая свежая запись после сортировки.
        $latest = $history[array_key_last($history)];
        $stmt = $this->db->prepare(
            'INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date)
             VALUES (:issuer_id, :agency, :rating, :outlook, :last_action_date)
             ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                outlook = VALUES(outlook),
                last_action_date = VALUES(last_action_date)'
        );
        $stmt->execute([
            'issuer_id' => $issuerId,
            'agency' => self::AGENCY,
            'rating' => mb_substr(trim($latest['Рейтинг'] ?? ''), 0, 20),
            'outlook' => RatingsNormalizer::mapOutlook($latest['Прогноз'] ?? ''),
            'last_action_date' => $latest['_date'],
        ]);
        $this->currentRatingsWritten++;

        // rating_actions — вся история, rating_from/outlook_from — значения
        // предыдущего по дате действия ЭТОГО ЖЕ эмитента (NULL у первого).
        $prevRating = null;
        $prevOutlook = null;
        $prevDate = null;
        $actionStmt = $this->db->prepare(
            'INSERT INTO rating_actions
                (issuer_id, agency, action_date, rating_from, rating_to, outlook_from, outlook_to, source_url)
             VALUES
                (:issuer_id, :agency, :action_date, :rating_from, :rating_to, :outlook_from, :outlook_to, :source_url)
             ON DUPLICATE KEY UPDATE
                rating_from = VALUES(rating_from),
                rating_to = VALUES(rating_to),
                outlook_from = VALUES(outlook_from),
                outlook_to = VALUES(outlook_to),
                source_url = VALUES(source_url)'
        );
        foreach ($history as $row) {
            if ($prevDate !== null && $row['_date'] === $prevDate) {
                // Два действия одной датой для одного эмитента — при апсерте
                // по (issuer_id, agency, action_date) второе тихо перезапишет
                // первое (см. комментарий в миграции 014). Считаем, чтобы
                // это было видно в отчёте, а не терялось молча.
                $this->actionsSameDayCollisions++;
            }

            $ratingTo = mb_substr(trim($row['Рейтинг'] ?? ''), 0, 20);
            $outlookTo = RatingsNormalizer::mapOutlook($row['Прогноз'] ?? '');

            $actionStmt->execute([
                'issuer_id' => $issuerId,
                'agency' => self::AGENCY,
                'action_date' => $row['_date'],
                'rating_from' => $prevRating,
                'rating_to' => $ratingTo,
                'outlook_from' => $prevOutlook,
                'outlook_to' => $outlookTo,
                'source_url' => mb_substr(trim($row['Ссылка на пресс релиз'] ?? ''), 0, 500) ?: null,
            ]);
            $this->actionsWritten++;

            $prevRating = $ratingTo;
            $prevOutlook = $outlookTo;
            $prevDate = $row['_date'];
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
        Logger::info("Строк в истории обработано: {$this->totalRows}");
        Logger::info("  - из них не про кредитный рейтинг эмитента (ESG/услуги/отдельный выпуск облигаций): {$this->skippedWrongType}");
        Logger::info("  - без валидного ИНН: {$this->unmatchedNoInn}");
        Logger::info("  - без распознанной даты: {$this->skippedNoDate}");
        Logger::info("current_ratings записано: {$this->currentRatingsWritten}");
        Logger::info("current_ratings — эмитент не сопоставлен (ИНН есть, issuers.inn нет в базе): {$this->currentRatingsUnmatchedIssuer}");
        Logger::info("rating_actions строк записано: {$this->actionsWritten}");
        Logger::info("rating_actions — пропущено из-за несопоставленного эмитента: {$this->actionsUnmatchedIssuer}");
        Logger::info("rating_actions — совпадений по дате внутри одного эмитента (вторая запись тихо перезаписала первую): {$this->actionsSameDayCollisions}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
