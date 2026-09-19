<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use PDO;
use Throwable;

/**
 * current_ratings из raexpert.ru (Эксперт РА) — по прямому разрешению
 * пользователя обходит боевой сайт агентства постранично (список) плюс
 * по одному запросу на карточку каждой компании (ради ИНН, единственного
 * надёжного способа сопоставить компанию с issuers.id — см.
 * docs/STAGE3_RATINGS.md). Между КАЖДЫМ запросом — задержка
 * ($delayMicroseconds), это не биржевой API и не рассчитано на
 * автоматизированный обход в лоб.
 *
 * Категории — только те, что про кредитоспособность САМОГО ЭМИТЕНТА
 * (не регионы/муниципалитеты/суверен — у них нет ИНН юрлица в привычном
 * смысле; не ESG/качество услуг/структурированное финансирование/
 * отдельные выпуски облигаций — те про другое или про security_id, не
 * issuer_id). Полный список категорий сайта — в фильтре на
 * https://raexpert.ru/ratings/ (чекбоксы с data-path), см. STAGE3_RATINGS.md
 * про то, что осталось за бортом и почему.
 *
 * === fetchSnapshot() — переиспользуется сверщиком (17 сентября 2026) ===
 *
 * Обход категорий + резолв ИНН вынесены в отдельный публичный метод
 * fetchSnapshot() — возвращает нормализованный "снимок сейчас" БЕЗ
 * записи в БД. import() сам теперь просто пишет то, что вернул этот
 * метод. Как и НКР (в отличие от НРА), у Эксперт РА ЕСТЬ отдельная
 * страница "снимок сейчас" (список действующих рейтингов по категориям,
 * не история с датой начала) — CurrentRatingsReconciler переиспользует
 * этот метод буквально, см. docs/STAGE3_RATINGS.md.
 *
 * === Второй уровень сопоставления — "по корню" названия (миграция 022) ===
 *
 * По прямому запросу пользователя (сентябрь 2026): если в issuers
 * заведена только SPV (или только материнская компания), а карточка
 * компании на сайте называет ДРУГУЮ сторону — ИНН не совпадёт вообще
 * (разные юрлица). parseRow() пробует IssuerMatcher::findIssuerIdByRootName()
 * как fallback (после явной ручной связки issuer_spv_links, миграция
 * 023 — приоритет выше root), если ИНН не подошёл или карточка вообще
 * не дала ИНН. Строка пишется как обычно, но с флагом
 * matched_by_root_name — собирается в getRootMatchNotices() для
 * уведомления администратора (см. bin/seed_ratings.php). Дефолтному
 * грейду ("D"/"SD") прогноз не положен — см. RatingsNormalizer::isDefaultGrade()
 * (найдено вживую на ООО «ЛКХ», НКР).
 */
final class ExpertRaImporter
{
    private const AGENCY = 'expert_ra';

    /** @var array<string, string> slug => человекочитаемое имя категории (для логов) */
    private const CATEGORIES = [
        'bankcredit' => 'Банки',
        'credits_fin' => 'Финансовые компании',
        'credits' => 'Нефинансовые компании',
        'credits_holding' => 'Холдинговые компании',
        'credits_project' => 'Проектные компании',
        'factor' => 'Факторинговые компании',
        'leasing_rel' => 'Лизинговые компании',
        'insurance' => 'Страховые компании',
        'life' => 'Страхование жизни',
        'mfi_credits' => 'МФО',
    ];

    private int $totalRows = 0;
    private int $cardFetchFailed = 0;
    private int $noInnOnCard = 0;
    private int $skippedNoDate = 0;
    private int $matched = 0;
    private int $matchedByRoot = 0;
    private int $unmatchedNoIssuer = 0;
    /** @var array<int, string> */
    private array $unmatchedNames = [];
    /** @var array<int, string> заголовки для уведомления администратору о root-совпадениях (см. bin/seed_ratings.php) */
    private array $rootMatchNotices = [];
    private int $skippedRootPriorityConflict = 0;
    /** @var array<int, true> issuer_id => уже сопоставлен НАПРЯМУЮ по ИНН/явной связке в ЭТОМ прогоне (см. resolveIssuerIdWithPriority()) */
    private array $innMatchedIssuerIds = [];

