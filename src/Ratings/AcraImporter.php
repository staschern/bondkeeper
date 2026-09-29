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
 * ИНН юрлица в привычном смысле).
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
 * === Сверка и перезапись АКРА (П7, сентябрь 2026) ===
 *
 * readSnapshotFromFile() разбирает файл в тот же нормализованный снимок,
 * что и у НКР/Эксперт РА, current_ratings не трогает — его использует
 * сверка (bin/reconcile_ratings.php --agency=acra --file=JSON). Перезапись
 * — importFromFile() или, после проверки сверки, seed_ratings.php
 * --agency=acra --snapshot=ФАЙЛ_СНИМКА. Одна строка на эмитента —
 * SnapshotRows::latestPerIssuer().
 *
 * === Сопоставление по названию — только после подтверждения (миграция 024) ===
 *
 * ИНН → явная связка issuer_spv_links (миграция 023) →
 * NameMatchResolver. Совпадение по названию без подтверждения
 * администратора в current_ratings не пишется — это предложение
 * (issuer_name_match_reviews), со ссылкой на карточку компании из файла.
 */
final class AcraImporter
{
    private const AGENCY = 'acra';

    private int $totalRows = 0;
    private int $skippedNoInn = 0;
    private int $skippedNoDate = 0;
    private int $matched = 0;
    private int $matchedBySpvLink = 0;
    private int $matchedByApprovedName = 0;
    private int $proposedByName = 0;
    private int $collapsedDuplicates = 0;
    private int $unmatchedNoIssuer = 0;
    /** @var array<int, string> */
    private array $unmatchedNames = [];
    /** @var array<int, true> issuer_id => уже сопоставлен НАПРЯМУЮ по ИНН/явной связке в ЭТОМ прогоне — по названию его не предлагаем */
    private array $innMatchedIssuerIds = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
        private readonly NameMatchResolver $nameResolver,
    ) {
    }

    public function importFromFile(string $jsonPath): void
    {
        $this->applySnapshot($this->readSnapshotFromFile($jsonPath));
        $this->printReport();
    }

    /**
     * @param array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, source_url: ?string}> $snapshot
     */
    public function applySnapshot(array $snapshot): void
    {
        $written = SnapshotRows::apply($this->db, self::AGENCY, $snapshot);
        Logger::info("АКРА: записано строк current_ratings (source='snapshot'): {$written}");
    }

    /**
     * @return array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, source_url: ?string}>
     */
    public function readSnapshotFromFile(string $jsonPath): array
    {
        if (!is_file($jsonPath)) {
            throw new RuntimeException("Файл не найден: {$jsonPath}");
        }

        $rows = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($rows)) {
            throw new RuntimeException("Не удалось разобрать JSON (ожидался массив объектов): {$jsonPath}");
        }

        Logger::info('АКРА: строк в файле: ' . count($rows));

        $snapshot = [];
        foreach ($rows as $row) {
            $this->totalRows++;
            $parsed = $this->parseRow($row);
            if ($parsed !== null) {
                $snapshot[] = $parsed;
            }
        }

        $unique = SnapshotRows::latestPerIssuer($snapshot);
        $this->collapsedDuplicates = count($snapshot) - count($unique);

        return $unique;
    }

    /**
     * @param mixed $row
     * @return array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, source_url: ?string}|null
     */
    private function parseRow($row): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        $rawInn = $row['inn'] ?? null;
        $inn = is_string($rawInn) ? IssuerMatcher::normalizeInn($rawInn) : null;
        $companyName = (string) ($row['company'] ?? '');
        $rating = RatingsNormalizer::normalizeGrade(RatingsNormalizer::normalizeWithdrawnRatingText((string) ($row['rating'] ?? '')));
        $url = is_string($row['url'] ?? null) && $row['url'] !== '' ? $row['url'] : null;
        $sourceTitle = 'Выгрузка АКРА (JSON-файл): ' . $companyName
            . ' — рейтинг ' . ($rating !== '' ? $rating : '?')
            . ', прогноз ' . (trim((string) ($row['forecast'] ?? '')) !== '' ? trim((string) $row['forecast']) : '—')
            . ', дата ' . (trim((string) ($row['date'] ?? '')) !== '' ? trim((string) $row['date']) : '?');

        $issuerId = $this->resolveIssuerId($inn, $companyName, $sourceTitle, $url);
        if ($issuerId === null) {
            if ($inn === null) {
                $this->skippedNoInn++;
            } else {
                $this->unmatchedNoIssuer++;
                $this->unmatchedNames[] = "{$companyName} (ИНН={$inn})";
            }
            return null;
        }

        $date = RatingsNormalizer::parseRussianMonthDate((string) ($row['date'] ?? ''));
        if ($date === null) {
            $this->skippedNoDate++;
            return null;
        }

        $this->matched++;

        // Дефолтному грейду ("D"/"SD") прогноз не положен в принципе — по
        // прямому запросу пользователя (найдено вживую на ООО «ЛКХ», НКР),
        // см. RatingsNormalizer::isDefaultGrade(). Защитная сетка для
        // полной сверки — основной случай для новостей идёт через
        // CurrentRatingsSync::sync().
        $outlook = RatingsNormalizer::outlookForRating(
            $rating,
            RatingsNormalizer::combineOutlookWithAcraWatchSuffix((string) ($row['forecast'] ?? '')),
        );

        return [
            'issuer_id' => $issuerId,
            'issuer_name' => $companyName,
            'rating' => $rating,
            'outlook' => $outlook,
            'last_action_date' => $date,
            'source_url' => $url,
        ];
    }

    /**
     * ИНН → явная связка issuer_spv_links → NameMatchResolver (только
     * подтверждённое; иначе — предложение администратору). См.
     * NkrImporter::resolveIssuerId().
     */
    private function resolveIssuerId(?string $inn, string $companyName, ?string $sourceTitle, ?string $sourceUrl): ?int
    {
        $issuerId = $inn !== null ? $this->matcher->findIssuerIdByInn($inn) : null;
        if ($issuerId === null && $inn !== null) {
            $issuerId = $this->matcher->findIssuerIdBySpvLink($inn);
            if ($issuerId !== null) {
                $this->matchedBySpvLink++;
            }
        }
        if ($issuerId !== null) {
            $this->innMatchedIssuerIds[$issuerId] = true;
            return $issuerId;
        }

        $result = $this->nameResolver->resolve(self::AGENCY, $inn, [$companyName], $sourceTitle, $sourceUrl, $this->innMatchedIssuerIds);
        $this->proposedByName += $result['proposed'];
        if ($result['issuerId'] !== null) {
            $this->matchedByApprovedName++;
        }

        return $result['issuerId'];
    }

    /** Отчёт о разборе файла — после readSnapshotFromFile() (импорт и сверка). */
    public function printReport(): void
    {
        Logger::info('=== Отчёт по current_ratings (АКРА, из файла) ===');
        Logger::info("Строк обработано: {$this->totalRows}");
        Logger::info("Сопоставлено с issuers: {$this->matched} (по связке issuer_spv_links: {$this->matchedBySpvLink}, по подтверждённому названию: {$this->matchedByApprovedName})");
        Logger::info("Строк свёрнуто в одну на эмитента (самая свежая дата): {$this->collapsedDuplicates}");
        Logger::info("Новых предложений сопоставления по названию (ждут подтверждения, bin/review_matches.php): {$this->proposedByName}");
        Logger::info("Не сопоставлено (ИНН есть, но такого issuers.inn нет в базе): {$this->unmatchedNoIssuer}");
        Logger::info("Не сопоставлено (нет ИНН в файле — муниципалитет/иностранное юрлицо и т.п.): {$this->skippedNoInn}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
