<?php

declare(strict_types=1);

namespace BondKeeper\Fns;

use BondKeeper\Support\Logger;
use PDO;

/**
 * Наполняет fns_blocks (одна строка на эмитента: блокировка + состояние
 * проверки в одном месте) через официальный сервис ФНС
 * service.nalog.ru/bi.do (см. NalogBiClient).
 *
 * Источник — "действующие приостановления" на сегодня, не история. Значит
 * при каждой проверке эмитента ответ — это полный СРЕЗ его активных
 * блокировок прямо сейчас, а не дельта.
 *
 * Пост-обработка (миграция 010): одна строка на ЭМИТЕНТА, не на банк.
 * Раньше (миграция 008) хранили по строке на каждую пару БИК+номер
 * решения — но на реальных данных (ООО "Контрол Лизинг") выяснилось, что
 * одна и та же непрерывная блокировка периодически переиздаётся ФНС под
 * новым номером решения даже без факта разблокировки — ключ по номеру
 * решения принимал это за "снята старая, появилась новая" и плодил
 * дубли. Плюс дата/сумма/основание оказались одинаковыми во всех банках
 * одного эмитента — построчное хранение по банкам не несло
 * дополнительного смысла, только количество. Теперь: данные берутся из
 * ПЕРВОЙ валидной строки ответа, а число банков — в `active_bank_count`
 * (см. consolidateRows). Строка не удаляется при снятии всех
 * блокировок — так же, как раньше, помечается `unblock_date = CURDATE()`.
 *
 * Пост-обработка (миграция 009, перенесено на fns_blocks миграцией 011):
 * раньше неудачная попытка (капча, сетевая ошибка) вообще не оставляла
 * следа в БД — нельзя было отличить "ещё не проверяли" от "проверяли, но
 * не вышло". Теперь КАЖДАЯ попытка отмечается: date_verification = когда
 * была последняя попытка (успешная или нет), verification =
 * 'success'/'error'. is_fns_blocked и содержимое блокировки меняются
 * ТОЛЬКО при успешном разборе ответа (verification = 'success') —
 * капча/ошибка сети НЕ трактуются как "блокировок нет", это именно "не
 * удалось узнать". Изначально эти четыре поля жили на issuers, миграция
 * 011 перенесла их на fns_blocks — после миграции 010 это та же самая
 * "одна строка на эмитента", логичнее держать состояние проверки рядом
 * с её результатом, а не в отдельной таблице.
 *
 * Постановка задачи намеренно НЕ предполагает ежедневный прогон по всем
 * ~495 эмитентам сразу — вызывающий код (bin/check_fns_blocks.php)
 * передаёт заведомо небольшой список и работает МЕДЛЕННО и БЕЗ прокси —
 * часть эмитентов будет пропущена капчей, это ожидаемо и честно
 * отражается как verification='error', а не как повод её обходить.
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
            $this->markVerificationError($issuerId);
            return;
        }

        if ($result->captchaRequired) {
            $this->skippedCaptcha++;
            Logger::warn("ФНС: капча для ИНН {$inn} — статус блокировки не тронут, отмечена только попытка");
            $this->markVerificationError($issuerId);
            return;
        }

        $this->applyResult($issuerId, $inn, $result->rows);
    }

    /**
     * Неудачная попытка (капча или сетевая/парсинг-ошибка) — единственное,
     * что меняется, это отметка "когда пытались и что вышло". is_fns_blocked
     * и остальное содержимое блокировки НЕ трогаются — отсутствие ответа не
     * должно интерпретироваться как "блокировок нет". Если строки для
     * эмитента в fns_blocks ещё нет вообще (ни разу не проверяли успешно) —
     * создаём её с is_fns_blocked=FALSE по умолчанию схемы, только чтобы
     * было куда записать verification/date_verification.
     */
    private function markVerificationError(int $issuerId): void
    {
        $this->db->prepare(
            "INSERT INTO fns_blocks (issuer_id, verification, date_verification)
             VALUES (:issuer_id, 'error', NOW())
             ON DUPLICATE KEY UPDATE
                verification = 'error',
                date_verification = NOW()"
        )->execute(['issuer_id' => $issuerId]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function applyResult(int $issuerId, string $inn, array $rows): void
    {
        $this->db->beginTransaction();
        try {
            $summary = $this->consolidateRows($rows);
            $isBlocked = $summary !== null;

            if ($summary !== null) {
                $stmt = $this->db->prepare(
                    "INSERT INTO fns_blocks
                        (issuer_id, is_fns_blocked, verification, date_verification, last_success_verification,
                         active_bank_count, decision_number, block_date, blocked_amount, reason, reason_category, source_reference)
                     VALUES
                        (:issuer_id, 1, 'success', NOW(), NOW(),
                         :active_bank_count, :decision_number, :block_date, :blocked_amount, :reason, :reason_category, :source_reference)
                     ON DUPLICATE KEY UPDATE
                        is_fns_blocked = 1,
                        verification = 'success',
                        date_verification = NOW(),
                        last_success_verification = NOW(),
                        active_bank_count = VALUES(active_bank_count),
                        decision_number = VALUES(decision_number),
                        block_date = VALUES(block_date),
                        unblock_date = NULL,
                        blocked_amount = VALUES(blocked_amount),
                        reason = VALUES(reason),
                        reason_category = VALUES(reason_category),
                        source_reference = VALUES(source_reference),
                        updated_at = CURRENT_TIMESTAMP"
                );
                $stmt->execute([
                    'issuer_id' => $issuerId,
                    'active_bank_count' => $summary['active_bank_count'],
                    'decision_number' => $summary['decision_number'],
                    'block_date' => $summary['block_date'],
                    'blocked_amount' => $summary['blocked_amount'],
                    'reason' => $summary['reason'],
                    'reason_category' => $summary['reason_category'],
                    'source_reference' => $summary['source_reference'],
                ]);
            } else {
                // Ни одной валидной строки в ответе — проверка прошла
                // успешно, блокировок нет. unblock_date выставляется
                // только если строка ДО этого была активно заблокирована
                // (is_fns_blocked = 1 читается ДО этого же UPDATE — ссылка
                // на колонку без VALUES() в ON DUPLICATE KEY UPDATE берёт
                // текущее значение строки, не новое) — если эмитент и
                // раньше был свободен, его старый unblock_date (если был)
                // не трогаем, а не пробел/не блокировка вообще ещё не
                // проверялась — тогда это просто первая вставка.
                $this->db->prepare(
                    "INSERT INTO fns_blocks
                        (issuer_id, is_fns_blocked, verification, date_verification, last_success_verification, active_bank_count)
                     VALUES
                        (:issuer_id, 0, 'success', NOW(), NOW(), 0)
                     ON DUPLICATE KEY UPDATE
                        unblock_date = IF(is_fns_blocked = 1, CURDATE(), unblock_date),
                        is_fns_blocked = 0,
                        verification = 'success',
                        date_verification = NOW(),
                        last_success_verification = NOW(),
                        active_bank_count = 0,
                        updated_at = CURRENT_TIMESTAMP"
                )->execute(['issuer_id' => $issuerId]);
            }

            if ($isBlocked) {
                $this->blockedFound++;
                Logger::info("ФНС: ИНН {$inn} — активная блокировка счёта подтверждена ({$summary['active_bank_count']} банк(ов))");
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Сводит все строки ответа в одну запись на эмитента: дата, сумма и
     * основание берутся из ПЕРВОЙ валидной строки (на практике совпадают
     * во всех банках одного эмитента — см. докблок класса), а число
     * валидных строк идёт в active_bank_count вместо перечисления
     * конкретных БИК, которые сами по себе не несут продуктовой ценности.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{active_bank_count: int, decision_number: string, block_date: string, blocked_amount: ?string, reason: ?string, reason_category: ?string, source_reference: string}|null
     */
    private function consolidateRows(array $rows): ?array
    {
        $valid = [];
        foreach ($rows as $row) {
            $bik = trim((string) ($row['BIK'] ?? ''));
            $decisionNumber = trim((string) ($row['NOMER'] ?? ''));
            $blockDate = $this->parseDate((string) ($row['DATABEGIN'] ?? $row['DATA'] ?? ''));

            if ($bik === '' || $decisionNumber === '' || $blockDate === null) {
                Logger::warn('ФНС: строка ответа без BIK/NOMER/даты пропущена: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                continue;
            }

            $valid[] = ['row' => $row, 'decision_number' => $decisionNumber, 'block_date' => $blockDate];
        }

        if ($valid === []) {
            return null;
        }

        $first = $valid[0];
        $kodOsnov = trim((string) ($first['row']['KODOSNOV'] ?? ''));

        return [
            'active_bank_count' => count($valid),
            'decision_number' => $first['decision_number'],
            'block_date' => $first['block_date'],
            'blocked_amount' => $this->parseAmount($first['row']['SALDOENS'] ?? null),
            'reason' => $this->reasonText($kodOsnov),
            'reason_category' => $this->reasonCategory($kodOsnov),
            'source_reference' => sprintf(
                'service.nalog.ru bi.do, решение №%s от %s, банков одновременно: %d',
                $first['decision_number'],
                (string) ($first['row']['DATA'] ?? '?'),
                count($valid)
            ),
        ];
    }

    /**
     * SALDOENS — "Размер отрицательного сальдо ЕНС" (подтверждено вживую
     * пользователем через интерфейс сайта, сверено с колонкой в БД).
     * Приходит только для кодов основания 01/03 (см. сноску "*" в
     * справочнике на самой странице результата) — для остальных кодов
     * поля в ответе нет вообще, blocked_amount останется NULL, и это
     * ожидаемо, а не пробел. Значение приходит с ведущими пробелами
     * (например "       302156193.95") — только тримить, разделитель
     * дробной части уже точка, тысячи ничем не разделены.
     */
    private function parseAmount(mixed $rawSaldoEns): ?string
    {
        if ($rawSaldoEns === null) {
            return null;
        }
        $trimmed = trim((string) $rawSaldoEns);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        return $trimmed;
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