    /** @var array<string, string|null> card_url => ИНН|null — не ходим на одну и ту же карточку дважды за прогон */
    private array $innCache = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
        private readonly ExpertRaClient $client,
        private readonly int $delayMicroseconds = 400_000,
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
     * Обходит все категории сайта + резолвит ИНН по карточке каждой
     * компании, возвращает нормализованный снимок "сейчас" — БЕЗ записи
     * в БД. Побочный эффект: заполняет те же счётчики, что и раньше
     * заполнял import() — printReport() работает одинаково что для
     * import(), что при вызове только этого метода.
     *
     * @return array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, matched_by_root_name: bool}>
     */
    public function fetchSnapshot(): array
    {
        $snapshot = [];
        foreach (self::CATEGORIES as $slug => $label) {
            Logger::info("Эксперт РА: категория «{$label}» ({$slug})");
            $rows = $this->client->fetchCategoryRows($slug, $this->delayMicroseconds);
            Logger::info('  строк в категории: ' . count($rows));

            foreach ($rows as $row) {
                $parsed = $this->parseRow($row);
                if ($parsed !== null) {
                    $snapshot[] = $parsed;
                }
            }
        }

        return $snapshot;
    }

    /**
     * @param array{name: string, card_url: string, rating: string, outlook: string, date: string} $row
     * @return array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, matched_by_root_name: bool}|null
     */
    private function parseRow(array $row): ?array
    {
        $this->totalRows++;

        $inn = $this->resolveInn($row['card_url']);

        ['issuerId' => $issuerId, 'matchedByRoot' => $matchedByRoot, 'skippedPriorityConflict' => $skippedPriorityConflict]
            = $this->resolveIssuerIdWithPriority($inn, $row['name']);

        if ($skippedPriorityConflict) {
            $this->skippedRootPriorityConflict++;
            $this->unmatchedNames[] = "{$row['name']} (ИНН=" . ($inn ?? '—') . ') — root-совпадение проигнорировано: issuer_id уже сопоставлен напрямую по ИНН в этом же прогоне';
            return null;
        }
        if ($issuerId === null) {
            $this->unmatchedNoIssuer++;
            $this->unmatchedNames[] = "{$row['name']} (ИНН=" . ($inn ?? '—') . ')';
            return null;
        }

        $date = RatingsNormalizer::parseDate($row['date']);
        if ($date === null) {
            $this->skippedNoDate++;
            return null;
        }

        $this->matched++;
        if ($matchedByRoot) {
            $this->matchedByRoot++;
            $this->rootMatchNotices[] = "Эксперт РА (полная сверка): «{$row['name']}» → issuer_id={$issuerId} (сопоставлено по корню названия, проверьте)";
        }

        $rating = mb_substr(trim($row['rating']), 0, 20);
        // Дефолтному грейду ("D"/"SD") прогноз не положен в принципе — по
        // прямому запросу пользователя (найдено вживую на ООО «ЛКХ», НКР),
        // см. RatingsNormalizer::isDefaultGrade(). Защитная сетка для
        // полной сверки — основной случай для новостей идёт через
        // CurrentRatingsSync::sync().
        $outlook = RatingsNormalizer::isDefaultGrade($rating) ? null : RatingsNormalizer::mapOutlook($row['outlook']);

        return [
            'issuer_id' => $issuerId,
            'issuer_name' => $row['name'],
            'rating' => $rating,
            'outlook' => $outlook,
            'last_action_date' => $date,
            'matched_by_root_name' => $matchedByRoot,
        ];
    }

    /**
     * Приоритет прямого сопоставления (ИНН, либо явная ручная связка
     * issuer_spv_links — миграция 023) НАД корнем — см. подробное
     * объяснение в NkrImporter::resolveIssuerIdWithPriority() (тот же
     * приём, вынесено отдельным методом ради офлайн-теста без завязки на
     * MySQL-диалект INSERT, см. tests/test_root_priority_conflict.php).
     *
     * @return array{issuerId: ?int, matchedByRoot: bool, skippedPriorityConflict: bool}
     */
    private function resolveIssuerIdWithPriority(?string $inn, string $companyName): array
    {
        $issuerId = $inn !== null ? $this->matcher->findIssuerIdByInn($inn) : null;
        if ($issuerId === null && $inn !== null) {
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
            'agency' => self::AGENCY,
            'rating' => $row['rating'],
            'outlook' => $row['outlook'],
            'last_action_date' => $row['last_action_date'],
            'matched_by_root_name' => $row['matched_by_root_name'] ? 1 : 0,
        ]);
    }

    private function resolveInn(string $cardUrl): ?string
    {
        if (array_key_exists($cardUrl, $this->innCache)) {
            return $this->innCache[$cardUrl];
        }

        usleep($this->delayMicroseconds);

        try {
            $rawInn = $this->client->fetchCompanyInn($cardUrl);
        } catch (Throwable $e) {
            $this->cardFetchFailed++;
            Logger::warn("Эксперт РА: не удалось получить карточку {$cardUrl}: {$e->getMessage()}");
            $this->innCache[$cardUrl] = null;
            return null;
        }

        $inn = IssuerMatcher::normalizeInn($rawInn);
        if ($inn === null) {
            $this->noInnOnCard++;
        }

        $this->innCache[$cardUrl] = $inn;
        return $inn;
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту current_ratings (Эксперт РА) ===');
        Logger::info("Строк обработано (по всем категориям): {$this->totalRows}");
        Logger::info('Уникальных карточек компаний запрошено: ' . count($this->innCache));
        Logger::info("  - карточка не открылась (ошибка сети/HTTP): {$this->cardFetchFailed}");
        Logger::info("  - карточка открылась, но ИНН на ней нет (иностранное юрлицо и т.п.): {$this->noInnOnCard}");
        Logger::info("Сопоставлено с issuers и записано: {$this->matched} (из них по корню названия SPV/материнская компания: {$this->matchedByRoot})");
        Logger::info("Root-совпадений проигнорировано из-за приоритета прямого сопоставления в этом же прогоне: {$this->skippedRootPriorityConflict}");
        Logger::info("Не сопоставлено (ИНН есть, но такого issuers.inn нет в базе): {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
