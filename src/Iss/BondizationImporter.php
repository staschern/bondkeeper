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
 * Пост-обработка этапа 1 (docs/STAGE1_POSTPROCESSING.md): переписано после
 * находки, что обе таблицы фактически "замерзали" после первого прогона.
 * Причина была в связке двух решений:
 *   1. Отбор "что обрабатывать сегодня" проверял буквально "есть ли у
 *      бумаги хоть одна строка" (LEFT JOIN ... WHERE c.id IS NULL). Как
 *      только строка появлялась — бумага навсегда выпадала из ежедневной
 *      обработки, даже если график ещё не полон.
 *   2. `value_per_bond NOT NULL` заставлял тихо выбрасывать (`continue`)
 *      любую выплату, для которой API ещё не знает сумму — типичный
 *      случай для флоатеров и структурных облигаций, где сумма будущего
 *      купона зависит от ещё не наступившей даты фиксации базовой ставки.
 * Вместе это значило: у такой бумаги первая же прошедшая (уже известная)
 * выплата блокировала бумагу от дальнейшей обработки навсегда, а будущие
 * выплаты с неизвестной суммой никогда не попадали в таблицу вообще —
 * не как NULL, а как полностью отсутствующий факт.
 *
 * Теперь: 1) строка со ЗНАЧЕНИЕМ даты, но неизвестной суммой — всё равно
 * вставляется (status='planned', rate_percent/value_per_bond=NULL), а не
 * пропускается; 2) отбор "что обрабатывать сегодня" смотрит не на факт
 * существования строк, а на то, есть ли у бумаги незаполненные "плейсхолдеры"
 * (см. importForAllPending()); 3) апсерт по естественному ключу
 * (security_id, period_end_date)/(security_id, payment_date_planned) вместо
 * DELETE+INSERT — coupon_number (порядковый номер, который мы считали сами)
 * убран из схемы (миграция 006), поэтому исходная причина держать
 * DELETE+INSERT (нестабильность порядковых номеров между прогонами) больше
 * не существует: дата не "сдвигается" от прогона к прогону.
 */
final class BondizationImporter
{
    private int $processed = 0;
    private int $couponsUpserted = 0;
    private int $amortizationsUpserted = 0;
    private int $failed = 0;

    public function __construct(
        private readonly IssClient $iss,
        private readonly PDO $db,
    ) {
    }

