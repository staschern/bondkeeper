<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

/**
 * Сообщение НРД, которое меняет наши сведения об офертах (11.10.2026,
 * docs/OFFERS.md). Таких видов два — по выгрузке GetNews за 18–30.09.2026:
 *
 *   1. Объявление о досрочном погашении по решению эмитента: «(MCAL) О
 *      корпоративном действии "Досрочное обязательное погашение
 *      облигации"». Это колл, который уже объявлен: АльфаБО-21 — объявлено
 *      23.09.2026 на 09.10.2026, за 16 дней. У биржи таких дат почти нет.
 *      Тем же кодом MCAL НРД оформляет и обычное погашение в конце срока
 *      (короткие выпуски ВЭБ.РФ, ВТБ) — отличает их дата: погашение по
 *      сроку приходится на срок, досрочное — раньше (решает
 *      OfferNoticeProcessor по сроку из нашей базы).
 *
 *   2. Отмена: «Об отмене корпоративного действия» для оферты (BPUT) или
 *      досрочного погашения (MCAL). Пример — АльфаБО-21 и БиоВит 1Р1: в
 *      день объявления колла НРД отменил запланированный пут, а у биржи он
 *      остался в таблице оферт.
 *
 * Объявления НРД о самой оферте (BPUT, «О корпоративном действии») не
 * разбираются: даты путов даёт биржа. В них есть период предъявления, цена
 * и вид выкупа — это возможное уточнение на будущее.
 */
final class OfferNotice
{
    public const ACTION_ANNOUNCED = 'announced';
    public const ACTION_CANCELLED = 'cancelled';

    public function __construct(
        public readonly string $isin,
        /** put | call */
        public readonly string $type,
        public readonly string $action,
        /** Плановая дата корпоративного действия. */
        public readonly string $date,
        public readonly string $messageDate,
        /** Номер корпоративного действия у НРД: у объявления и его отмены он один. */
        public readonly string $actionRef,
        /** Погашаемая часть (MCAL) или цена приобретения (BPUT), % номинала. */
        public readonly ?string $percent = null,
        public readonly ?string $periodStart = null,
        public readonly ?string $periodEnd = null,
    ) {
    }

    /**
     * @param array<string, mixed> $rawMessage один элемент ответа GetNewsClient::fetchNews()
     * @return ?self null — сообщение не про объявление колла и не про отмену
     */
    public static function fromRaw(array $rawMessage): ?self
    {
        $type = match (explode('/', (string) ($rawMessage['ca_type'] ?? ''))[0]) {
            'BPUT' => 'put',
            'MCAL' => 'call',
            default => null,
        };
        if ($type === null) {
            return null;
        }

        $data = is_array($rawMessage['data'] ?? null) ? $rawMessage['data'] : [];
        $title = (string) ($rawMessage['title_ru'] ?? $rawMessage['announce_ru'] ?? '');
        $stateCode = (string) ($data['state']['code'] ?? '');
        if (preg_match('/об отмене корпоративного действия/ui', $title) === 1) {
            $action = self::ACTION_CANCELLED;
        } elseif ($type === 'call' && preg_match('/о корпоративном действии/ui', $title) === 1 && in_array($stateCode, ['N', 'A'], true)) {
            $action = self::ACTION_ANNOUNCED;
        } else {
            return null;
        }

        $securities = is_array($data['securities'] ?? null) ? $data['securities'] : [];
        $isin = (string) ($securities[0]['isin'] ?? '');
        $date = (string) ($data['action_date_plan'] ?? $data['action_date_calc'] ?? '');
        $messageDate = substr((string) ($rawMessage['pub_date'] ?? ''), 0, 10);
        $actionRef = (string) ($rawMessage['action_id'] ?? $data['id'] ?? '');
        if ($isin === '' || $actionRef === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $messageDate) !== 1) {
            return null;
        }

        $repayment = is_array($data['repayment'] ?? null) ? $data['repayment'] : [];
        $early = is_array($repayment['early_redemption'] ?? null) ? $repayment['early_redemption'] : [];
        $percent = $type === 'call' ? ($repayment['size_percent'] ?? null) : ($early['ep_price_percent'] ?? null);

        return new self(
            isin: $isin,
            type: $type,
            action: $action,
            date: $date,
            messageDate: $messageDate,
            actionRef: $actionRef,
            percent: is_numeric($percent) && (float) $percent > 0 && (float) $percent < 1000 ? number_format((float) $percent, 3, '.', '') : null,
            periodStart: self::date($early['issuer_start_date'] ?? null),
            periodEnd: self::date($early['issuer_end_date'] ?? null),
        );
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
