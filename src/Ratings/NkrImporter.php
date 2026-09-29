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
 * (оба пишут в одну и ту же строку) — архитектурная нестыковка, не
 * разовый баг. RatingsNormalizer::normalizeWithdrawnRatingText() сводит
 * любое написание со словом "отозван" к тому же литералу, что и у
 * NkrNewsImporter — оба импортёра согласованы независимо от порядка прогонов.
 *
 * === fetchSnapshot() / applySnapshot() — сверка и перезапись (сентябрь 2026) ===
 *
 * fetchSnapshot() скачивает и разбирает выгрузку в нормализованный
 * "снимок сейчас", current_ratings не трогает. Его использует и сверка
 * (CurrentRatingsReconciler через bin/reconcile_ratings.php), и
 * перезапись. applySnapshot() пишет снимок — свежий (import()) или
 * проверенный на сверке и сохранённый в файл (bin/seed_ratings.php
 * --agency=nkr --snapshot=ФАЙЛ, П2: сначала сверка, потом перезапись).
 *
 * === Сопоставление по названию — только после подтверждения (миграция 024) ===
 *
 * TIN → явная связка issuer_spv_links (миграция 023; так подключены
 * матери, которых нет в issuers, — ВК, ПК «Борец», «Корпоративный центр
 * ИКС 5», ЕВРАЗ) → NameMatchResolver. Совпадение по названию (точное или
 * "по корню") в current_ratings без подтверждения администратора не
 * пишется — это предложение (issuer_name_match_reviews). Живой случай:
 * ООО «Озон» (фармацевтика) получила "по корню" рейтинг компании группы
 * Ozon. Дефолтному грейду ("D"/"SD") прогноз не положен — см.
 * RatingsNormalizer::isDefaultGrade() (найдено вживую на ООО «ЛКХ»).
 *
 * Одна строка на эмитента — SnapshotRows::latestPerIssuer() (П1): через
 * связки несколько строк выгрузки могут прийти в один issuer_id.
 */
final class NkrImporter
{
    private const AGENCY = 'nkr';
    private const EXPORT_URL = 'https://ratings.ru/issuers.php';
    private const ISSUERS_PAGE_URL = 'https://ratings.ru/ratings/issuers/';

    private int $totalRows = 0;
    private int $matched = 0;
    private int $matchedBySpvLink = 0;
    private int $matchedByApprovedName = 0;
    private int $proposedByName = 0;
    private int $collapsedDuplicates = 0;
    private int $unmatchedNoInn = 0;
    private int $unmatchedNoIssuer = 0;
    private int $skippedNoDate = 0;
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

    public function import(): void
    {
        $this->applySnapshot($this->fetchSnapshot());
        $this->printReport();
    }

    /**
     * @param array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, source_url: ?string}> $snapshot
     */
    public function applySnapshot(array $snapshot): void
    {
        $written = SnapshotRows::apply($this->db, self::AGENCY, $snapshot);
        Logger::info("НКР: записано строк current_ratings (source='snapshot'): {$written}");
    }

    /**
     * Скачивает и разбирает выгрузку НКР в нормализованный снимок "сейчас"
     * — current_ratings не трогает (пишутся только новые предложения
     * сопоставления по названию). Одна строка на эмитента (П1).
     *
     * @return array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, source_url: ?string}>
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

        $unique = SnapshotRows::latestPerIssuer($snapshot);
        $this->collapsedDuplicates = count($snapshot) - count($unique);

        return $unique;
    }

    /**
     * @param array<string, string> $row
     * @return array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, source_url: ?string}|null
     */
    private function parseRow(array $row): ?array
    {
        $tin = $row['TIN'] ?? '';
        $issuerName = (string) ($row['Issuer Name'] ?? '');
        $rating = RatingsNormalizer::normalizeGrade(RatingsNormalizer::normalizeWithdrawnRatingText($row['Rating'] ?? ''));
        [$sourceTitle, $sourceUrl] = self::describeRow($row, $rating);

        $issuerId = $this->resolveIssuerId($tin, $issuerName, $sourceTitle, $sourceUrl);
        if ($issuerId === null) {
            if (IssuerMatcher::normalizeInn($tin) === null) {
                $this->unmatchedNoInn++;
            } else {
                $this->unmatchedNoIssuer++;
            }
            $this->unmatchedNames[] = "{$issuerName} (TIN={$tin})";
            return null;
        }

        $lastActionDate = RatingsNormalizer::parseDate($row['Date'] ?? '');
        if ($lastActionDate === null) {
            $this->skippedNoDate++;
            return null;
        }

        $this->matched++;

        // Дефолтному грейду ("D"/"SD") прогноз не положен в принципе — по
        // прямому запросу пользователя (найдено вживую на ООО «ЛКХ»), см.
        // RatingsNormalizer::isDefaultGrade(). Здесь это скорее защитная
        // сетка (собственная колонка "Outlook" у НКР для D-строк на
        // практике и так обычно пустая), основной случай — новостной путь
        // через CurrentRatingsSync::sync().
        $outlook = RatingsNormalizer::isDefaultGrade($rating)
            ? null
            : (RatingsNormalizer::extractReviewStatusFromProse($row['Outlook'] ?? '')
                ?? RatingsNormalizer::mapOutlook($row['Outlook'] ?? ''));

        return [
            'issuer_id' => $issuerId,
            'issuer_name' => $issuerName,
            'rating' => $rating,
            'outlook' => $outlook,
            'last_action_date' => $lastActionDate,
            'source_url' => $sourceUrl,
        ];
    }

