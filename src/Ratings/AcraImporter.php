<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use PDO;
use RuntimeException;

/**
 * current_ratings из JSON-файла, который пользователь готовит САМ, не
 * автоматизированным опросом сайта АКРА — см. docs/STAGE3_RATINGS.md:
 * www.acra-ratings.ru блокирует автоматические запросы через 2-3
 * попытки (WAF + Yandex SmartCaptcha), и этот проект принципиально не
 * обходит защиту от ботов ни для какого источника (та же граница, что
 * действовала для service.nalog.ru с самого начала). Этот импортёр
 * никогда не обращается к acra-ratings.ru сам — только читает уже
 * готовый локальный файл.
 *
 * Формат файла — массив объектов, подтверждён на реальном примере
 * (acra_issuers_smoketest.json, август 2026):
 *   [{"id":46608,"company":"ПАО \"БАНК ПСБ\"","inn":"7744000912",
 *     "rating":"AAA(RU)","forecast":"Стабильный","date":"28 авг 2026",
 *     "url":"https://www.acra-ratings.ru/ratings/issuers/24/"}, ...]
 * "inn" может быть null (пример: "город Томск" — у муниципалитета нет
 * ИНН юрлица в привычном смысле) — такие строки честно пропускаются,
 * без попытки сопоставить по названию.
 */
final class AcraImporter
{
    private const AGENCY = 'acra';

    private int $totalRows = 0;
    private int $skippedNoInn = 0;
    private int $skippedNoDate = 0;
    private int $matched = 0;
    private int $unmatchedNoIssuer = 0;
    /** @var array<int, string> */
    private array $unmatchedNames = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
    ) {
    }

    public function importFromFile(string $jsonPath): void
    {
        if (!is_file($jsonPath)) {
            throw new RuntimeException("Файл не найден: {$jsonPath}");
        }

        $rows = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($rows)) {
            throw new RuntimeException("Не удалось разобрать JSON (ожидался массив объектов): {$jsonPath}");
        }

        Logger::info('АКРА: строк в файле: ' . count($rows));

        foreach ($rows as $row) {
            $this->totalRows++;
            $this->importRow($row);
        }

        $this->printReport();
    }

    /** @param mixed $row */
    private function importRow($row): void
    {
        if (!is_array($row)) {
            return;
        }

        $rawInn = $row['inn'] ?? null;
        $inn = is_string($rawInn) ? IssuerMatcher::normalizeInn($rawInn) : null;
        if ($inn === null) {
            $this->skippedNoInn++;
            return;
        }

        $date = RatingsNormalizer::parseRussianMonthDate((string) ($row['date'] ?? ''));
        if ($date === null) {
            $this->skippedNoDate++;
            return;
        }

        $issuerId = $this->matcher->findIssuerIdByInn($inn);
        if ($issuerId === null) {
            $this->unmatchedNoIssuer++;
            $this->unmatchedNames[] = ($row['company'] ?? '?') . " (ИНН={$inn})";
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
            'agency' => self::AGENCY,
            'rating' => mb_substr(trim((string) ($row['rating'] ?? '')), 0, 20),
            'outlook' => RatingsNormalizer::mapOutlook((string) ($row['forecast'] ?? '')),
            'last_action_date' => $date,
        ]);

        $this->matched++;
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту current_ratings (АКРА, из файла) ===');
        Logger::info("Строк обработано: {$this->totalRows}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched}");
        Logger::info("Не сопоставлено (ИНН есть, но такого issuers.inn нет в базе): {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (нет ИНН в файле — муниципалитет/иностранное юрлицо и т.п.): {$this->skippedNoInn}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
