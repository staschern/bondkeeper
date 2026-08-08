<?php

declare(strict_types=1);

namespace BondKeeper\Iss;

use BondKeeper\Support\Logger;
use PDO;

/**
 * Наполняет issuers/securities/redemptions(scheduled_maturity) из бесплатного
 * ISS API Мосбиржи — без обращения к платным источникам (НРД, e-disclosure).
 * Это прямая реализация шага 1 дорожной карты: "заполнить первые таблицы
 * бесплатными данными".
 *
 * Помимо импорта, класс ведёт статистику по каждому полю схемы — сколько
 * записей удалось заполнить, а сколько пришлось пропустить из-за отсутствия
 * данных в бесплатном источнике. Это статистика и есть ответ на вопрос
 * "всё ли можно вытащить" — а не предположение, а факт, полученный по
 * итогам реального прогона (см. README.md, "Как читать отчёт после прогона").
 *
 * Маппинг полей ниже проверен на реальном ответе ISS API по нескольким
 * бумагам (bondkeeper.ru, август 2026), не на предположениях — см.
 * database/002_issuer_moex_emitter_id.sql для истории находки про ИНН.
 */
final class SecuritiesImporter
{
    private int $securitiesSeen = 0;
    private int $securitiesImported = 0;
    private int $skippedNoEmitterId = 0;
    private int $skippedOther = 0;

    /** @var array<string, int> поле схемы => сколько раз осталось NULL из-за отсутствия в ISS-ответе */
    private array $missingFieldCounts = [];

    public function __construct(
        private readonly IssClient $iss,
        private readonly PDO $db,
    ) {
    }

    /**
     * Точка входа: обходит все доски рынка облигаций, для каждой найденной
     * бумаги подтягивает справочные параметры и апсертит issuer+security.
     *
     * Доски (boardid) для рынка облигаций на MOEX ISS: TQCB/TQIR — корп.
     * облигации в режиме Т+; TQOB — ОФЗ; EQOB/EQIR/PSBB — прочие режимы,
     * куда бумаги попадают эпизодически. Список досок стоит свериться
     * с реальным ответом /iss/engines/stock/markets/bonds/boards.json —
     * см. README, этот список задан по общим конвенциям ISS API и не
     * проверен вживую в этой среде разработки.
     *
     * @param string[] $boards
     */
    public function importMarket(array $boards = ['TQCB', 'TQIR', 'TQOB']): void
    {
        $isinList = $this->discoverIsinList($boards);
        Logger::info('Обнаружено бумаг для импорта: ' . count($isinList));

        foreach ($isinList as $isin) {
            $this->securitiesSeen++;
            try {
                $this->importOne($isin);
            } catch (\Throwable $e) {
                $this->skippedOther++;
                Logger::warn("Пропущен {$isin}: {$e->getMessage()}");
            }
        }

        $this->printReport();
    }

    /**
     * @param string[] $boards
     * @return string[] уникальный список ISIN
     */
    private function discoverIsinList(array $boards): array
    {
        $isins = [];

        foreach ($boards as $board) {
            $start = 0;
            do {
                $response = $this->iss->getJson(
                    "/engines/stock/markets/bonds/boards/{$board}/securities.json",
                    [
                        'iss.only' => 'securities',
                        'securities.columns' => 'SECID,ISIN,SHORTNAME,STATUS',
                        'start' => $start,
                    ]
                );
                $rows = IssClient::block($response, 'securities');

                foreach ($rows as $row) {
                    $isin = trim((string) ($row['ISIN'] ?? ''));
                    // STATUS='A' — бумага активно торгуется; остальные статусы
                    // (погашена, приостановлена и т.п.) на этапе 1 пропускаем —
                    // историю по ним можно досеять отдельным прогоном позже.
                    if ($isin !== '' && ($row['STATUS'] ?? null) === 'A') {
                        $isins[$isin] = true;
                    }
                }

                $start += 100;
            } while (count($rows) === 100);
        }

        return array_keys($isins);
    }

