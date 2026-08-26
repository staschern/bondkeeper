<?php

declare(strict_types=1);

namespace BondKeeper\Iss;

use BondKeeper\Support\Logger;
use PDO;

/**
 * Наполняет offers через доска-специфичный эндпоинт ISS API
 * (/iss/engines/stock/markets/bonds/boards/{board}/securities/{secid}.json),
 * который до этого момента в проекте не запрашивался вообще — подтверждён
 * вживую (bondkeeper.ru, август 2026) через bin/debug_iss_security.php на
 * 3 бумагах перед тем, как писать этот импортёр.
 *
 * На всех трёх проверенных бумагах OFFERDATE и BUYBACKDATE (тот же сигнал,
 * что уже даёт securities.has_offer) совпали день в день. Выборка
 * маленькая, поэтому здесь ведётся честный подсчёт по трём группам (оба
 * сигнала / только BUYBACKDATE / только OFFERDATE) на каждом реальном
 * прогоне — а не предполагается, что они всегда совпадают. Строка в
 * offers создаётся, если есть ХОТЯ БЫ ОДИН из двух сигналов — объединение,
 * не пересечение (по прямому запросу пользователя).
 *
 * BUYBACKDATE в этом эндпоинте у бумаг без оферты приходит не как NULL, а
 * как технический "0000-00-00" — та же защита (nullableDate), что уже
 * была нужна в SecuritiesImporter для другого эндпоинта.
 *
 * offer_type — бонус-находка, не было в исходном плане (там должен был
 * остаться 'unknown'): PUTOPTIONDATE/CALLOPTIONDATE в этом же ответе на
 * обоих проверенных примерах с офертой заполнено ровно одно из двух —
 * второе NULL. Даёт put/call бесплатно, без похода на RusBonds. Если оба
 * поля пусты или оба заполнены (не встречалось на 3 примерах, но не
 * исключено на 3000+) — честно 'unknown', не гадаем.
 */
final class OffersImporter
{
    private int $checked = 0;
    private int $foundOffers = 0;
    private int $bothSignals = 0;
    private int $onlyBuybackDate = 0;
    private int $onlyOfferDate = 0;
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
            "SELECT id, issuer_id, secid, moex_board FROM securities
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

    private function importOne(int $securityId, int $issuerId, string $secid, string $board): void
    {
        $response = $this->iss->getJson(
            "/engines/stock/markets/bonds/boards/{$board}/securities/{$secid}.json",
            ['iss.only' => 'securities']
        );
        $rows = IssClient::block($response, 'securities');
        $row = $rows[0] ?? null;
        if ($row === null) {
            return;
        }

        $offerDate = $this->nullableDate($row['OFFERDATE'] ?? null);
        $hasBuybackDate = $this->nullableDate($row['BUYBACKDATE'] ?? null) !== null;
        $hasOfferDate = $offerDate !== null;

        if (!$hasBuybackDate && !$hasOfferDate) {
            return;
        }

        if ($hasBuybackDate && $hasOfferDate) {
            $this->bothSignals++;
        } elseif ($hasBuybackDate) {
            $this->onlyBuybackDate++;
        } else {
            $this->onlyOfferDate++;
        }

        $offerType = $this->resolveOfferType($row);
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

        $this->foundOffers++;
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
        Logger::info("Найдено оферт (объединение BUYBACKDATE/OFFERDATE): {$this->foundOffers}");
        Logger::info("  - оба сигнала совпадают: {$this->bothSignals}");
        Logger::info("  - только BUYBACKDATE (нет OFFERDATE): {$this->onlyBuybackDate}");
        Logger::info("  - только OFFERDATE (нет BUYBACKDATE): {$this->onlyOfferDate}");
        Logger::info("Вид оферты определён (PUTOPTIONDATE/CALLOPTIONDATE): {$this->offerTypeResolved}");
        Logger::info("Ошибок: {$this->failed}");
    }
}
