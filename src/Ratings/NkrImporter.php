<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use PDO;

/**
 * current_ratings из Excel-выгрузки НКР (ratings.ru).
 *
 * Найдено вживую (август 2026, см. STAGE3_RATINGS.md): кнопка "Выгрузить
 * в Excel" на https://ratings.ru/ratings/issuers/ ведёт на
 * https://ratings.ru/issuers.php — один файл, одна строка на эмитента
 * (не история), с колонками ID/Issuer Name/Date/Rating/Outlook/SCA/
 * Sector/TIN/OGRN/ESG-Rating/Press release. TIN — уже ИНН текстом (не
 * числом), в отличие от НРА (см. RatingsHttp/XlsxReader/IssuerMatcher) —
 * ведущие нули у 2 региональных ИНН из 259 сохранились без потери.
 * Сопоставление — только по ИНН, см. IssuerMatcher.
 *
 * === Статус "на пересмотре" (адаптация под общую ENUM-логику, сентябрь 2026) ===
 *
 * Колонка "Outlook" — то же самое поле, что и категория "Прогноз" в
 * фильтре на ratings.ru/ratings/issuers/, а там прямо в списке значений
 * есть "Рейтинг на пересмотре с возможностью повышения/понижения" —
 * то есть это не только формулировка из текста пресс-релиза (как у
 * NkrNewsImporter), а официальная категория ТЕКУЩЕГО состояния эмитента
 * на их сайте. Живого примера строки с таким значением в выгрузке на
 * момент правки не нашлось (значит, сейчас никто из выгруженных
 * эмитентов не находится в этом статусе), но раз категория официально
 * существует — стоит её распознавать, а не полагаться на то, что она
 * никогда не встретится. RatingsNormalizer::extractReviewStatusFromProse()
 * (тот же метод, что уже проверен на NkrNewsImporter) проверяется ПЕРЕД
 * обычным mapOutlook() — без такой формулировки поведение не меняется.
 *
 * === "Рейтинг отозван" vs "отозван" (найдено пользователем, сентябрь 2026) ===
 *
 * Колонка "Rating" копировалась дословно, как есть у НКР в их выгрузке —
 * если НКР сам пишет там что-то вроде "Рейтинг отозван" (не просто
 * "отозван"), а NkrNewsImporter для того же случая (глагол "отозвало" в
 * тексте пресс-релиза) пишет наш собственный литерал 'отозван', то один
 * и тот же эмитент мог получить РАЗНЫЙ текст в current_ratings.rating в
 * зависимости от того, какой из двух импортёров прогонялся последним
 * (оба пишут в одну и ту же строку через ON DUPLICATE KEY UPDATE) —
 * архитектурная нестыковка, не разовый баг. RatingsNormalizer::
 * normalizeWithdrawnRatingText() сводит любое написание со словом
 * "отозван" к тому же литералу, что и у NkrNewsImporter — оба импортёра
 * теперь согласованы независимо от порядка прогонов.
 *
 * === fetchSnapshot() — переиспользуется сверщиком (17 сентября 2026) ===
 *
 * Скачивание+разбор выгрузки вынесены в отдельный публичный метод
 * fetchSnapshot() — возвращает нормализованный "снимок сейчас" БЕЗ
 * записи в БД. import() сам теперь просто пишет то, что вернул этот
 * метод. Нужно это было CurrentRatingsReconciler (см.
 * docs/STAGE3_RATINGS.md, раздел "Сверка current_ratings") — той же
 * логике скачивания и разбора, но для СРАВНЕНИЯ с current_ratings, а не
 * для слепой перезаписи. Прямой запрос пользователя: НКР — единственное
 * агентство с отдельной страницей "снимок сейчас", поэтому именно здесь
 * сверка переиспользует существующий импортёр буквально, а не
 * пересчитывает что-то заново (в отличие от НРА, см. NraImporter).
 */
final class NkrImporter
{
    private const EXPORT_URL = 'https://ratings.ru/issuers.php';

    private int $totalRows = 0;
    private int $matched = 0;
    private int $unmatchedNoInn = 0;
    private int $unmatchedNoIssuer = 0;
    private int $skippedNoDate = 0;
    /** @var array<int, string> */
    private array $unmatchedNames = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
    ) {
    }

    public function import(): void
    {
        $snapshot = $this->fetchSnapshot();

        foreach ($snapshot as $row) {
            $this->writeCurrentRating($row);
        }

        $this->printReport();
    }

    /**
     * Скачивает и разбирает выгрузку НКР в нормализованный снимок "сейчас"
     * — БЕЗ записи в БД. Побочный эффект: заполняет те же счётчики
     * (matched/unmatchedNoInn/...), что и раньше заполнял import() —
     * printReport() продолжает работать одинаково что для import(), что
     * при вызове только этого метода.
     *
     * @return array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string}>
     */
    public function fetchSnapshot(): array
    {
        $tmpFile = sys_get_temp_dir() . '/bondkeeper_nkr_issuers_' . uniqid('', true) . '.xlsx';
        file_put_contents($tmpFile, RatingsHttp::get(self::EXPORT_URL));

        try {
            $rows = XlsxReader::readFirstSheetAsRows($tmpFile);
        } finally {
            unlink($tmpFile);
        }

        $this->totalRows = count($rows);
        Logger::info('НКР: строк в выгрузке (эмитенты): ' . count($rows));

        $snapshot = [];
        foreach ($rows as $row) {
            $parsed = $this->parseRow($row);
            if ($parsed !== null) {
                $snapshot[] = $parsed;
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, string> $row
     * @return array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string}|null
     */
    private function parseRow(array $row): ?array
    {
        $tin = $row['TIN'] ?? '';
        $issuerId = $this->matcher->findIssuerIdByInn($tin);
        if ($issuerId === null) {
            if (IssuerMatcher::normalizeInn($tin) === null) {
                $this->unmatchedNoInn++;
            } else {
                $this->unmatchedNoIssuer++;
            }
            $this->unmatchedNames[] = ($row['Issuer Name'] ?? '?') . " (TIN={$tin})";
            return null;
        }

        $lastActionDate = RatingsNormalizer::parseDate($row['Date'] ?? '');
        if ($lastActionDate === null) {
            $this->skippedNoDate++;
            return null;
        }

        $this->matched++;

        return [
            'issuer_id' => $issuerId,
            'issuer_name' => (string) ($row['Issuer Name'] ?? ''),
            'rating' => RatingsNormalizer::normalizeWithdrawnRatingText($row['Rating'] ?? ''),
            'outlook' => RatingsNormalizer::extractReviewStatusFromProse($row['Outlook'] ?? '')
                ?? RatingsNormalizer::mapOutlook($row['Outlook'] ?? ''),
            'last_action_date' => $lastActionDate,
        ];
    }

    /** @param array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string} $row */
    private function writeCurrentRating(array $row): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date)
             VALUES (:issuer_id, :agency, :rating, :outlook, :last_action_date)
             ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                outlook = VALUES(outlook),
                last_action_date = VALUES(last_action_date)'
        );
        $stmt->execute([
            'issuer_id' => $row['issuer_id'],
            'agency' => 'nkr',
            'rating' => $row['rating'],
            'outlook' => $row['outlook'],
            'last_action_date' => $row['last_action_date'],
        ]);
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту current_ratings (НКР) ===');
        Logger::info("Строк обработано: {$this->totalRows}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched}");
        Logger::info("Не сопоставлено (нет валидного ИНН в выгрузке): {$this->unmatchedNoInn}");
        Logger::info("Не сопоставлено (ИНН есть, но такого issuers.inn нет в базе): {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
