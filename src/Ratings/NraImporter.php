<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use PDO;
use RuntimeException;

/**
 * current_ratings из Excel-выгрузки НРА (ra-national.ru).
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
 * проверки, у части эмитентов — несколько строк за разные даты). Для
 * current_ratings берём по каждому эмитенту только САМУЮ СВЕЖУЮ строку.
 * Вся история endpoint'а не пропадает даром — это будущий источник для
 * rating_actions (см. открытый вопрос в STAGE3_RATINGS.md), просто в этом
 * заходе (по прямому решению пользователя "начинаем с текущих
 * рейтингов") в rating_actions ничего не пишем — количество исторических
 * строк только логируется в отчёте.
 *
 * "Вид рейтинга" в файле смешивает кредитные рейтинги ЭМИТЕНТА с ESG,
 * рейтингами качества услуг (УК/ИК/НПФ/депозитариев/регистраторов) и
 * кредитным рейтингом ОТДЕЛЬНЫХ ВЫПУСКОВ ОБЛИГАЦИЙ (это про security_id,
 * не issuer_id — current_ratings такого не хранит). current_ratings —
 * про кредитоспособность эмитента для оценки риска дефолта, поэтому
 * оставляем только явные "Кредитный рейтинг ... компаний/организаций".
 */
final class NraImporter
{
    private const LISTING_URL = 'https://www.ra-national.ru/ratings/';

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

        // По каждому эмитенту (ИНН) оставляем только самую свежую строку
        // среди кредитных рейтингов эмитента — current_ratings хранит
        // только "текущее" состояние, а не историю (та же роль, что уже
        // играет current_ratings.updated_at при повторных прогонах).
        /** @var array<string, array<string, string>> */
        $latestByInn = [];
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

            $existing = $latestByInn[$inn] ?? null;
            if ($existing === null || $date > $existing['_date']) {
                $row['_date'] = $date;
                $latestByInn[$inn] = $row;
            }
        }

        foreach ($latestByInn as $inn => $row) {
            // PHP приводит чисто цифровые строковые ключи массива к int
            // (ИНН вида "7744000912" станет ключом-числом) — приводим
            // обратно к string явно, а не полагаемся на тип ключа foreach.
            $this->importLatestRow((string) $inn, $row);
        }

        $this->printReport();
    }

    /** @param array<string, string> $row */
    private function importLatestRow(string $inn, array $row): void
    {
        $issuerId = $this->matcher->findIssuerIdByInn($inn);
        if ($issuerId === null) {
            $this->unmatchedNoIssuer++;
            $this->unmatchedNames[] = ($row['Название организации'] ?? '?') . " (ИНН={$inn})";
            return;
        }

        $lastActionDate = $row['_date'];

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
            'agency' => 'nra',
            'rating' => mb_substr(trim($row['Рейтинг'] ?? ''), 0, 20),
            'outlook' => RatingsNormalizer::mapOutlook($row['Прогноз'] ?? ''),
            'last_action_date' => $lastActionDate,
        ]);

        $this->matched++;
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
        Logger::info('=== Отчёт по импорту current_ratings (НРА) ===');
        Logger::info("Строк в истории обработано: {$this->totalRows}");
        Logger::info("  - из них не про кредитный рейтинг эмитента (ESG/услуги/отдельный выпуск облигаций): {$this->skippedWrongType}");
        Logger::info('Уникальных эмитентов с кредитным рейтингом (по последней записи): ' . ($this->matched + $this->unmatchedNoIssuer + $this->unmatchedNoInn));
        Logger::info("Сопоставлено с issuers и записано: {$this->matched}");
        Logger::info("Не сопоставлено (ИНН есть, но такого issuers.inn нет в базе): {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (нет валидного ИНН в строке): {$this->unmatchedNoInn}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
