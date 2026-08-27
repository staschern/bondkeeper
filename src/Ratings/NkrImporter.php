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
        $tmpFile = sys_get_temp_dir() . '/bondkeeper_nkr_issuers_' . uniqid('', true) . '.xlsx';
        file_put_contents($tmpFile, RatingsHttp::get(self::EXPORT_URL));

        try {
            $rows = XlsxReader::readFirstSheetAsRows($tmpFile);
        } finally {
            unlink($tmpFile);
        }

        Logger::info('НКР: строк в выгрузке (эмитенты): ' . count($rows));

        foreach ($rows as $row) {
            $this->totalRows++;
            $this->importRow($row);
        }

        $this->printReport();
    }

    /** @param array<string, string> $row */
    private function importRow(array $row): void
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
            return;
        }

        $lastActionDate = RatingsNormalizer::parseDate($row['Date'] ?? '');
        if ($lastActionDate === null) {
            $this->skippedNoDate++;
            return;
        }

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
            'agency' => 'nkr',
            'rating' => mb_substr(trim($row['Rating'] ?? ''), 0, 20),
            'outlook' => RatingsNormalizer::mapOutlook($row['Outlook'] ?? ''),
            'last_action_date' => $lastActionDate,
        ]);

        $this->matched++;
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
