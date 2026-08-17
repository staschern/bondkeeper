<?php

declare(strict_types=1);

namespace BondKeeper\Fns;

use BondKeeper\Support\Logger;
use PDO;

/**
 * Наполняет fns_blocks и issuers.is_fns_blocked/fns_last_checked_at через
 * официальный сервис ФНС service.nalog.ru/bi.do (см. NalogBiClient).
 *
 * Источник — "действующие приостановления" на сегодня, не история. Значит
 * при каждой проверке эмитента ответ — это полный СРЕЗ его активных
 * блокировок прямо сейчас, а не дельта. Отсюда два следствия для логики:
 *   - строки, которых не было в БД, но которые пришли сейчас — INSERT;
 *   - строки, которые БЫЛИ активны (unblock_date IS NULL) в БД, но не
 *     пришли в этом ответе — считаются снятыми, unblock_date = CURDATE()
 *     (см. applyResult). Строки не удаляются — это история блокировок.
 *
 * Капча: если NalogBiClient вернул captchaRequired=true — эмитент
 * пропускается БЕЗ каких-либо изменений в БД (ни apply, ни "снятие"
 * блокировок по умолчанию) — отсутствие ответа не должно интерпретироваться
 * как "блокировок нет". См. docs/STAGE1_POSTPROCESSING.md про наблюдаемую
 * частоту капчи (примерно на 3-м запросе подряд в одной сессии — отсюда
 * решение делать по одной свежей HTTP-сессии на КАЖДУЮ проверку, см.
 * NalogBiClient).
 *
 * Постановка задачи намеренно НЕ предполагает ежедневный прогон по всем
 * ~495 эмитентам сразу — vызывающий код (bin/check_fns_blocks.php)
 * передаёт заведомо небольшой список, чтобы вживую увидеть частоту капчи
 * прежде, чем масштабировать.
 */
final class FnsBlocksImporter
{
    private int $checked = 0;
    private int $blockedFound = 0;
    private int $skippedCaptcha = 0;
    private int $failed = 0;

    public function __construct(
        private readonly NalogBiClientInterface $client,
        private readonly PDO $db,
        private readonly int $delaySeconds = 5,
    ) {
    }

    /** @param array<int, array{id: int, inn: string}> $issuers */
    public function checkIssuers(array $issuers): void
    {
        foreach ($issuers as $index => $issuer) {
            if ($index > 0) {
                sleep($this->delaySeconds);
            }
            $this->checkOne((int) $issuer['id'], (string) $issuer['inn']);
        }

        $this->printReport();
    }