    /**
     * "Нужно обработать сегодня" = у бумаги есть незаполненные данные, к
     * которым стоит вернуться:
     *   - вообще ни одной строки ещё нет (первый сев для этой бумаги —
     *     единственный случай, где для coupons отбор смотрит на факт
     *     существования строк, а не на их содержимое: пустой график
     *     бывает у настоящих zero_coupon-бумаг, и это нормально, но
     *     отличить "ещё не сеяли" от "сеяли и график пуст" без строк
     *     нельзя, поэтому такая бумага будет открываться на bondization
     *     каждый день — это лишний, но безвредный HTTP-запрос, а не
     *     потеря данных);
     *   - ИЛИ у бумаги есть купон с известной датой, но неизвестной суммой
     *     (status='planned' AND value_per_bond IS NULL) — типично для
     *     флоатеров/структурных облигаций, ждём, когда биржа опубликует
     *     факт;
     *   - ИЛИ у бумаги, уже отмеченной как амортизируемая
     *     (securities.is_amortized), есть амортизация с известной датой,
     *     но неизвестной суммой, ИЛИ амортизации нет вообще ни одной
     *     строки. Обе проверки — только для is_amortized=1, не проверяются
     *     для НЕ-амортизируемых бумаг: у подавляющего большинства бумаг
     *     amortizations пуста НАВСЕГДА (обычный bullet-бонд без
     *     амортизации) — если бы отсутствие строк в amortizations само по
     *     себе считалось "нужно обработать" для ВСЕХ бумаг, это означало
     *     бы пересев всего рынка каждый день, а не только реально
     *     недозаполненных бумаг.
     *
     *     Часть "амортизации нет вообще ни одной строки" — страховка от
     *     конкретного найденного сценария: бумага реально амортизируемая
     *     (SecuritiesImporter уже поставил is_amortized=1 по тегу
     *     "амортизир" в BOND_TYPE — см. её докблок), но в день первого
     *     запроса bondization-эндпоинт вернул купоны полностью, а блок
     *     amortizations — пустым (тот же класс разового неполного ответа
     *     API, что уже случался). Без этой проверки: coupons полностью
     *     заполнены → первое условие ложно; amortizations пуста, но
     *     is_amortized уже TRUE (от текстового сигнала) → без явной
     *     проверки NOT EXISTS бумага никогда больше не открылась бы на
     *     bondization — is_amortized=1 сам по себе не запускает
     *     переобработку, нужен ещё и факт отсутствия строк.
     */
    public function importForAllPending(bool $forceAll = false): void
    {
        $this->processed = 0;
        $this->couponsUpserted = 0;
        $this->amortizationsUpserted = 0;
        $this->failed = 0;

        $sql = 'SELECT s.id, s.isin, s.issuer_id FROM securities s WHERE s.status = "active"';
        if (!$forceAll) {
            $sql .= ' AND (
                NOT EXISTS (SELECT 1 FROM coupons c WHERE c.security_id = s.id)
                OR EXISTS (
                    SELECT 1 FROM coupons c
                    WHERE c.security_id = s.id AND c.status = "planned" AND c.value_per_bond IS NULL
                )
                OR (
                    s.is_amortized = 1
                    AND (
                        NOT EXISTS (SELECT 1 FROM amortizations a WHERE a.security_id = s.id)
                        OR EXISTS (
                            SELECT 1 FROM amortizations a
                            WHERE a.security_id = s.id AND a.status = "planned" AND a.value_per_bond IS NULL
                        )
                    )
                )
            )';
        }

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
        Logger::info("Купонов записано (вставлено/обновлено): {$this->couponsUpserted}");
        Logger::info("Амортизаций записано (вставлено/обновлено): {$this->amortizationsUpserted}");
        Logger::info("Ошибок: {$this->failed}");
    }

    /**
     * Апсерт вместо DELETE+INSERT: и coupons, и amortizations теперь имеют
     * UNIQUE KEY на естественном ключе (security_id, дата), поэтому нет
     * нужды удалять весь график перед перезаписью — ON DUPLICATE KEY UPDATE
     * обновляет ровно те строки, которые реально пришли в новом ответе API,
     * не трогая остальные.
     *
     * Строка вставляется, если у выплаты известна дата — сумма/ставка
     * необязательны (см. класс-докблок). Пропускается только полностью
     * бесполезная строка без даты вообще.
     */
    private function importOne(int $securityId, string $isin, int $issuerId): void
    {
        $response = $this->iss->getJson(
            "/statistics/engines/stock/markets/bonds/bondization/{$isin}.json",
            ['iss.only' => 'coupons,amortizations']
        );

        $coupons = IssClient::block($response, 'coupons');
        $amortizations = IssClient::block($response, 'amortizations');

        $this->db->beginTransaction();
        try {
            $couponUpsert = $this->db->prepare(
                'INSERT INTO coupons (security_id, issuer_id, period_start_date, period_end_date, rate_percent, value_per_bond)
                 VALUES (:security_id, :issuer_id, :period_start, :period_end, :rate, :value)
                 ON DUPLICATE KEY UPDATE
                    period_start_date = VALUES(period_start_date),
                    rate_percent = VALUES(rate_percent),
                    value_per_bond = VALUES(value_per_bond),
                    updated_at = CURRENT_TIMESTAMP'
            );

            foreach ($coupons as $coupon) {
                $couponDate = $coupon['coupondate'] ?? null;
                if ($couponDate === null) {
                    continue;
                }

                $couponUpsert->execute([
                    'security_id' => $securityId,
                    'issuer_id' => $issuerId,
                    'period_start' => $coupon['startdate'] ?? null,
                    'period_end' => $couponDate,
                    // Реальное имя поля со ставкой купона в bondization-ответе —
                    // valueprc (проверено на bondkeeper.ru: 'value'=16.44 руб.,
                    // 'valueprc'=20 — то есть 20% годовых). Поля 'couponpercent'
                    // в этом эндпоинте не существует вообще. Оба поля
                    // намеренно НЕ форсируются в NULL, если API их не даёт —
                    // просто передаём то, что реально пришло: у флоатера
                    // ставка и сумма обычно неизвестны вместе, но если
                    // когда-нибудь придёт одно без другого, не теряем то,
                    // что есть.
                    'rate' => $coupon['valueprc'] ?? null,
                    'value' => $coupon['value'] ?? null,
                ]);
                $this->couponsUpserted++;
            }

            $amortUpsert = $this->db->prepare(
                'INSERT INTO amortizations (security_id, issuer_id, payment_date_planned, value_per_bond, percent_of_nominal)
                 VALUES (:security_id, :issuer_id, :payment_date, :value, :percent)
                 ON DUPLICATE KEY UPDATE
                    value_per_bond = VALUES(value_per_bond),
                    percent_of_nominal = VALUES(percent_of_nominal),
                    updated_at = CURRENT_TIMESTAMP'
            );

            $upsertedAmortizations = [];
            foreach ($amortizations as $amortization) {
                $amortDate = $amortization['amortdate'] ?? null;
                if ($amortDate === null) {
                    continue;
                }

                $amortUpsert->execute([
                    'security_id' => $securityId,
                    'issuer_id' => $issuerId,
                    'payment_date' => $amortDate,
                    'value' => $amortization['value'] ?? null,
                    'percent' => $amortization['valueprc'] ?? null,
                ]);
                $this->amortizationsUpserted++;
                $upsertedAmortizations[] = $amortization;
            }

            // is_amortized — монотонный флаг, выставляется В ЭТОМ методе
            // ТОЛЬКО в TRUE, никогда обратно в FALSE. Раньше сюда писали
            // 0, если сегодняшний ответ API не содержал ни одной строки
            // амортизации — а bondization-эндпоинт уже ловили на разовых
            // неполных ответах (435 бумаг за один день, см. историю в
            // README). Для бумаги с реальной амортизацией это означало:
            // один неудачный день — и is_amortized тихо становится FALSE
            // навсегда, а вместе с ним бумага перестаёт даже проверяться
            // на предмет "не пришла ли амортизация" в pending-отборе
            // importForAllPending() (см. её докблок). SecuritiesImporter
            // отдельно, по тегу "амортизир" в BOND_TYPE, может выставить
            // тот же флаг ДО того, как здесь появятся реальные строки —
            // оба источника пишут через GREATEST()/только-TRUE, поэтому
            // не конфликтуют между собой.
            if ($upsertedAmortizations !== []) {
                $this->db->prepare('UPDATE securities SET is_amortized = 1 WHERE id = :security_id')
                    ->execute(['security_id' => $securityId]);

                // Плановая сумма scheduled_maturity должна быть остатком
                // номинала, а не полным номиналом, который проставил
                // SecuritiesImporter — досчитываем здесь, когда график уже
                // известен.
                $this->adjustScheduledRedemption($securityId);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Пересчитывает от НЕИЗМЕННОГО номинала на момент выпуска
     * (securities.initial_nominal_value, миграция 007), а НЕ от текущего
     * securities.nominal_value. Текущий nominal_value — это тот же
     * FACEVALUE, который синкается ежедневно и у амортизируемой бумаги УЖЕ
     * САМ ПО СЕБЕ является остатком после прошлых амортизаций. Если из него
     * ещё раз вычесть сумму по ВСЕЙ амортизации (bondization отдаёт график
     * целиком — и прошлые, и будущие транши), прошлая амортизация
     * вычитается дважды, а будущая — раньше времени: у бумаги с
     * существенной историей амортизации итог легко уходит в минус.
     * COALESCE на nominal_value — подстраховка на случай, если
     * initial_nominal_value почему-то не заполнился (см. миграцию 007).
     *
     * Сумма амортизации берётся запросом к самой таблице (SUM по всем
     * сохранённым строкам securities_id), а не из аргументов текущего
     * вызова — так пересчёт корректен даже если СЕГОДНЯШНИЙ ответ API
     * вернул амортизацию не полностью (разовый неполный ответ уже
     * случался, см. README): в сумму попадёт всё, что реально накоплено
     * в таблице к этому моменту, включая строки из прошлых успешных
     * прогонов. Идемпотентен и не зависит от того, запускался ли пересчёт
     * раньше для этой бумаги. SUM() в MySQL игнорирует NULL — неизвестные
     * (ещё не опубликованные биржей) суммы амортизации по умолчанию не
     * увеличивают вычитаемое, как только они появятся, следующий пересчёт
     * учтёт их автоматически.
     */
    private function adjustScheduledRedemption(int $securityId): void
    {
        $sumStmt = $this->db->prepare(
            'SELECT SUM(value_per_bond) FROM amortizations WHERE security_id = :security_id'
        );
        $sumStmt->execute(['security_id' => $securityId]);
        $totalAmortized = (float) $sumStmt->fetchColumn();

        $this->db->prepare(
            "UPDATE redemptions r
             JOIN securities s ON s.id = r.security_id
             SET r.value_per_bond = COALESCE(s.initial_nominal_value, s.nominal_value) - :amortized
             WHERE r.security_id = :security_id
               AND r.redemption_type = 'scheduled_maturity'"
        )->execute([
            'amortized' => $totalAmortized,
            'security_id' => $securityId,
        ]);
    }
}
