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
 * итогам реального прогона.
 *
 * Два запроса на бумагу, оба проверены на реальном ответе ISS API
 * (bondkeeper.ru, август 2026):
 *   1. /iss/securities.json?q={ISIN} — общий поиск. Отдаёт emitent_id/
 *      emitent_title/emitent_inn напрямую (то, чего НЕ было в /iss/
 *      securities/{ISIN}.json — см. database/002_issuer_moex_emitter_id.sql
 *      про более раннюю, ошибочную попытку через EMITTER_ID без ИНН).
 *      Также отдаёт secid — который для гособлигаций (ОФЗ) НЕ совпадает
 *      с ISIN (например ISIN RU0002868001 = SECID SU46012RMFS9), в
 *      отличие от большинства корпоративных бумаг, где они совпадают.
 *   2. /iss/securities/{SECID}.json — карточка самой бумаги (description):
 *      номинал, даты, купон и т.д. Ключевая правка: раньше эту карточку
 *      запрашивали по ISIN, что молча возвращало пустой ответ для всех
 *      ОФЗ — 59 бумаг из 3061 пропадали именно поэтому.
 */
final class SecuritiesImporter
{
    private int $securitiesSeen = 0;
    private int $securitiesImported = 0;
    private int $skippedNotFoundInSearch = 0;
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
        $identity = $this->searchIdentity($isin);
        if ($identity === null) {
            $this->skippedNotFoundInSearch++;
            Logger::warn("{$isin}: не нашёлся в /iss/securities.json?q= — бумага пропущена (см. отчёт)");
            return;
        }

        $issuerId = $this->resolveOrCreateIssuer($identity);

        $secid = $identity['secid'] ?? $isin;
        $response = $this->iss->getJson("/securities/{$secid}.json", [
            'iss.only' => 'description,boards',
        ]);
        $description = $this->descriptionMap(IssClient::block($response, 'description'));
        $boards = IssClient::block($response, 'boards');
        $primaryBoard = $this->pickPrimaryBoard($boards);

        $securityId = $this->upsertSecurity($isin, $issuerId, $identity, $description, $primaryBoard);
        $this->upsertScheduledRedemption($securityId, $issuerId, $description);

        $this->securitiesImported++;
    }

    /**
     * Общий поиск ISS API по ISIN — единственный подтверждённый источник
     * emitent_inn/emitent_title/emitent_id, и заодно правильного secid
     * (для ОФЗ он не совпадает с ISIN). Ищем точное совпадение по полю
     * isin в результатах, а не берём первую строку — на случай, если
     * поиск вернёт несколько похожих бумаг.
     *
     * @return array<string, mixed>|null
     */
    private function searchIdentity(string $isin): ?array
    {
        $response = $this->iss->getJson('/securities.json', [
            'q' => $isin,
            'iss.only' => 'securities',
        ]);
        $rows = IssClient::block($response, 'securities');

        foreach ($rows as $row) {
            if (($row['isin'] ?? null) === $isin) {
                return $row;
            }
        }

        return null;
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
     * ИНН эмитента (issuers.inn) — раньше считался недоступным бесплатно
     * (см. историю в database/002_issuer_moex_emitter_id.sql), пока не
     * выяснилось, что /iss/securities.json?q= отдаёт его прямым полем
     * emitent_inn. issuers.moex_emitter_id сохраняем тоже — он остаётся
     * полезным как более стабильный числовой ключ на случай технических
     * расхождений в написании ИНН между источниками в будущем (сверка с
     * ФНС/e-disclosure).
     *
     * ON DUPLICATE KEY UPDATE перезаписывает inn/имена при каждом повторном
     * прогоне — в отличие от предыдущей ревизии (когда апдейтился только
     * updated_at), потому что теперь есть реальные юридические данные,
     * которые стоит держать актуальными, а не консервировать первое
     * попавшееся приближение.
     *
     * @param array<string, mixed> $identity
     */
    private function resolveOrCreateIssuer(array $identity): int
    {
        $emitterId = $identity['emitent_id'] ?? null;
        $inn = $identity['emitent_inn'] ?? null;
        $title = $identity['emitent_title'] ?? null;

        if ($inn === null || $inn === '') {
            $this->recordMissing('issuers.inn (отсутствовал в конкретном ответе поиска)');
            $inn = null;
        }
        if ($title === null || $title === '') {
            $title = $emitterId !== null ? "MOEX-{$emitterId}" : 'Неизвестный эмитент';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO issuers (moex_emitter_id, inn, full_name, short_name)
             VALUES (:emitter_id, :inn, :full_name, :short_name)
             ON DUPLICATE KEY UPDATE
                inn = VALUES(inn),
                full_name = VALUES(full_name),
                short_name = VALUES(short_name),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'emitter_id' => $emitterId !== null ? (int) $emitterId : null,
            'inn' => $inn,
            'full_name' => $title,
            'short_name' => $title,
        ]);

        if ($emitterId !== null) {
            $idStmt = $this->db->prepare('SELECT id FROM issuers WHERE moex_emitter_id = :emitter_id');
            $idStmt->execute(['emitter_id' => (int) $emitterId]);
        } else {
            $idStmt = $this->db->prepare('SELECT id FROM issuers WHERE inn = :inn');
            $idStmt->execute(['inn' => $inn]);
        }
        return (int) $idStmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $identity данные из /iss/securities.json?q=
     * @param array<string, mixed> $description данные из /iss/securities/{secid}.json
     * @param array<string, mixed>|null $primaryBoard
     */
    private function upsertSecurity(string $isin, int $issuerId, array $identity, array $description, ?array $primaryBoard): int
    {
        $secid = $identity['secid'] ?? $this->firstPresent($description, ['SECID']) ?? $isin;
        $regNumber = $identity['regnumber'] ?? $this->firstPresent($description, ['REGNUMBER']);
        $shortName = $identity['shortname'] ?? $this->firstPresent($description, ['SHORTNAME']) ?? $isin;
        $fullName = $identity['name'] ?? $this->firstPresent($description, ['NAME']);
        $nominal = $this->firstPresent($description, ['FACEVALUE', 'INITIALFACEVALUE']);
        $currency = $this->normalizeCurrency($this->firstPresent($description, ['FACEUNIT']));
        $maturityDate = $this->nullableDate($this->firstPresent($description, ['MATDATE']));
        $issueVolume = $this->firstPresent($description, ['ISSUESIZE', 'ISSUESIZEPLACED']);
        $listingLevel = $this->firstPresent($description, ['LISTLEVEL']);
        $moexBoard = $identity['primary_boardid'] ?? $primaryBoard['boardid'] ?? $primaryBoard['BOARDID'] ?? null;

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
            // coupon_frequency, которые уже замаплены на реальные поля
            // (ISQUALIFIEDINVESTORS/BOND_TYPE/COUPONFREQUENCY).
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
        Logger::info("Пропущено (не нашлась в поиске ISS API): {$this->skippedNotFoundInSearch}");
        Logger::info("Пропущено (другая ошибка): {$this->skippedOther}");
        Logger::info('Поля с пропусками (сколько раз не удалось заполнить из бесплатного источника):');
        foreach ($this->missingFieldCounts as $field => $count) {
            Logger::info("  - {$field}: {$count}");
        }
    }
}
