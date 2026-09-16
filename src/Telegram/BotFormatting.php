<?php

declare(strict_types=1);

namespace BondKeeper\Telegram;

/**
 * Мелкие форматтеры для текста, которые нужны и `BotCommandHandler`
 * (раздел "Статус"), и `NotificationDispatcher` (шаблоны уведомлений,
 * Этап 4 Фаза 3) — вынесены сюда, чтобы не дублировать один и тот же
 * `match`/`DateTime::createFromFormat` в двух местах.
 */
final class BotFormatting
{
    private function __construct()
    {
        // Только статические методы — инстанцировать незачем.
    }

    public static function agencyDisplayName(string $code): string
    {
        return match ($code) {
            'nkr' => 'НКР',
            'nra' => 'НРА',
            'expert_ra' => 'Эксперт РА',
            'acra' => 'АКРА',
            default => $code,
        };
    }

    /** 'Y-m-d' или 'Y-m-d H:i:s' -> 'd.m.y'; null/'' -> '—' (не пробел форматирования — источника нет). */
    public static function formatDate(?string $isoDate): string
    {
        if ($isoDate === null || $isoDate === '') {
            return '—';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', substr($isoDate, 0, 10));

        return $dt !== false ? $dt->format('d.m.y') : $isoDate;
    }

    /**
     * DECIMAL-строка из БД ('46740445.40') -> "46 740 445.40 ₽" — разделитель
     * разрядов пробелом, по прямому запросу пользователя (17 сентября 2026):
     * до этого сумма блокировки выводилась как есть, сплошной строкой цифр,
     * что тяжело читается на суммах от 7 знаков. null/'' -> 'не указана'
     * (тот же текст, что уже был запасным значением в formatIssuerStatus()).
     */
    public static function formatMoney(?string $decimal): string
    {
        if ($decimal === null || $decimal === '') {
            return 'не указана';
        }

        return number_format((float) $decimal, 2, '.', ' ') . ' ₽';
    }
}
