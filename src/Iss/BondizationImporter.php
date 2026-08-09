<?php

declare(strict_types=1);

namespace BondKeeper\Iss;

use BondKeeper\Support\Logger;
use PDO;

/**
 * Наполняет coupons/amortizations через bondization-эндпоинт ISS API —
 * второй бесплатный источник, покрывающий график выплат целиком (плановые
 * и уже прошедшие купоны/амортизации), в отличие от description-блока,
 * который даёт только текущий купон и дату ближайшей оферты/погашения.
 *
 * По умолчанию запускается по бумагам, у которых ещё нет ни одной строки
 * в coupons ("ещё не сеяли график") — дёшево для ежедневного cron, не
 * трогает уже загруженные бумаги. $forceAll=true обрабатывает вообще все
 * активные бумаги заново поверх уже существующих строк (апсерт) — нужен
 * для разового backfill, когда меняется сама логика маппинга полей (так
 * нашли и чинили путаницу rate_percent/valueprc, см. коммит), а не только
 * для новых бумаг.
 */
final class BondizationImporter
{
    private int $processed = 0;
    private int $couponsImported = 0;
    private int $amortizationsImported = 0;
    private int $failed = 0;

    public function __construct(
        private readonly IssClient $iss,
        private readonly PDO $db,
    ) {
    }

    public function importForAllPending(bool $forceAll = false): void
    {
        $sql = 'SELECT s.id, s.isin, s.issuer_id FROM securities s';
        $sql .= $forceAll
            ? ' WHERE s.status = "active"'
            : ' LEFT JOIN coupons c ON c.security_id = s.id WHERE c.id IS NULL AND s.status = "active"';

        $stmt = $this->db->query($sql);

        while ($row = $stmt->fetch()) {
            $this->processed++;
            try {
                $this->importOne((int) $row['id'], (string) $row['isin'], (int) $row['issuer_id']);
            } catch (\Throwable $e) {
                $this->failed++;
                Logger::warn("Пропущен график выплат для {$row['isin']}: {$e->getMessage()}");
            }
        }

        Logger::info('=== Отчёт по импорту графика выплат (bondization) ===');
        Logger::info("Бумаг обработано: {$this->processed}");
        Logger::info("Купонов импортировано: {$this->couponsImported}");
        Logger::info("Амортизаций импортировано: {$this->amortizationsImported}");
        Logger::info("Ошибок: {$this->failed}");
    }

    private function importOne(int $securityId, string $isin, int $issuerId): void
    {
        $response = $this->iss->getJson(
            "/statistics/engines/stock/markets/bonds/bondization/{$isin}.json",
            ['iss.only' => 'coupons,amortizations']
        );

        $coupons = IssClient::block($response, 'coupons');
        $amortizations = IssClient::block($response, 'amortizations');

        usort($coupons, static fn ($a, $b) => strcmp((string) ($a['coupondate'] ?? ''), (string) ($b['coupondate'] ?? '')));

        $couponNumber = 0;
        foreach ($coupons as $coupon) {
            $couponDate = $coupon['coupondate'] ?? null;
            $couponValue = $coupon['value'] ?? null;
            if ($couponDate === null || $couponValue === null) {
                continue;
            }
            $couponNumber++;

            $stmt = $this->db->prepare(
                'INSERT INTO coupons (security_id, issuer_id, coupon_number, period_start_date, period_end_date, rate_percent, value_per_bond)
                 VALUES (:security_id, :issuer_id, :coupon_number, :period_start, :period_end, :rate, :value)
                 ON DUPLICATE KEY UPDATE
                    period_start_date = VALUES(period_start_date),
                    rate_percent = VALUES(rate_percent),
                    value_per_bond = VALUES(value_per_bond)'
            );
            $stmt->execute([
                'security_id' => $securityId,
                'issuer_id' => $issuerId,
                'coupon_number' => $couponNumber,
                'period_start' => $coupon['startdate'] ?? null,
                'period_end' => $couponDate,
                // Реальное имя поля со ставкой купона в bondization-ответе —
                // valueprc (проверено на bondkeeper.ru: 'value'=16.44 руб.,
                // 'valueprc'=20 — то есть 20% годовых). Поля 'couponpercent'
                // в этом эндпоинте не существует вообще — раньше здесь была
                // ошибка, из-за которой rate_percent писался NULL у всех
                // 35708 купонов первого прогона.
                'rate' => $coupon['valueprc'] ?? null,
                'value' => $couponValue,
            ]);
            $this->couponsImported++;
        }

        foreach ($amortizations as $amortization) {
            $amortDate = $amortization['amortdate'] ?? null;
            $amortValue = $amortization['value'] ?? null;
            if ($amortDate === null || $amortValue === null) {
                continue;
            }

            $stmt = $this->db->prepare(
                'INSERT INTO amortizations (security_id, issuer_id, payment_date_planned, value_per_bond, percent_of_nominal)
                 VALUES (:security_id, :issuer_id, :payment_date, :value, :percent)
                 ON DUPLICATE KEY UPDATE
                    value_per_bond = VALUES(value_per_bond),
                    percent_of_nominal = VALUES(percent_of_nominal)'
            );
            $stmt->execute([
                'security_id' => $securityId,
                'issuer_id' => $issuerId,
                'payment_date' => $amortDate,
                'value' => $amortValue,
                'percent' => $amortization['valueprc'] ?? null,
            ]);
            $this->amortizationsImported++;
        }

        // Если у бумаги была амортизация, плановая сумма scheduled_maturity
        // должна быть остатком номинала, а не полным номиналом, который
        // проставил SecuritiesImporter — досчитываем здесь, когда график
        // уже известен.
        if ($amortizations !== []) {
            $this->adjustScheduledRedemption($securityId, $amortizations);
        }
    }

    /**
     * Пересчитывает от исходного номинала (securities.nominal_value), а не
     * вычитает из текущего value_per_bond — так пересчёт идемпотентен и
     * не зависит от того, запускался ли он раньше для этой бумаги.
     *
     * @param array<int, array<string, mixed>> $amortizations
     */
    private function adjustScheduledRedemption(int $securityId, array $amortizations): void
    {
        $totalAmortized = array_sum(array_map(
            static fn ($row) => (float) ($row['value'] ?? 0),
            $amortizations
        ));

        $this->db->prepare(
            "UPDATE redemptions r
             JOIN securities s ON s.id = r.security_id
             SET r.value_per_bond = s.nominal_value - :amortized
             WHERE r.security_id = :security_id
               AND r.redemption_type = 'scheduled_maturity'"
        )->execute([
            'amortized' => $totalAmortized,
            'security_id' => $securityId,
        ]);
    }
}
