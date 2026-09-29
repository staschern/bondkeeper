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
 * === fetchSnapshot() / applySnapshot() — сверка и перезапись (сентябрь 2026) ===
 *
 * fetchSnapshot() обходит категории и резолвит ИНН, current_ratings не
 * трогает — его использует и сверка (CurrentRatingsReconciler через
 * bin/reconcile_ratings.php), и перезапись. applySnapshot() пишет снимок
 * — свежий (import()) или проверенный на сверке и сохранённый в файл
 * (bin/seed_ratings.php --agency=expert_ra --snapshot=ФАЙЛ, П2: сначала
 * сверка, потом перезапись, без второго часового обхода сайта).
 *
 * === Одна строка на эмитента (П1) ===
 *
 * Одна компания встречается в нескольких категориях, в том числе в
 * архиве старой категории после смены методологии (АФК Система, ПКБ,
 * Магистраль двух столиц, ТРАНСФИН-М, Ситиматик, Россиум). Раньше
 * побеждала категория, обработанная последней; теперь —
 * SnapshotRows::latestPerIssuer(), самая свежая дата.
 *
 * === Сопоставление по названию — только после подтверждения (миграция 024) ===
 *
 * ИНН с карточки → явная связка issuer_spv_links (миграция 023) →
 * NameMatchResolver. Совпадение по названию без подтверждения
 * администратора в current_ratings не пишется — это предложение
 * (issuer_name_match_reviews). Живой случай: ООО «Озон» (фармацевтика)
 * получила "по корню" рейтинг ОЗОН Капитала. Дефолтному грейду
 * ("D"/"SD") прогноз не положен — см. RatingsNormalizer::isDefaultGrade().
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
    private int $matchedBySpvLink = 0;
    private int $matchedByApprovedName = 0;
    private int $proposedByName = 0;
    private int $collapsedDuplicates = 0;
    private int $unmatchedNoIssuer = 0;
    /** @var array<int, string> */
    private array $unmatchedNames = [];
    /** @var array<int, true> issuer_id => уже сопоставлен НАПРЯМУЮ по ИНН/явной связке в ЭТОМ прогоне — по названию его не предлагаем */
    private array $innMatchedIssuerIds = [];

    /** @var array<string, string|null> card_url => ИНН|null — не ходим на одну и ту же карточку дважды за прогон */
    private array $innCache = [];
    /** @var array<string, true> card_url, карточка которых не открылась (сеть/HTTP) */
    private array $failedCards = [];

    public function __construct(
        private readonly PDO $db,
        private readonly IssuerMatcher $matcher,
        private readonly ExpertRaClient $client,
        private readonly NameMatchResolver $nameResolver,
        private readonly int $delayMicroseconds = 400_000,
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
        Logger::info("Эксперт РА: записано строк current_ratings (source='snapshot'): {$written}");
    }

    /**
     * Обходит все категории сайта + резолвит ИНН по карточке каждой
     * компании, возвращает нормализованный снимок "сейчас" — current_ratings
     * не трогает (пишутся только новые предложения сопоставления по
     * названию). Одна строка на эмитента (П1).
     *
     * @return array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, source_url: ?string}>
     */
    public function fetchSnapshot(): array
    {
        $snapshot = [];
        foreach (self::CATEGORIES as $slug => $label) {
            Logger::info("Эксперт РА: категория «{$label}» ({$slug})");
            $rows = $this->client->fetchCategoryRows($slug, $this->delayMicroseconds);
            Logger::info('  строк в категории: ' . count($rows));

            foreach ($rows as $row) {
                $parsed = $this->parseRow($row, $label);
                if ($parsed !== null) {
                    $snapshot[] = $parsed;
                }
            }
        }

        $unique = SnapshotRows::latestPerIssuer($snapshot);
        $this->collapsedDuplicates = count($snapshot) - count($unique);

        return $unique;
    }

    /**
     * @param array{name: string, card_url: string, rating: string, outlook: string, date: string} $row
     * @return array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string, source_url: ?string}|null
     */
    private function parseRow(array $row, string $categoryLabel): ?array
    {
        $this->totalRows++;

        $inn = $this->resolveInn($row['card_url']);
        $rating = RatingsNormalizer::normalizeGrade(RatingsNormalizer::normalizeWithdrawnRatingText($row['rating']));
        $sourceTitle = self::describeRow($row, $rating, $categoryLabel);

        $issuerId = $this->resolveIssuerId($inn, $row['name'], $sourceTitle, $row['card_url'], isset($this->failedCards[$row['card_url']]));
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

        // Дефолтному грейду ("D"/"SD") прогноз не положен в принципе — по
        // прямому запросу пользователя (найдено вживую на ООО «ЛКХ», НКР),
        // см. RatingsNormalizer::isDefaultGrade(). Защитная сетка для
        // полной сверки — основной случай для новостей идёт через
        // CurrentRatingsSync::sync().
        $outlook = RatingsNormalizer::outlookForRating($rating, RatingsNormalizer::mapOutlook($row['outlook']));

        return [
            'issuer_id' => $issuerId,
            'issuer_name' => $row['name'],
            'rating' => $rating,
            'outlook' => $outlook,
            'last_action_date' => $date,
            'source_url' => $row['card_url'] !== '' ? $row['card_url'] : null,
        ];
    }

    /**
     * ИНН → явная связка issuer_spv_links → NameMatchResolver (только
     * подтверждённое; иначе — предложение администратору). См.
     * NkrImporter::resolveIssuerId(). Если карточка не открылась, ИНН мы
     * просто не увидели — тогда только уже подтверждённое название, без
     * новых предложений.
     */
    private function resolveIssuerId(?string $inn, string $companyName, ?string $sourceTitle, ?string $sourceUrl, bool $cardFailed): ?int
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

        if ($cardFailed) {
            $issuerId = $this->nameResolver->findApproved(null, [$companyName]);
        } else {
            $result = $this->nameResolver->resolve(self::AGENCY, $inn, [$companyName], $sourceTitle, $sourceUrl, $this->innMatchedIssuerIds);
            $this->proposedByName += $result['proposed'];
            $issuerId = $result['issuerId'];
        }
        if ($issuerId !== null) {
            $this->matchedByApprovedName++;
        }

        return $issuerId;
    }

    /**
     * Заголовок для предложения сопоставления: у строки списка нет
     * заголовка новости, поэтому описываем саму строку; ссылка — карточка
     * компании на raexpert.ru.
     *
     * @param array{name: string, card_url: string, rating: string, outlook: string, date: string} $row
     */
    private static function describeRow(array $row, string $rating, string $categoryLabel): string
    {
        $outlook = trim($row['outlook']);
        $date = trim($row['date']);

        return "Полная выгрузка Эксперт РА (категория «{$categoryLabel}»): {$row['name']}"
            . ' — рейтинг ' . ($rating !== '' ? $rating : '?')
            . ', прогноз ' . ($outlook !== '' ? $outlook : '—')
            . ', дата ' . ($date !== '' ? $date : '?');
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
            $this->failedCards[$cardUrl] = true;
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

    /** Отчёт о разборе сайта — после fetchSnapshot() (import() и сверка). */
    public function printReport(): void
    {
        Logger::info('=== Отчёт по current_ratings (Эксперт РА) ===');
        Logger::info("Строк обработано (по всем категориям): {$this->totalRows}");
        Logger::info('Уникальных карточек компаний запрошено: ' . count($this->innCache));
        Logger::info("  - карточка не открылась (ошибка сети/HTTP): {$this->cardFetchFailed}");
        Logger::info("  - карточка открылась, но ИНН на ней нет (иностранное юрлицо и т.п.): {$this->noInnOnCard}");
        Logger::info("Сопоставлено с issuers: {$this->matched} (по связке issuer_spv_links: {$this->matchedBySpvLink}, по подтверждённому названию: {$this->matchedByApprovedName})");
        Logger::info("Строк свёрнуто в одну на эмитента (дубли по категориям, самая свежая дата, П1): {$this->collapsedDuplicates}");
        Logger::info("Новых предложений сопоставления по названию (ждут подтверждения, bin/review_matches.php): {$this->proposedByName}");
        Logger::info("Не сопоставлено: {$this->unmatchedNoIssuer}");
        Logger::info("Пропущено (не распознана дата): {$this->skippedNoDate}");
        if ($this->unmatchedNames !== []) {
            Logger::info('Не сопоставленные эмитенты: ' . implode('; ', array_slice($this->unmatchedNames, 0, 30)));
        }
    }
}