    private function importOne(string $isin): void
    {
        $response = $this->iss->getJson("/securities/{$isin}.json", [
            'iss.only' => 'description,boards',
        ]);

        $description = $this->descriptionMap(IssClient::block($response, 'description'));
        $boards = IssClient::block($response, 'boards');
        $primaryBoard = $this->pickPrimaryBoard($boards);

        $issuerId = $this->resolveOrCreateIssuer($description);
        if ($issuerId === null) {
            $this->skippedNoEmitterId++;
            Logger::warn("{$isin}: в description нет даже EMITTER_ID — бумага пропущена (см. отчёт)");
            return;
        }

        $securityId = $this->upsertSecurity($isin, $issuerId, $description, $primaryBoard);
        $this->upsertScheduledRedemption($securityId, $issuerId, $description);

        $this->securitiesImported++;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed> имя_поля => значение
     */
    private function descriptionMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (isset($row['name'])) {
                $map[$row['name']] = $row['value'] ?? null;
            }
        }
        return $map;
    }

    /** @param array<int, array<string, mixed>> $boards */
    private function pickPrimaryBoard(array $boards): ?array
    {
        foreach ($boards as $board) {
            if (($board['is_primary'] ?? $board['IS_PRIMARY'] ?? null) == 1) {
                return $board;
            }
        }
        return $boards[0] ?? null;
    }

    /**
     * ГЛАВНЫЙ ВЫВОД шага "проверить всё ли можно вытащить" (проверено на
     * реальном ответе ISS API, а не в теории — см. database/002_...sql):
     * ИНН эмитента (issuers.inn) ISS API не отдаёт ни в одном подтверждённом
     * ресурсе — ни в description бумаги, ни через /iss/emitents (такого
     * ресурса в ISS API нет вообще: /iss/index.json перечисляет ровно 8
     * групп ресурсов, эмитентов среди них нет).
     *
     * Единственное, что ISS даёт стабильно, — EMITTER_ID: внутренний
     * числовой ID эмитента на Мосбирже, одинаковый у всех выпусков одной
     * компании. Используем его как естественный ключ эмитента прямо
     * сейчас, а inn оставляем NULL — это не потеря данных, а честная
     * граница того, что бесплатно достаёт именно ISS API. Список на
     * дообогащение ИНН — `SELECT * FROM issuers WHERE inn IS NULL`.
     *
     * short_name/full_name здесь — название САМОЙ БУМАГИ (SHORTNAME/NAME),
     * а не выверенное юридическое наименование компании; для первого
     * найденного выпуска эмитента оно фиксируется и дальше не переписывается
     * последующими выпусками той же компании (см. ON DUPLICATE KEY UPDATE
     * ниже — обновляется только updated_at).
     *
     * @param array<string, mixed> $description
     */
    private function resolveOrCreateIssuer(array $description): ?int
    {
        $emitterId = $this->firstPresent($description, ['EMITTER_ID']);
        if ($emitterId === null) {
            $this->recordMissing('issuers.moex_emitter_id');
            return null;
        }

        $shortName = $this->firstPresent($description, ['SHORTNAME'])
            ?? $this->firstPresent($description, ['NAME'])
            ?? "MOEX-{$emitterId}";

        $this->recordMissing('issuers.inn (ISS API не отдаёт — нужно дообогащение отдельным источником)');

        $stmt = $this->db->prepare(
            'INSERT INTO issuers (moex_emitter_id, full_name, short_name)
             VALUES (:emitter_id, :full_name, :short_name)
             ON DUPLICATE KEY UPDATE
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'emitter_id' => (int) $emitterId,
            'full_name' => $shortName,
            'short_name' => $shortName,
        ]);

        $idStmt = $this->db->prepare('SELECT id FROM issuers WHERE moex_emitter_id = :emitter_id');
        $idStmt->execute(['emitter_id' => (int) $emitterId]);
        return (int) $idStmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $description
     * @param array<string, mixed>|null $primaryBoard
     */
    private function upsertSecurity(string $isin, int $issuerId, array $description, ?array $primaryBoard): int
    {
        $secid = $this->firstPresent($description, ['SECID']) ?? $primaryBoard['boardid'] ?? $primaryBoard['SECID'] ?? null;
        $regNumber = $this->firstPresent($description, ['REGNUMBER']);
        $shortName = $this->firstPresent($description, ['SHORTNAME']) ?? $isin;
        $fullName = $this->firstPresent($description, ['NAME']);
        $nominal = $this->firstPresent($description, ['FACEVALUE', 'INITIALFACEVALUE']);
        $currency = $this->normalizeCurrency($this->firstPresent($description, ['FACEUNIT']));
        $maturityDate = $this->nullableDate($this->firstPresent($description, ['MATDATE']));
        $issueVolume = $this->firstPresent($description, ['ISSUESIZE', 'ISSUESIZEPLACED']);
        $listingLevel = $this->firstPresent($description, ['LISTLEVEL']);
        $moexBoard = $primaryBoard['boardid'] ?? $primaryBoard['BOARDID'] ?? null;

        $isQualifiedOnly = $this->firstPresent($description, ['ISQUALIFIEDINVESTORS']);
        $couponFrequency = $this->mapCouponFrequency($this->firstPresent($description, ['COUPONFREQUENCY']));
        $couponType = $this->mapCouponType($this->firstPresent($description, ['BOND_TYPE']));

        if ($nominal === null) {
            $this->recordMissing('securities.nominal_value');
        }
        if ($isQualifiedOnly === null) {
            $this->recordMissing('securities.is_qualified_investors_only');
        }
        foreach (['is_subordinated', 'is_structured'] as $uncertainField) {
            // По-прежнему не найдено подтверждённого поля в ISS API — в
            // отличие от is_qualified_investors_only/coupon_type/
            // coupon_frequency, которые в этой ревизии уже замаплены на
            // реальные поля (ISQUALIFIEDINVESTORS/BOND_TYPE/COUPONFREQUENCY).
            $this->recordMissing("securities.{$uncertainField} (поле в ISS API не найдено)");
        }

        $stmt = $this->db->prepare(
            'INSERT INTO securities
                (isin, secid, reg_number, issuer_id, short_name, full_name,
                 nominal_value, currency, maturity_date, initial_issue_volume,
                 listing_level, moex_board, coupon_type, coupon_frequency,
                 is_qualified_investors_only, last_synced_at)
             VALUES
                (:isin, :secid, :reg_number, :issuer_id, :short_name, :full_name,
                 :nominal_value, :currency, :maturity_date, :issue_volume,
                 :listing_level, :moex_board, :coupon_type, :coupon_frequency,
                 :is_qualified_only, NOW())
             ON DUPLICATE KEY UPDATE
                secid = VALUES(secid),
                reg_number = VALUES(reg_number),
                short_name = VALUES(short_name),
                full_name = VALUES(full_name),
                nominal_value = VALUES(nominal_value),
                currency = VALUES(currency),
                maturity_date = VALUES(maturity_date),
                initial_issue_volume = VALUES(initial_issue_volume),
                listing_level = VALUES(listing_level),
                moex_board = VALUES(moex_board),
                coupon_type = VALUES(coupon_type),
                coupon_frequency = VALUES(coupon_frequency),
                is_qualified_investors_only = VALUES(is_qualified_investors_only),
                last_synced_at = NOW()'
        );
        $stmt->execute([
            'isin' => $isin,
            'secid' => $secid,
            'reg_number' => $regNumber,
            'issuer_id' => $issuerId,
            'short_name' => $shortName,
            'full_name' => $fullName,
            'nominal_value' => $nominal ?? 1000,
            'currency' => $currency,
            'maturity_date' => $maturityDate,
            'issue_volume' => $issueVolume,
            'listing_level' => $listingLevel,
            'moex_board' => $moexBoard,
            'coupon_type' => $couponType,
            'coupon_frequency' => $couponFrequency,
            'is_qualified_only' => $isQualifiedOnly === null ? 0 : (int) $isQualifiedOnly,
        ]);

        $idStmt = $this->db->prepare('SELECT id FROM securities WHERE isin = :isin');
        $idStmt->execute(['isin' => $isin]);
        return (int) $idStmt->fetchColumn();
    }

    /**
     * ISS API отдаёт FACEUNIT='SUR' для рублёвых бумаг — это устаревший
     * ISO-код рубля до деноминации 1998 года, MOEX его до сих пор
     * использует по историческим причинам. Схема хранит валюту как обычный
     * ISO 4217 ('RUB'), поэтому нормализуем на входе.
     */
    private function normalizeCurrency(?string $faceUnit): string
    {
        if ($faceUnit === null || $faceUnit === 'SUR') {
            return 'RUB';
        }
        return $faceUnit;
    }

    /**
     * COUPONFREQUENCY — число выплат в год (подтверждено на реальном
     * примере: 12 у бумаги с ежемесячным купоном). Прямое соответствие
     * ENUM-у securities.coupon_frequency.
     */
    private function mapCouponFrequency(?string $frequency): ?string
    {
        return match ($frequency) {
            '12' => 'monthly',
            '4' => 'quarterly',
            '2' => 'semi_annual',
            '1' => 'annual',
            default => null,
        };
    }

    /**
     * BOND_TYPE — свободный текст на русском (подтверждённый пример:
     * "Фикс с известным купоном"). Точный список всех возможных значений
     * не наблюдался — эвристика ниже покрывает распознанные ключевые слова
     * и по умолчанию считает бумагу 'fixed', если не нашла явных признаков
     * плавающего/индексируемого/бескупонного типа. Стоит расширить список
     * ключевых слов по мере накопления реальных значений BOND_TYPE в БД
     * (например: `SELECT DISTINCT coupon_type, COUNT(*) FROM securities
     * GROUP BY coupon_type` после первого полного прогона на боевых данных).
     */
    private function mapCouponType(?string $bondType): string
    {
        if ($bondType === null) {
            return 'fixed';
        }
        $normalized = mb_strtolower($bondType);

        if (str_contains($normalized, 'флоат') || str_contains($normalized, 'перемен')) {
            return 'floating';
        }
        if (str_contains($normalized, 'индекс')) {
            return 'indexed';
        }
        if (str_contains($normalized, 'дисконт') || str_contains($normalized, 'без купон')) {
            return 'zero_coupon';
        }
        return 'fixed';
    }

    /**
     * Строка redemptions(scheduled_maturity) — сама дата и сумма гарантированно
     * есть в description (MATDATE/FACEVALUE), поэтому это не зависит от
     * bondization-эндпоинта и заполняется сразу при импорте справочника.
     * value_per_bond здесь равен номиналу целиком — если у бумаги есть
     * амортизация, BondizationImporter корректирует это значение до остатка
     * номинала после уже импортированных amortizations (см. adjustScheduledRedemption).
     *
     * @param array<string, mixed> $description
     */
    private function upsertScheduledRedemption(int $securityId, int $issuerId, array $description): void
    {
        $maturityDate = $this->nullableDate($this->firstPresent($description, ['MATDATE']));
        $nominal = $this->firstPresent($description, ['FACEVALUE', 'INITIALFACEVALUE']);

        if ($maturityDate === null || $nominal === null) {
            $this->recordMissing('redemptions.payment_date_planned/value_per_bond');
            return;
        }

        $exists = $this->db->prepare(
            "SELECT id FROM redemptions
             WHERE security_id = :security_id AND redemption_type = 'scheduled_maturity'"
        );
        $exists->execute(['security_id' => $securityId]);
        if ($exists->fetchColumn() !== false) {
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO redemptions (security_id, issuer_id, payment_date_planned, value_per_bond, redemption_type)
             VALUES (:security_id, :issuer_id, :payment_date_planned, :value_per_bond, 'scheduled_maturity')"
        );
        $stmt->execute([
            'security_id' => $securityId,
            'issuer_id' => $issuerId,
            'payment_date_planned' => $maturityDate,
            'value_per_bond' => $nominal,
        ]);
    }

    /** @param array<string, mixed> $map */
    private function firstPresent(array $map, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($map[$key]) && $map[$key] !== '') {
                return (string) $map[$key];
            }
        }
        return null;
    }

    private function nullableDate(?string $value): ?string
    {
        if ($value === null || $value === '0000-00-00') {
            return null;
        }
        return $value;
    }

    private function recordMissing(string $field): void
    {
        $this->missingFieldCounts[$field] = ($this->missingFieldCounts[$field] ?? 0) + 1;
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по импорту справочника (проверка "всё ли можно вытащить бесплатно") ===');
        Logger::info("Бумаг обработано: {$this->securitiesSeen}");
        Logger::info("Импортировано полностью: {$this->securitiesImported}");
        Logger::info("Пропущено (нет даже EMITTER_ID): {$this->skippedNoEmitterId}");
        Logger::info("Пропущено (другая ошибка): {$this->skippedOther}");
        Logger::info('Поля с пропусками (сколько раз не удалось заполнить из бесплатного источника):');
        foreach ($this->missingFieldCounts as $field => $count) {
            Logger::info("  - {$field}: {$count}");
        }
    }
}