    private function checkOne(int $issuerId, string $inn): void
    {
        $this->checked++;

        try {
            $result = $this->client->check($inn);
        } catch (\Throwable $e) {
            $this->failed++;
            Logger::warn("ФНС: ошибка проверки ИНН {$inn}: {$e->getMessage()}");
            return;
        }

        if ($result->captchaRequired) {
            $this->skippedCaptcha++;
            Logger::warn("ФНС: капча для ИНН {$inn} — пропущен без записи в БД");
            return;
        }

        $this->applyResult($issuerId, $inn, $result->rows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function applyResult(int $issuerId, string $inn, array $rows): void
    {
        $this->db->beginTransaction();
        try {
            $seenKeys = $this->upsertActiveRows($issuerId, $rows);
            $this->markMissingRowsUnblocked($issuerId, $seenKeys);

            $isBlocked = $this->hasActiveBlocks($issuerId);
            $this->db->prepare(
                'UPDATE issuers SET is_fns_blocked = :blocked, fns_last_checked_at = NOW() WHERE id = :issuer_id'
            )->execute(['blocked' => (int) $isBlocked, 'issuer_id' => $issuerId]);

            if ($isBlocked) {
                $this->blockedFound++;
                Logger::info("ФНС: ИНН {$inn} — активная блокировка счёта подтверждена");
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{0: string, 1: string}> замеченные (bank_bik, decision_number)
     */
    private function upsertActiveRows(int $issuerId, array $rows): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO fns_blocks
                (issuer_id, bank_bik, decision_number, block_date, reason, reason_category, source_reference)
             VALUES
                (:issuer_id, :bank_bik, :decision_number, :block_date, :reason, :reason_category, :source_reference)
             ON DUPLICATE KEY UPDATE
                block_date = VALUES(block_date),
                unblock_date = NULL,
                reason = VALUES(reason),
                reason_category = VALUES(reason_category),
                source_reference = VALUES(source_reference),
                updated_at = CURRENT_TIMESTAMP'
        );

        $seenKeys = [];
        foreach ($rows as $row) {
            $bik = trim((string) ($row['BIK'] ?? ''));
            $decisionNumber = trim((string) ($row['NOMER'] ?? ''));
            $blockDate = $this->parseDate((string) ($row['DATABEGIN'] ?? $row['DATA'] ?? ''));

            if ($bik === '' || $decisionNumber === '' || $blockDate === null) {
                Logger::warn('ФНС: строка ответа без BIK/NOMER/даты пропущена: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                continue;
            }

            $kodOsnov = trim((string) ($row['KODOSNOV'] ?? ''));
            $stmt->execute([
                'issuer_id' => $issuerId,
                'bank_bik' => $bik,
                'decision_number' => $decisionNumber,
                'block_date' => $blockDate,
                'reason' => $this->reasonText($kodOsnov),
                'reason_category' => $this->reasonCategory($kodOsnov),
                'source_reference' => sprintf(
                    'service.nalog.ru bi.do, решение №%s от %s, БИК %s, опубликовано %s',
                    $decisionNumber,
                    (string) ($row['DATA'] ?? '?'),
                    $bik,
                    (string) ($row['DATABI'] ?? '?')
                ),
            ]);

            $seenKeys[] = [$bik, $decisionNumber];
        }

        return $seenKeys;
    }

    /** @param array<int, array{0: string, 1: string}> $seenKeys */
    private function markMissingRowsUnblocked(int $issuerId, array $seenKeys): void
    {
        if ($seenKeys === []) {
            $this->db->prepare(
                'UPDATE fns_blocks SET unblock_date = CURDATE()
                 WHERE issuer_id = :issuer_id AND unblock_date IS NULL'
            )->execute(['issuer_id' => $issuerId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($seenKeys), '(?,?)'));
        $params = [$issuerId];
        foreach ($seenKeys as [$bik, $decisionNumber]) {
            $params[] = $bik;
            $params[] = $decisionNumber;
        }

        $this->db->prepare(
            "UPDATE fns_blocks
             SET unblock_date = CURDATE()
             WHERE issuer_id = ? AND unblock_date IS NULL
               AND (bank_bik, decision_number) NOT IN ({$placeholders})"
        )->execute($params);
    }

    private function hasActiveBlocks(int $issuerId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM fns_blocks WHERE issuer_id = :issuer_id AND unblock_date IS NULL'
        );
        $stmt->execute(['issuer_id' => $issuerId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function parseDate(string $ddMmYyyy): ?string
    {
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $ddMmYyyy, $m)) {
            return null;
        }

        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    /**
     * Справочник кодов основания приостановления операций по счетам —
     * подтверждён вживую со страницы результата service.nalog.ru (август
     * 2026). 1 и 3 — про деньги (взыскание задолженности / обеспечение
     * исполнения решения по налоговому правонарушению), 2/4/5/6 — про
     * непредставление документов/отчётности, к сумме долга не сводятся.
     * Это первое приближение, не финальное решение — см.
     * docs/STAGE1_POSTPROCESSING.md.
     */
    private function reasonCategory(string $kodOsnov): ?string
    {
        // KODOSNOV в реальном ответе приходит с ведущим нулём ("01".."06"),
        // не как в человекочитаемой легенде на сайте ("1".."6") — сверено
        // вживую на реальном ответе (bondkeeper.ru, август 2026).
        return match (ltrim($kodOsnov, '0') ?: '0') {
            '1', '3' => 'tax_debt',
            '2', '4', '5', '6' => 'other',
            default => null,
        };
    }

    private function reasonText(string $kodOsnov): ?string
    {
        $map = [
            '1' => 'Принятие налоговым органом решения о взыскании задолженности',
            '2' => 'Непредставление налоговой декларации в течение 20 дней по истечении установленного срока',
            '3' => 'Обеспечение исполнения решения о привлечении к ответственности за налоговое правонарушение (п. 10 ст. 101 НК РФ)',
            '4' => 'Неисполнение обязанности по передаче квитанции о приёме требования/уведомления от налогового органа',
            '5' => 'Неисполнение обязанности по обеспечению получения документов от налогового органа в электронной форме',
            '6' => 'Непредставление налоговым агентом расчёта сумм НДФЛ/страховых взносов в течение 20 дней',
        ];
        $normalized = ltrim($kodOsnov, '0') ?: '0';

        return isset($map[$normalized])
            ? "Код {$kodOsnov}: {$map[$normalized]}"
            : "Код {$kodOsnov}: расшифровка не найдена в справочнике";
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по проверке блокировок счетов (ФНС, service.nalog.ru) ===');
        Logger::info("Эмитентов проверено: {$this->checked}");
        Logger::info("С активной блокировкой: {$this->blockedFound}");
        Logger::info("Пропущено (капча): {$this->skippedCaptcha}");
        Logger::info("Ошибок: {$this->failed}");
    }
}
