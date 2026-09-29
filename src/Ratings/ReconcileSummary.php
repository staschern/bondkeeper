<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

/**
 * Текст сводки сверки для администратора (Telegram) — из результата
 * CurrentRatingsReconciler::reconcile(). Чистая функция, без БД и сети.
 *
 * По итогам первой живой сводки (28.09.2026): раньше приходили первые 10
 * строк "по полю" и отсылка к логу, которого администратор не видит.
 * Теперь:
 *   - все строки, одна строка на компанию (все поля сразу, "у нас → у
 *     агентства"), значения по-русски, даты ДД.ММ.ГГГГ;
 *   - название у агентства, если оно другое (компании со связкой —
 *     ЕвразХолдинг Финанс ↔ ПАО «ЕВРАЗ»);
 *   - откуда наше значение: выгрузка, новость (с заголовком и ссылкой),
 *     ручной ввод, сверка;
 *   - деление на "требуют внимания" и "ожидаемые". Расхождение по полям
 *     ожидаемо, если наше значение пришло из новости о рейтинге выпуска
 *     облигаций (у выпуска нет прогноза, а дата новее даты рейтинга
 *     компании — ИКС 5 ФИНАНС, Мэйл.Ру Финанс, Синара-ТМ).
 */
final class ReconcileSummary
{
    private const FIELD_LABELS = ['rating' => 'рейтинг', 'outlook' => 'прогноз', 'last_action_date' => 'дата'];
    private const OUTLOOK_LABELS = [
        'positive' => 'позитивный',
        'stable' => 'стабильный',
        'negative' => 'негативный',
        'developing' => 'развивающийся',
        'indefinite' => 'неопределённый',
        'under_review' => 'под наблюдением / на пересмотре',
        'under_review_negative' => 'под наблюдением / на пересмотре (возможно понижение)',
        'under_review_positive' => 'под наблюдением / на пересмотре (возможно повышение)',
        'under_review_stable' => 'под наблюдением / на пересмотре (стабильный)',
        'under_review_developing' => 'под наблюдением / на пересмотре (развивающийся)',
        'under_review_indefinite' => 'под наблюдением / на пересмотре (неопределённый)',
        'review_concluded' => 'пересмотр завершён',
    ];
    private const SOURCE_LABELS = [
        'snapshot' => 'выгрузка агентства',
        'action' => 'новость',
        'manual' => 'ручной ввод',
        'reconcile' => 'добавлено сверкой',
    ];

    /**
     * @param array{
     *     snapshot_count: int,
     *     field_mismatches: array<int, array{issuer_id: int, our_name: string, agency_name: string, field: string, ours: ?string, theirs: ?string, our_source: ?string, our_action_title: ?string, our_action_url: ?string}>,
     *     missing_in_ours: array<int, array{issuer_id: int, our_name: string, agency_name: string, rating: string, outlook: ?string, last_action_date: string}>,
     *     missing_in_snapshot: array<int, array{issuer_id: int, our_name: string, ours: ?string, last_action_date: ?string, source: ?string, expected: bool, reason: ?string, note: ?string}>
     * } $result
     * @return array<int, string>
     */
    public static function lines(string $label, array $result): array
    {
        $attention = [];
        $expected = [];

        foreach (self::groupByIssuer($result['field_mismatches']) as $group) {
            $first = $group[0];
            $changes = array_map(
                static fn (array $d): string => self::FIELD_LABELS[$d['field']] . ' ' . self::value($d['field'], $d['ours']) . ' → ' . self::value($d['field'], $d['theirs']),
                $group,
            );
            $line = '• ' . self::issuer($first['issuer_id'], $first['our_name'], $first['agency_name']) . ': ' . implode('; ', $changes);

            if ($first['our_source'] === 'action' && RatingsNormalizer::isBondIssueRatingTitle((string) $first['our_action_title'])) {
                $expected[] = $line . ' · наше — из новости о рейтинге выпуска облигаций';
                continue;
            }
            $attention[] = $line . ' · наше: ' . self::source($first['our_source'], $first['our_action_title'], $first['our_action_url']);
        }

        foreach ($result['missing_in_snapshot'] as $d) {
            $line = '• ' . self::issuer($d['issuer_id'], $d['our_name'], null) . ': нет в снимке агентства, у нас '
                . self::value('rating', $d['ours']) . ' от ' . self::value('last_action_date', $d['last_action_date']);
            if ($d['expected']) {
                $expected[] = $line . ' · ' . $d['reason'];
                continue;
            }
            $attention[] = $line . ' · наше: ' . self::source($d['source'], null, null) . ($d['note'] !== null ? " · {$d['note']}" : '');
        }

        $missingInOurs = array_map(
            static fn (array $d): string => '• ' . self::issuer($d['issuer_id'], $d['our_name'], $d['agency_name']) . ': у агентства '
                . self::value('rating', $d['rating']) . ', ' . self::value('outlook', $d['outlook']) . ', ' . self::value('last_action_date', $d['last_action_date']),
            $result['missing_in_ours'],
        );

        $fieldCompanies = count(self::groupByIssuer($result['field_mismatches']));
        $expectedMissing = count(array_filter($result['missing_in_snapshot'], static fn (array $d): bool => $d['expected']));
        $lines = [
            "{$label} (у нас → у агентства): в снимке {$result['snapshot_count']}; компаний с расхождениями по полям: {$fieldCompanies}; "
            . 'нет у нас ' . count($result['missing_in_ours']) . '; нет в снимке ' . count($result['missing_in_snapshot']) . " (ожидаемых {$expectedMissing})",
        ];
        foreach (['Требуют внимания' => $attention, 'Ожидаемые' => $expected, 'Есть у агентства, нет у нас (добавит перезапись)' => $missingInOurs] as $title => $items) {
            if ($items !== []) {
                $lines[] = '';
                $lines[] = $title . ' (' . count($items) . '):';
                array_push($lines, ...$items);
            }
        }

        return $lines;
    }

    /**
     * @param array<int, array{issuer_id: int}> $fieldMismatches
     * @return array<int, array<int, array<string, mixed>>> строки, сгруппированные по issuer_id, в исходном порядке
     */
    private static function groupByIssuer(array $fieldMismatches): array
    {
        $groups = [];
        foreach ($fieldMismatches as $d) {
            $groups[$d['issuer_id']][] = $d;
        }

        return array_values($groups);
    }

    private static function issuer(int $issuerId, string $ourName, ?string $agencyName): string
    {
        $text = "{$ourName} (id {$issuerId})";
        if ($agencyName !== null && $agencyName !== ''
            && IssuerMatcher::normalizeCompanyName($agencyName) !== IssuerMatcher::normalizeCompanyName($ourName)) {
            $text .= " [у агентства — {$agencyName}]";
        }

        return $text;
    }

    private static function value(string $field, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($field) {
            'outlook' => self::OUTLOOK_LABELS[$value] ?? $value,
            'last_action_date' => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) ? "{$m[3]}.{$m[2]}.{$m[1]}" : $value,
            default => $value,
        };
    }

    private static function source(?string $source, ?string $actionTitle, ?string $actionUrl): string
    {
        $text = $source !== null ? (self::SOURCE_LABELS[$source] ?? $source) : 'источник не указан (запись до миграции 025)';
        if ($source === 'action' && $actionTitle !== null) {
            $text .= " «{$actionTitle}»" . ($actionUrl !== null ? " {$actionUrl}" : '');
        }

        return $text;
    }
}
