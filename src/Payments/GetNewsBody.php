<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

/**
 * Чтение текста сообщения GetNews (поле body_ru — HTML-таблицы). Чистые
 * функции, без БД и сети.
 *
 * Зачем: сведения о деньгах НРД кладёт только в текст. Проверено на полной
 * выгрузке тестового доступа (3798 сообщений, 18–30.09.2026): во всех 1521
 * сообщениях «О получении…», «О получении и передаче…» и «О передаче…»
 * есть таблица
 *
 *   Текущая выплата по КД
 *   Размер денежных средств, подлежащих выплате на 1 ц.б. | Дата поступления в НРД денежных средств | Дата передачи … своим депонентам
 *   730.895092532                                         | 29 сентября 2026 г.                      | …
 *
 * — всегда одна строка значений; «Размер…» здесь — сумма ИМЕННО ЭТОГО
 * перевода на одну бумагу (при выплате частями — размер части), в валюте
 * выплаты. Колонки «Дата передачи» в сообщении «О получении…» нет.
 *
 * Если эмитент нарушил срок или сумму, ниже идёт вторая таблица —
 * «Сведения о неисполнении/ненадлежащем исполнении эмитентом обязанности
 * по выплате» с одной из формулировок (все четыре встречены в выгрузке):
 *   «Не исполнена эмитентом в срок» — деньги пришли полностью, но позже;
 *   «Исполнена ненадлежащим образом» — пришла часть;
 *   «Не исполнена эмитентом в срок, исполнена ненадлежащим образом»;
 *   «Исполнена в неполном объеме до наступления срока» — часть пришла
 *   раньше срока, нарушением НРД это ещё не называет.
 *
 * Поля data.coupon.payment_size / data.repayment.size_per_security_cur —
 * ПЛАНОВАЯ сумма: при выплате частями они не меняются, поэтому по ним
 * частичную выплату не увидеть.
 */
final class GetNewsBody
{
    private const MONTHS = [
        'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6,
        'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12,
    ];

    /**
     * @return array{amount: ?string, received: ?string, transferred: ?string, note: ?string}
     *         amount — сумма перевода на бумагу (десятичная строка); даты — ГГГГ-ММ-ДД;
     *         note — формулировка НРД о неисполнении, если она есть
     */
    public static function currentPayment(string $html): array
    {
        $result = ['amount' => null, 'received' => null, 'transferred' => null, 'note' => null];

        foreach (self::tables($html) as $rows) {
            $titleRow = self::rowIndexContaining($rows, 'Текущая выплата по КД');
            if ($titleRow !== null && isset($rows[$titleRow + 1], $rows[$titleRow + 2])) {
                $labels = $rows[$titleRow + 1];
                $values = $rows[$titleRow + 2];
                foreach ($labels as $i => $label) {
                    $value = $values[$i] ?? '';
                    if (str_starts_with($label, 'Размер денежных средств')) {
                        $result['amount'] = self::parseAmount($value);
                    } elseif (str_starts_with($label, 'Дата поступления')) {
                        $result['received'] = self::parseDate($value);
                    } elseif (str_starts_with($label, 'Дата передачи')) {
                        $result['transferred'] = self::parseDate($value);
                    }
                }
            }

            $noteRow = self::rowIndexContaining($rows, 'Сведения о неисполнении');
            if ($noteRow !== null) {
                $notes = [];
                foreach (array_slice($rows, $noteRow + 1) as $cells) {
                    foreach ($cells as $cell) {
                        if ($cell !== '') {
                            $notes[] = $cell;
                        }
                    }
                }
                if ($notes !== []) {
                    $result['note'] = implode('; ', $notes);
                }
            }
        }

        return $result;
    }

    /** «29 сентября 2026 г.» → 2026-09-29; не дата — null. */
    public static function parseDate(string $text): ?string
    {
        if (preg_match('/(\d{1,2})\s+([а-яё]+)\s+(\d{4})/u', mb_strtolower($text), $m) !== 1 || !isset(self::MONTHS[$m[2]])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $m[3], self::MONTHS[$m[2]], (int) $m[1]);
    }

    /** «730.895092532», «1 000,50» → десятичная строка с точкой; не число — null. */
    public static function parseAmount(string $text): ?string
    {
        $normalized = str_replace(',', '.', preg_replace('/[\s\x{00A0}]+/u', '', $text) ?? '');

        return preg_match('/^\d+(\.\d+)?$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * Таблицы текста: каждая — список строк, строка — список текстов ячеек.
     *
     * @return array<int, array<int, array<int, string>>>
     */
    private static function tables(string $html): array
    {
        $tables = [];
        if (preg_match_all('~<table\b.*?</table>~isu', $html, $tableMatches) === false) {
            return [];
        }
        foreach ($tableMatches[0] as $tableHtml) {
            $rows = [];
            preg_match_all('~<tr\b.*?</tr>~isu', $tableHtml, $rowMatches);
            foreach ($rowMatches[0] as $rowHtml) {
                preg_match_all('~<t[dh]\b[^>]*>(.*?)</t[dh]>~isu', $rowHtml, $cellMatches);
                if ($cellMatches[1] === []) {
                    continue;
                }
                $rows[] = array_map([self::class, 'cellText'], $cellMatches[1]);
            }
            if ($rows !== []) {
                $tables[] = $rows;
            }
        }

        return $tables;
    }

    private static function cellText(string $cellHtml): string
    {
        $text = html_entity_decode(strip_tags($cellHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? '');
    }

    /** @param array<int, array<int, string>> $rows */
    private static function rowIndexContaining(array $rows, string $needle): ?int
    {
        foreach ($rows as $index => $cells) {
            foreach ($cells as $cell) {
                if (str_contains($cell, $needle)) {
                    return $index;
                }
            }
        }

        return null;
    }
}
