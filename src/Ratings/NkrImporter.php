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
 *
 * === Второй уровень сопоставления — "по корню" названия (миграция 022) ===
 *
 * По прямому запросу пользователя (сентябрь 2026): если в issuers
 * заведена только SPV (или только материнская компания), а строка
 * официальной выгрузки НКР называет ДРУГУЮ сторону — TIN не совпадёт
 * вообще (разные юрлица, разные ИНН). parseRow() пробует
 * IssuerMatcher::findIssuerIdByRootName() как fallback, только если TIN
 * не подошёл (после явной ручной связки issuer_spv_links, миграция 023
 * — приоритет выше root). Строка пишется как обычно, но с флагом
 * matched_by_root_name — собирается в getRootMatchNotices() для
 * уведомления администратора (см. bin/seed_ratings.php). Дефолтному
 * грейду ("D"/"SD") прогноз не положен — см. RatingsNormalizer::isDefaultGrade()
 * (найдено вживую на ООО «ЛКХ»).
 */
final class NkrImporter
{
    private const EXPORT_URL = 'https://ratings.ru/issuers.php';

    private int $totalRows = 0;
    private int $matched = 0;
    private int $matchedByRoot = 0;
    private int $unmatchedNoInn = 0;
    private int $unmatchedNoIssuer = 0;
    private int $skippedNoDate = 0;
    /** @var array<int, string> */
    private array $unmatchedNames = [];
    /** @var array<int, string> заголовки для уведомления администратору о root-совпадениях (см. bin/seed_ratings.php) */
    private array $rootMatchNotices = [];
    private int $skippedRootPriorityConflict = 0;
    /** @var array<int, true> issuer_id => уже сопоставлен НАПРЯМУЮ по ИНН/явной связке в ЭТОМ прогоне (см. resolveIssuerIdWithPriority()) */
    private array $innMatchedIssuerIds = [];

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
     * @return array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, matched_by_root_name: bool}>
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
     * @return array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, matched_by_root_name: bool}|null
     */
    private function parseRow(array $row): ?array
    {
        $tin = $row['TIN'] ?? '';
        $issuerName = (string) ($row['Issuer Name'] ?? '');

        ['issuerId' => $issuerId, 'matchedByRoot' => $matchedByRoot, 'skippedPriorityConflict' => $skippedPriorityConflict]
            = $this->resolveIssuerIdWithPriority($tin, $issuerName);

        if ($skippedPriorityConflict) {
            $this->skippedRootPriorityConflict++;
            $this->unmatchedNames[] = "{$issuerName} (TIN={$tin}) — root-совпадение проигнорировано: issuer_id уже сопоставлен напрямую по ИНН в этом же прогоне";
            return null;
        }
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
        if ($matchedByRoot) {
            $this->matchedByRoot++;
            $this->rootMatchNotices[] = "НКР (полная сверка): «{$issuerName}» (TIN={$tin}) → issuer_id={$issuerId} (сопоставлено по корню названия, проверьте)";
        }

        $rating = RatingsNormalizer::normalizeWithdrawnRatingText($row['Rating'] ?? '');
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
            'matched_by_root_name' => $matchedByRoot,
        ];
    }

    /**
     * Приоритет прямого сопоставления (ИНН, либо явная ручная связка
     * issuer_spv_links — миграция 023) НАД корнем (по прямому запросу
     * пользователя, сентябрь 2026, реальный найденный случай — АО
     * «Аэрофьюэлз»/ООО «Аэрофьюэлз Групп»): если issuer_id уже сопоставлен
     * прямым путём где-то раньше в ЭТОМ ЖЕ прогоне, root-совпадение на тот
     * же issuer_id позже — игнорируется, не перезаписывает более надёжный
     * результат. Обратный порядок (root первым, прямое совпадение вторым)
     * безопасен сам по себе — прямое совпадение просто законно
     * "перезапишет" root-значение позже.
     *
     * Вынесено отдельным методом ради офлайн-теста без завязки на
     * MySQL-диалект INSERT — см. tests/test_root_priority_conflict.php.
     *
     * @return array{issuerId: ?int, matchedByRoot: bool, skippedPriorityConflict: bool}
     */
    private function resolveIssuerIdWithPriority(string $tin, string $issuerName): array
    {
        $issuerId = $this->matcher->findIssuerIdByInn($tin);
        if ($issuerId === null) {
            $issuerId = $this->matcher->findIssuerIdBySpvLink($tin);
        }
        if ($issuerId !== null) {
            $this->innMatchedIssuerIds[$issuerId] = true;

            return ['issuerId' => $issuerId, 'matchedByRoot' => false, 'skippedPriorityConflict' => false];
        }

        $rootId = $this->matcher->findIssuerIdByRootName($issuerName);
        if ($rootId !== null && isset($this->innMatchedIssuerIds[$rootId])) {
            return ['issuerId' => null, 'matchedByRoot' => false, 'skippedPriorityConflict' => true];
        }

        return ['issuerId' => $rootId, 'matchedByRoot' => $rootId !== null, 'skippedPriorityConflict' => false];
    }

    /** @return array<int, string> тексты для админ-уведомления о совпадениях "по корню" в этом прогоне */
    public function getRootMatchNotices(): array
    {
        return $this->rootMatchNotices;
    }

    /** @param array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, matched_by_root_name: bool} $row */
    private function writeCurrentRating(array $row): void
    {
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
            'issuer_id' => $row['issuer_id'],
            'agency' => 'nkr',
            'rating' => $row['rating'],
            'outlook' => $row['outlook'],
            'last_action_date' => $row['last_action_date'],
            'matched_by_root_name' => $row['matched_by_root_name'] ? 1 : 0,
        ]);
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту current_ratings (НКР) ===');
        Logger::info("Строк обработано: {$this->totalRows}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched} (из них по корню названия SPV/материнская компания: {$this->matchedByRoot})");
        Logger::info("Root-совпадений проигнорировано из-за приоритета прямого сопоставления в этом же прогоне: {$this->skippedRootPriorityConflict}");
        Logger::info("Не сопоставлено (нет валидного ИНН в выгрузке): {$this->unmatchedNoInn}");
        Logger::info("Не сопоставлено (ИНН есть, но такого issuers.inn нет в базе): {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
