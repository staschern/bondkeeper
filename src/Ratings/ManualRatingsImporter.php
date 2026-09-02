<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use PDO;
use RuntimeException;

/**
 * current_ratings из ручного xlsx-файла — рейтинги, которых не нашлось
 * через автоматические источники (НРА/НКР/Эксперт РА/АКРА), собранные
 * пользователем вручную. Формат файла (лист "Лист1", подтверждён на
 * реальном примере, август 2026):
 *   ИНН | issuer_id | Полное наименование | agency | rating | outlook | last_action_date
 *
 * Особенности этого формата (в отличие от остальных источников):
 * - `outlook` уже приходит в словаре схемы (stable/positive/negative/
 *   developing), а не на русском — не пропускаем через
 *   RatingsNormalizer::mapOutlook(), а просто проверяем на допустимость.
 * - `last_action_date` — не ДД.ММ.ГГГГ и не "28 авг 2026", а порядковый
 *   номер дня Excel (xlsx хранит дату как число, XlsxReader формат
 *   ячейки не разбирает) — RatingsNormalizer::parseExcelSerialDate().
 * - Файл сам называет issuer_id — но мы ему не доверяем вслепую (это
 *   ровно то самое "случайно опечатались/скопировали не туда", от чего
 *   весь проект последовательно защищается): пересчитываем issuer_id
 *   заново по ИНН и, если он расходится с тем, что указано в файле,
 *   строку пропускаем и явно называем расхождение в отчёте — это
 *   сигнал, что в файле ошибка, а не повод молча довериться одному из
 *   двух источников истины.
 */
final class ManualRatingsImporter
{
    private const VALID_AGENCIES = ['nra', 'acra', 'expert_ra', 'nkr'];
    private const VALID_OUTLOOKS = ['positive', 'stable', 'negative', 'developing'];

    private int $totalRows = 0;
    private int $skippedNoInn = 0;
    private int $skippedBadAgency = 0;
    private int $skippedBadOutlook = 0;
    private int $skippedNoDate = 0;
    private int $skippedIssuerIdMismatch = 0;
    private int $matched = 0;
    private int $unmatchedNoIssuer = 0;
    /** @var array<int, string> */
    private array $unmatchedNames = [];
    /** @var array<int, string> */
    private array $mismatchNames = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
    ) {
    }

    public function importFromFile(string $xlsxPath): void
    {
        if (!is_file($xlsxPath)) {
            throw new RuntimeException("Файл не найден: {$xlsxPath}");
        }

        $rows = XlsxReader::readFirstSheetAsRows($xlsxPath);
        Logger::info('Ручной файл рейтингов: строк: ' . count($rows));

        foreach ($rows as $row) {
            $this->totalRows++;
            $this->importRow($row);
        }

        $this->printReport();
    }

    /** @param array<string, string> $row */
    private function importRow(array $row): void
    {
        $agency = trim($row['agency'] ?? '');
        if (!in_array($agency, self::VALID_AGENCIES, true)) {
            $this->skippedBadAgency++;
            Logger::warn('Ручной файл рейтингов: неизвестное агентство "' . $agency . '" — строка пропущена');
            return;
        }

        $inn = IssuerMatcher::normalizeInn($row['ИНН'] ?? '');
        if ($inn === null) {
            $this->skippedNoInn++;
            return;
        }

        $date = RatingsNormalizer::parseExcelSerialDate($row['last_action_date'] ?? '');
        if ($date === null) {
            $this->skippedNoDate++;
            return;
        }

        $rawOutlook = trim($row['outlook'] ?? '');
        if ($rawOutlook !== '' && !in_array($rawOutlook, self::VALID_OUTLOOKS, true)) {
            $this->skippedBadOutlook++;
            Logger::warn("Ручной файл рейтингов: недопустимое значение outlook \"{$rawOutlook}\" (ИНН={$inn}) — строка пропущена");
            return;
        }
        $outlook = $rawOutlook === '' ? null : $rawOutlook;

        $issuerId = $this->matcher->findIssuerIdByInn($inn);
        if ($issuerId === null) {
            $this->unmatchedNoIssuer++;
            $this->unmatchedNames[] = ($row['Полное наименование'] ?? '?') . " (ИНН={$inn})";
            return;
        }

        $claimedIssuerId = (int) ($row['issuer_id'] ?? 0);
        if ($claimedIssuerId !== 0 && $claimedIssuerId !== $issuerId) {
            $this->skippedIssuerIdMismatch++;
            $this->mismatchNames[] = ($row['Полное наименование'] ?? '?')
                . " (ИНН={$inn}: в файле issuer_id={$claimedIssuerId}, по ИНН реально {$issuerId})";
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
            'agency' => $agency,
            'rating' => mb_substr(trim($row['rating'] ?? ''), 0, 20),
            'outlook' => $outlook,
            'last_action_date' => $date,
        ]);

        $this->matched++;
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту current_ratings (ручной файл) ===');
        Logger::info("Строк обработано: {$this->totalRows}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched}");
        Logger::info("Не сопоставлено (ИНН есть, но такого issuers.inn нет в базе): {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (нет валидного ИНН): {$this->skippedNoInn}");
        Logger::info("Пропущено (неизвестное агентство): {$this->skippedBadAgency}");
        Logger::info("Пропущено (недопустимый outlook): {$this->skippedBadOutlook}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        Logger::info("Пропущено (issuer_id в файле не совпал с реальным по ИНН — вероятная ошибка в файле): {$this->skippedIssuerIdMismatch}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
        if ($this->mismatchNames !== []) {
            Logger::info('Расхождения issuer_id (проверьте файл): ' . implode('; ', array_slice($this->mismatchNames, 0, 30)));
        }
    }
}
