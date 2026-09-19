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
 *
 * === Статус "под наблюдением" (адаптация под общую ENUM-логику, сентябрь 2026) ===
 *
 * "forecast" из выгрузки иногда содержит статус наблюдения СЛИТНО с
 * направлением прогноза через запятую — "Позитивный, под наблюдением"
 * (проверено вживую на acra-ratings.ru/ratings/issuers/: ООО
 * «АЛЬФА-ЛИЗИНГ» и другие строки того же списка). RatingsNormalizer::
 * combineOutlookWithAcraWatchSuffix() распознаёт этот формат (подробности
 * и оговорка про непроверенный "снято с наблюдения" — в её докблоке);
 * без суффикса ведёт себя как прежний mapOutlook().
 *
 * === Второй уровень сопоставления — "по корню" названия (миграция 022) ===
 *
 * По прямому запросу пользователя (сентябрь 2026): если в issuers
 * заведена только SPV (или только материнская компания), а строка файла
 * называет ДРУГУЮ сторону — ИНН не совпадёт вообще (разные юрлица).
 * importRow() пробует IssuerMatcher::findIssuerIdByRootName() как
 * fallback, если ИНН не подошёл (или в файле его вообще нет). Строка
 * пишется как обычно, но с флагом matched_by_root_name — собирается в
 * getRootMatchNotices() для уведомления администратора (см.
 * bin/seed_ratings.php).
 */
final class AcraImporter
{
    private const AGENCY = 'acra';

    private int $totalRows = 0;
    private int $skippedNoInn = 0;
    private int $skippedNoDate = 0;
    private int $matched = 0;
    private int $matchedByRoot = 0;
    private int $unmatchedNoIssuer = 0;
    /** @var array<int, string> */
    private array $unmatchedNames = [];
    /** @var array<int, string> заголовки для уведомления администратору о root-совпадениях (см. bin/seed_ratings.php) */
    private array $rootMatchNotices = [];
    private int $skippedRootPriorityConflict = 0;
    /** @var array<int, true> issuer_id => уже сопоставлен НАПРЯМУЮ по ИНН в ЭТОМ прогоне (см. importRow()) */
    private array $innMatchedIssuerIds = [];

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
        $companyName = (string) ($row['company'] ?? '');

        ['issuerId' => $issuerId, 'matchedByRoot' => $matchedByRoot, 'skippedPriorityConflict' => $skippedPriorityConflict]
            = $this->resolveIssuerIdWithPriority($inn, $companyName);

        if ($skippedPriorityConflict) {
            $this->skippedRootPriorityConflict++;
            $this->unmatchedNames[] = "{$companyName} (ИНН=" . ($inn ?? '—') . ') — root-совпадение проигнорировано: issuer_id уже сопоставлен напрямую по ИНН в этом же прогоне';
            return;
        }

        if ($issuerId === null) {
            if ($inn === null) {
                $this->skippedNoInn++;
            } else {
                $this->unmatchedNoIssuer++;
                $this->unmatchedNames[] = "{$companyName} (ИНН={$inn})";
            }
            return;
        }

        $date = RatingsNormalizer::parseRussianMonthDate((string) ($row['date'] ?? ''));
        if ($date === null) {
            $this->skippedNoDate++;
            return;
        }

        $rating = mb_substr(trim((string) ($row['rating'] ?? '')), 0, 20);
        // Дефолтному грейду ("D"/"SD") прогноз не положен в принципе — по
        // прямому запросу пользователя (найдено вживую на ООО «ЛКХ», НКР),
        // см. RatingsNormalizer::isDefaultGrade(). Защитная сетка для
        // полной сверки — основной случай для новостей идёт через
        // CurrentRatingsSync::sync().
        $outlook = RatingsNormalizer::isDefaultGrade($rating)
            ? null
            : RatingsNormalizer::combineOutlookWithAcraWatchSuffix((string) ($row['forecast'] ?? ''));

        $stmt = $this->db->prepare(
            'INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date, matched_by_root_name)
             VALUES (:issuer_id, :agency, :rating, :outlook, :last_action_date, :matched_by_root_name)
             ON DUPLICATE KEY UPDATE
                rating = VALUES(rating),
                outlook = VALUES(outlook),
                last_action_date = VALUES(last_action_date),
                matched_by_root_name = VALUES(matched_by_root_name)'
        );
        $stmt->execute([
            'issuer_id' => $issuerId,
            'agency' => self::AGENCY,
            'rating' => $rating,
            'outlook' => $outlook,
            'last_action_date' => $date,
            'matched_by_root_name' => $matchedByRoot ? 1 : 0,
        ]);

        $this->matched++;
        if ($matchedByRoot) {
            $this->matchedByRoot++;
            $this->rootMatchNotices[] = "АКРА (полная сверка): «{$companyName}» → issuer_id={$issuerId} (сопоставлено по корню названия, проверьте)";
        }
    }

    /** @return array<int, string> тексты для админ-уведомления о совпадениях "по корню" в этом прогоне */
    public function getRootMatchNotices(): array
    {
        return $this->rootMatchNotices;
    }

    /**
     * Приоритет ИНН НАД корнем — см. подробное объяснение в
     * NkrImporter::resolveIssuerIdWithPriority() (тот же приём, вынесено
     * отдельным методом ради офлайн-теста без завязки на MySQL-диалект
     * INSERT, см. tests/test_root_priority_conflict.php).
     *
     * @return array{issuerId: ?int, matchedByRoot: bool, skippedPriorityConflict: bool}
     */
    private function resolveIssuerIdWithPriority(?string $inn, string $companyName): array
    {
        $issuerId = $inn !== null ? $this->matcher->findIssuerIdByInn($inn) : null;
        if ($issuerId === null && $inn !== null) {
            // Явная ручная связка SPV → материнская компания (миграция
            // 022) — приоритет выше root, см. NkrImporter::
            // resolveIssuerIdWithPriority() за подробным объяснением.
            $issuerId = $this->matcher->findIssuerIdBySpvLink($inn);
        }
        if ($issuerId !== null) {
            $this->innMatchedIssuerIds[$issuerId] = true;

            return ['issuerId' => $issuerId, 'matchedByRoot' => false, 'skippedPriorityConflict' => false];
        }

        $rootId = $this->matcher->findIssuerIdByRootName($companyName);
        if ($rootId !== null && isset($this->innMatchedIssuerIds[$rootId])) {
            return ['issuerId' => null, 'matchedByRoot' => false, 'skippedPriorityConflict' => true];
        }

        return ['issuerId' => $rootId, 'matchedByRoot' => $rootId !== null, 'skippedPriorityConflict' => false];
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту current_ratings (АКРА, из файла) ===');
        Logger::info("Строк обработано: {$this->totalRows}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched} (из них по корню названия SPV/материнская компания: {$this->matchedByRoot})");
        Logger::info("Root-совпадений проигнорировано из-за приоритета прямого ИНН в этом же прогоне: {$this->skippedRootPriorityConflict}");
        Logger::info("Не сопоставлено (ИНН есть, но такого issuers.inn нет в базе): {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (нет ИНН в файле — муниципалитет/иностранное юрлицо и т.п.): {$this->skippedNoInn}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
