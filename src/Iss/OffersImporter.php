<?php

declare(strict_types=1);

namespace BondKeeper\Iss;

use BondKeeper\Support\Logger;
use PDO;

/**
 * Переписано 14 сентября 2026 — по итогам отдельного расследования
 * (задача "почти нет call-оферт", начатая ранее в этом же этапе): исходная
 * версия опиралась ТОЛЬКО на доска-специфичный эндпоинт
 * (.../boards/{board}/securities/{secid}.json, поля OFFERDATE/BUYBACKDATE) —
 * он отдаёт для каждой бумаги не полный график оферт, а как будто снимок
 * "ближайшая оферта на сегодня", и на части бумаг (пример живьём —
 * RU000A10B313, «Брусника 002Р-05») эта дата попросту не заполняется, хотя
 * оферта у бумаги реально есть и видна в другом эндпоинте в тот же день.
 *
 * Найден более полный бесплатный источник — тот же bondization-эндпоинт,
 * который уже используется для купонов/амортизаций
 * (BondizationImporter), с iss.only=offers:
 *   /iss/statistics/engines/stock/markets/bonds/bondization/{ISIN}.json?iss.only=offers
 * Отдаёт блок "offers" с колонками isin, offerdate, offertype и др. — по
 * каждой бумаге может быть несколько строк (история: прошлые оферты,
 * отменённые, с разными исходами), а не одна дата "на сегодня".
 *
 * offertype — реально встречающиеся значения (сверено вживую на выборке
 * bondkeeper.ru, 14 сентября 2026, iss.moex.com):
 *   'Оферта', 'Оферта/Погашение'                         — актуальна/предстоит
 *   'Оферта (состоялось)', 'Оферта/Погашение (состоялось)' — уже прошла
 *   'Оферта (отменено)'                                    — не состоится
 *   'Оферта (дефолт)', 'Оферта (технический дефолт)'       — не исполнена эмитентом
 * Берём в offers только предстоящие (см. ACTIONABLE_OFFER_TYPES) и только с
 * датой не в прошлом — остальные типы либо уже случились, либо не
 * случатся, отдельная история этих исходов здесь не нужна (offers — это
 * "что предстоит", не журнал).
 *
 * 'Оферта/Погашение' включена в предстоящие наравне с чистой 'Оферта' —
 * проверено на выборке: если у такой записи реальная (не "0000-00-00")
 * будущая дата, эта дата — настоящее инвестиционно значимое событие (право
 * предъявить бумагу к выкупу), суффикс "/Погашение" по всей видимости
 * означает лишь "если офертой не воспользоваться — далее бумага идёт к
 * погашению", а не "это не оферта". Отдельного признака put/call у этого
 * эндпоинта нет вообще ни для одного из 7 значений offertype.
 *
 * put/call и признак BUYBACKDATE bondization/offers НЕ отдаёт — для них
 * по-прежнему нужен доска-специфичный эндпоинт (PUTOPTIONDATE/
 * CALLOPTIONDATE/BUYBACKDATE), но теперь он запрашивается ВТОРЫМ шагом,
 * только для бумаг, где bondization/offers уже подтвердил предстоящую
 * оферту — а не для всех активных бумаг подряд, как раньше. Это не только
 * точнее (полнота дат), но и дешевле по числу HTTP-запросов на бумагах без
 * оферты вообще (подавляющее большинство рынка).
 *
 * Настоящий бесплатный put/call-типизатор в природе не существует:
 * сверено с providers-страницей bondana.app (https://bondana.app/providers,
 * доступ открыт пользователем для этой сверки) — там прямо указано, что
 * даты И тип (Call/Put) оферт у них идут от Cbonds, платного источника.
 * Так что 'unknown' по offer_type там, где put/call не удалось определить
 * из PUTOPTIONDATE/CALLOPTIONDATE — не пробел в реализации, а честный
 * предел бесплатных данных.
 */
final class OffersImporter
{
    private const ACTIONABLE_OFFER_TYPES = ['Оферта', 'Оферта/Погашение'];

    private int $checked = 0;
    private int $foundOffers = 0;
    private int $boardLookupMissing = 0;
    private int $hasBuybackDate = 0;
    private int $offerTypeResolved = 0;
    private int $failed = 0;

    public function __construct(
        private readonly IssClient $iss,
        private readonly PDO $db,
    ) {
    }

    public function importForAllActive(): void
    {
        $stmt = $this->db->query(
            "SELECT id, issuer_id, isin, secid, moex_board FROM securities
             WHERE status = 'active' AND secid IS NOT NULL AND moex_board IS NOT NULL"
        );
        $securities = $stmt->fetchAll();

        Logger::info('Обнаружено активных бумаг для проверки оферты: ' . count($securities));

        foreach ($securities as $row) {
            $this->checked++;
            try {
                $this->importOne(
                    (int) $row['id'],
                    (int) $row['issuer_id'],
                    (string) $row['isin'],
                    (string) $row['secid'],
                    (string) $row['moex_board']
                );
            } catch (\Throwable $e) {
                $this->failed++;
                Logger::warn("Оферта: пропущена securities.id={$row['id']}: {$e->getMessage()}");
            }
        }

        $this->printReport();
    }

