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
 */
final class SecuritiesImporter
{
    private int $securitiesSeen = 0;
    private int $securitiesImported = 0;
    private int $skippedNoIssuerInn = 0;
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

        $issuerId = $this->resolveOrCreateIssuer($isin, $description);
        if ($issuerId === null) {
            $this->skippedNoIssuerInn++;
            Logger::warn("{$isin}: не удалось определить ИНН эмитента по данным ISS API — бумага пропущена");
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
     * ВАЖНО (главный вывод шага "проверить всё ли можно вытащить"):
     * блок description по конкретной бумаге — это данные О БУМАГЕ, а не
     * структурированные данные об эмитенте как юрлице. ИНН эмитента
     * (issuers.inn, обязательное уникальное поле) НЕ подтверждён как
     * отдающийся напрямую в этом блоке; в переписке-первоисточнике
     * (Profit_Bonds Q&A) отдельно и неопределённо разбирался только
     * cci/info/companies — с explicit пометкой "не смог подтвердить,
     * требует ли авторизации". Здесь мы пробуем несколько правдоподобных
     * алиасов полей (встречаются в разных версиях ответов ISS API для
     * бумаг с эмитентом, отличным от держателя выпуска), но если ни один
     * не сработал — НЕ придумываем ИНН, а пропускаем бумагу и считаем её
     * в отчёте. Значение ИНН — часть будущей сверки с ФНС/e-disclosure по
     * этому же ключу, поэтому фиктивное значение хуже отсутствующего.
     *
     * @param array<string, mixed> $description
     */
    private function resolveOrCreateIssuer(string $isin, array $description): ?int
    {
        $inn = $this->firstPresent($description, ['EMITTER_INN', 'ISSUER_INN', 'EMITENT_INN']);
        $shortName = $this->firstPresent($description, ['EMITTER_TITLE', 'ISSUER_TITLE', 'EMITENT_TITLE', 'SECNAME']);

        if ($inn === null) {
            $this->recordMissing('issuers.inn');
            return null;
        }

        $shortName = $shortName ?? $inn;

        $stmt = $this->db->prepare(
            'INSERT INTO issuers (inn, full_name, short_name)
             VALUES (:inn, :full_name, :short_name)
             ON DUPLICATE KEY UPDATE
                short_name = VALUES(short_name),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'inn' => $inn,
            'full_name' => $shortName,
            'short_name' => $shortName,
        ]);

        $idStmt = $this->db->prepare('SELECT id FROM issuers WHERE inn = :inn');
        $idStmt->execute(['inn' => $inn]);
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
        $nominal = $this->firstPresent($description, ['FACEVALUE']);
        $currency = $this->firstPresent($description, ['FACEUNIT']) ?? 'RUB';
        $maturityDate = $this->nullableDate($this->firstPresent($description, ['MATDATE']));
        $issueVolume = $this->firstPresent($description, ['ISSUESIZE', 'ISSUESIZEPLACED']);
        $listingLevel = $this->firstPresent($description, ['LISTLEVEL']);
        $moexBoard = $primaryBoard['boardid'] ?? $primaryBoard['BOARDID'] ?? null;

        if ($nominal === null) {
            $this->recordMissing('securities.nominal_value');
        }
        foreach (['coupon_type', 'is_subordinated', 'is_structured', 'is_qualified_investors_only'] as $uncertainField) {
            // Эти поля из ISS API по косвенным признакам не подтверждены как
            // надёжные (см. README) — на этапе 1 оставляем значения по
            // умолчанию из схемы и считаем как "требует ручной проверки",
            // а не как "получено бесплатно".
            $this->recordMissing("securities.{$uncertainField} (не автоматизировано, см. README)");
        }

        $stmt = $this->db->prepare(
            'INSERT INTO securities
                (isin, secid, reg_number, issuer_id, short_name, full_name,
                 nominal_value, currency, maturity_date, initial_issue_volume,
                 listing_level, moex_board, last_synced_at)
             VALUES
                (:isin, :secid, :reg_number, :issuer_id, :short_name, :full_name,
                 :nominal_value, :currency, :maturity_date, :issue_volume,
                 :listing_level, :moex_board, NOW())
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
        ]);

        $idStmt = $this->db->prepare('SELECT id FROM securities WHERE isin = :isin');
        $idStmt->execute(['isin' => $isin]);
        return (int) $idStmt->fetchColumn();
    }

    /**
     * Строка redemptions(scheduled_maturity) — сама дата и сумма гарантированно
     * есть в description (MATDATE/FACEVALUE), поэтому это не зависит от
     * bondization-эндпоинта и заполняется сразу при импорте справочника.
     * value_per_bond здесь равен номиналу целиком — если у бумаги есть
     * амортизация, BondizationImporter должен будет скорректировать это
     * значение до остатка номинала после уже импортированных amortizations
     * (TODO: сверка остатка не реализована в этом черновике, см. README).
     *
     * @param array<string, mixed> $description
     */
    private function upsertScheduledRedemption(int $securityId, int $issuerId, array $description): void
    {
        $maturityDate = $this->nullableDate($this->firstPresent($description, ['MATDATE']));
        $nominal = $this->firstPresent($description, ['FACEVALUE']);

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
        Logger::info("Пропущено (не найден ИНН эмитента): {$this->skippedNoIssuerInn}");
        Logger::info("Пропущено (другая ошибка): {$this->skippedOther}");
        Logger::info('Поля с пропусками (сколько раз не удалось заполнить из бесплатного источника):');
        foreach ($this->missingFieldCounts as $field => $count) {
            Logger::info("  - {$field}: {$count}");
        }
    }
}
