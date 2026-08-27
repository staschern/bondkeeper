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
    private int $unmatchedNoIssuer = 0;
    /** @var array<int, string> */
    private array $unmatchedNames = [];

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
        foreach (self::CATEGORIES as $slug => $label) {
            Logger::info("Эксперт РА: категория «{$label}» ({$slug})");
            $rows = $this->client->fetchCategoryRows($slug, $this->delayMicroseconds);
            Logger::info('  строк в категории: ' . count($rows));

            foreach ($rows as $row) {
                $this->importRow($row);
            }
        }

        $this->printReport();
    }

    /** @param array{name: string, card_url: string, rating: string, outlook: string, date: string} $row */
    private function importRow(array $row): void
    {
        $this->totalRows++;

        $inn = $this->resolveInn($row['card_url']);
        if ($inn === null) {
            return;
        }

        $issuerId = $this->matcher->findIssuerIdByInn($inn);
        if ($issuerId === null) {
            $this->unmatchedNoIssuer++;
            $this->unmatchedNames[] = "{$row['name']} (ИНН={$inn})";
            return;
        }

        $date = RatingsNormalizer::parseDate($row['date']);
        if ($date === null) {
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
            'agency' => self::AGENCY,
            'rating' => mb_substr(trim($row['rating']), 0, 20),
            'outlook' => RatingsNormalizer::mapOutlook($row['outlook']),
            'last_action_date' => $date,
        ]);

        $this->matched++;
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
        Logger::info("Сопоставлено с issuers и записано: {$this->matched}");
        Logger::info("Не сопоставлено (ИНН есть, но такого issuers.inn нет в базе): {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