    private function importOne(int $securityId, int $issuerId, string $isin, string $secid, string $board): void
    {
        $response = $this->iss->getJson(
            "/statistics/engines/stock/markets/bonds/bondization/{$isin}.json",
            ['iss.only' => 'offers']
        );
        $offerRows = IssClient::block($response, 'offers');

        $offerDate = $this->resolveUpcomingOfferDate($offerRows);
        if ($offerDate === null) {
            return;
        }

        $this->foundOffers++;

        // Второй запрос — только теперь, когда предстоящая оферта уже
        // подтверждена: put/call и BUYBACKDATE в bondization/offers не
        // приходят вообще, единственный источник — доска-специфичный
        // эндпоинт (тот же, что раньше был единственным шагом).
        $boardResponse = $this->iss->getJson(
            "/engines/stock/markets/bonds/boards/{$board}/securities/{$secid}.json",
            ['iss.only' => 'securities']
        );
        $boardRows = IssClient::block($boardResponse, 'securities');
        $boardRow = $boardRows[0] ?? null;

        if ($boardRow === null) {
            $this->boardLookupMissing++;
        }

        $hasBuybackDate = $boardRow !== null && $this->nullableDate($boardRow['BUYBACKDATE'] ?? null) !== null;
        if ($hasBuybackDate) {
            $this->hasBuybackDate++;
        }

        $offerType = $boardRow !== null ? $this->resolveOfferType($boardRow) : 'unknown';
        if ($offerType !== 'unknown') {
            $this->offerTypeResolved++;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO offers (security_id, issuer_id, execution_date_planned, has_buyback_date, offer_type)
             VALUES (:security_id, :issuer_id, :execution_date_planned, :has_buyback_date, :offer_type)
             ON DUPLICATE KEY UPDATE
                execution_date_planned = VALUES(execution_date_planned),
                has_buyback_date = VALUES(has_buyback_date),
                offer_type = VALUES(offer_type),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'security_id' => $securityId,
            'issuer_id' => $issuerId,
            'execution_date_planned' => $offerDate,
            'has_buyback_date' => (int) $hasBuybackDate,
            'offer_type' => $offerType,
        ]);
    }

    /**
     * Из всех строк bondization/offers для бумаги выбирает ближайшую
     * будущую (или сегодняшнюю) дату среди "предстоящих" типов оферты —
     * см. ACTIONABLE_OFFER_TYPES и докблок класса. У бумаги может быть
     * несколько строк истории (отменённые, уже состоявшиеся) — они
     * отбрасываются здесь же, до похода на доска-специфичный эндпоинт.
     *
     * @param array<int, array<string, mixed>> $offerRows
     */
    private function resolveUpcomingOfferDate(array $offerRows): ?string
    {
        $today = date('Y-m-d');
        $best = null;

        foreach ($offerRows as $row) {
            $type = trim((string) ($row['offertype'] ?? ''));
            if (!in_array($type, self::ACTIONABLE_OFFER_TYPES, true)) {
                continue;
            }

            $date = $this->nullableDate($row['offerdate'] ?? null);
            if ($date === null || $date < $today) {
                continue;
            }

            if ($best === null || $date < $best) {
                $best = $date;
            }
        }

        return $best;
    }

    /** @param array<string, mixed> $row */
    private function resolveOfferType(array $row): string
    {
        $hasPut = $this->nullableDate($row['PUTOPTIONDATE'] ?? null) !== null;
        $hasCall = $this->nullableDate($row['CALLOPTIONDATE'] ?? null) !== null;

        if ($hasPut && !$hasCall) {
            return 'put';
        }
        if ($hasCall && !$hasPut) {
            return 'call';
        }

        return 'unknown';
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            return null;
        }

        return (string) $value;
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту оферт (offers) ===');
        Logger::info("Бумаг проверено: {$this->checked}");
        Logger::info("Найдено предстоящих оферт (bondization/offers): {$this->foundOffers}");
        Logger::info("  - из них с признаком BUYBACKDATE (доска-эндпоинт): {$this->hasBuybackDate}");
        Logger::info("  - из них вид оферты определён (PUTOPTIONDATE/CALLOPTIONDATE): {$this->offerTypeResolved}");
        Logger::info("  - доска-эндпоинт не вернул строку securities: {$this->boardLookupMissing}");
        Logger::info("Ошибок: {$this->failed}");
    }
}