    /**
     * TIN → явная связка issuer_spv_links → NameMatchResolver (только
     * подтверждённое; иначе — предложение администратору). issuer_id,
     * уже сопоставленный напрямую в этом прогоне, по названию не
     * предлагается (реальный случай — АО «Аэрофьюэлз»/ООО «Аэрофьюэлз
     * Групп»). Вынесено отдельным методом ради офлайн-теста —
     * см. tests/test_root_priority_conflict.php.
     */
    private function resolveIssuerId(string $tin, string $issuerName, ?string $sourceTitle, ?string $sourceUrl): ?int
    {
        $issuerId = $this->matcher->findIssuerIdByInn($tin);
        if ($issuerId === null) {
            $issuerId = $this->matcher->findIssuerIdBySpvLink($tin);
            if ($issuerId !== null) {
                $this->matchedBySpvLink++;
            }
        }
        if ($issuerId !== null) {
            $this->innMatchedIssuerIds[$issuerId] = true;
            return $issuerId;
        }

        $result = $this->nameResolver->resolve(self::AGENCY, $tin, [$issuerName], $sourceTitle, $sourceUrl, $this->innMatchedIssuerIds);
        $this->proposedByName += $result['proposed'];
        if ($result['issuerId'] !== null) {
            $this->matchedByApprovedName++;
        }

        return $result['issuerId'];
    }

    /**
     * Заголовок и ссылка для предложения сопоставления: у строки выгрузки
     * нет заголовка новости, поэтому описываем саму строку, а ссылку берём
     * из колонки "Press release" (если там адрес), иначе — список
     * эмитентов НКР.
     *
     * @param array<string, string> $row
     * @return array{0: string, 1: string}
     */
    private static function describeRow(array $row, string $rating): array
    {
        $pressRelease = trim($row['Press release'] ?? '');
        // Колонка даёт адрес БЕЗ схемы — "ratings.ru/ratings/press-releases/
        // VIS-RA-160726/" (найдено на первом живом предложении, 28.09.2026:
        // ссылка ушла в текст заголовка, а в "Ссылка" встал общий список).
        $pressReleaseUrl = RatingsNormalizer::absoluteUrl($pressRelease, 'ratings.ru');
        $outlook = trim($row['Outlook'] ?? '');
        $date = trim($row['Date'] ?? '');

        $title = 'Полная выгрузка НКР: ' . trim($row['Issuer Name'] ?? '')
            . ' — рейтинг ' . ($rating !== '' ? $rating : '?')
            . ', прогноз ' . ($outlook !== '' ? $outlook : '—')
            . ', дата ' . ($date !== '' ? $date : '?');
        if ($pressRelease !== '' && $pressReleaseUrl === null) {
            $title .= "; пресс-релиз: {$pressRelease}";
        }

        return [$title, $pressReleaseUrl ?? self::ISSUERS_PAGE_URL];
    }

    /** Отчёт о разборе выгрузки — после fetchSnapshot() (import() и сверка). */
    public function printReport(): void
    {
        Logger::info('=== Отчёт по current_ratings (НКР) ===');
        Logger::info("Строк обработано: {$this->totalRows}");
        Logger::info("Сопоставлено с issuers: {$this->matched} (по связке issuer_spv_links: {$this->matchedBySpvLink}, по подтверждённому названию: {$this->matchedByApprovedName})");
        Logger::info("Строк свёрнуто в одну на эмитента (самая свежая дата, П1): {$this->collapsedDuplicates}");
        Logger::info("Новых предложений сопоставления по названию (ждут подтверждения, bin/review_matches.php): {$this->proposedByName}");
        Logger::info("Не сопоставлено (нет валидного ИНН в выгрузке): {$this->unmatchedNoInn}");
        Logger::info("Не сопоставлено (ИНН есть, но такого issuers.inn нет в базе): {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
