<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

final class AcraEmailParser
{
    private const GRADE_VERBS = ['подтверждён', 'присвоен', 'понижен', 'повышен', 'отозван', 'пересмотрен'];

    private const MONTHS_GENITIVE = [
        'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4,
        'мая' => 5, 'июня' => 6, 'июля' => 7, 'августа' => 8,
        'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12,
    ];

    public static function parseDateHeader(string $raw): ?string
    {
        $raw = mb_strtolower(trim($raw));
        if (!preg_match('/^(\d{1,2})\s+([а-яё]+)\s+(\d{4})\s+года$/u', $raw, $m)) {
            return null;
        }

        $month = self::MONTHS_GENITIVE[$m[2]] ?? null;
        if ($month === null || !checkdate($month, (int) $m[1], (int) $m[3])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $m[3], $month, (int) $m[1]);
    }

    public static function parseActionLine(string $line): ?array
    {
        $line = trim($line);
        $parts = preg_split('/\s+—\s+/u', $line, 2);
        if ($parts === false || count($parts) !== 2) {
            return null;
        }
        [$subject, $action] = $parts;

        $primary = preg_split('/\bтакже\b/ui', $action, 2)[0];

        $verb = self::detectGradeVerb($primary);
        $watch = self::detectWatchStatus($primary);
        if ($verb === null && $watch === null) {
            return null;
        }

        $isin = null;
        if (preg_match('/\(([A-Z]{2}[A-Z0-9]{9}\d)\)/', $subject, $m)) {
            $isin = $m[1];
        }

        $baseOutlook = null;
        if (preg_match('/прогноз\s*«([^»]+)»/ui', $primary, $m)) {
            $baseOutlook = RatingsNormalizer::mapOutlook(self::fixCyrillicLookalikes($m[1]));
        }

        $outlookTo = match ($watch) {
            'set' => 'under_review',
            'concluded' => $baseOutlook ?? 'review_concluded',
            default => $baseOutlook,
        };

        return [
            'subject' => $subject,
            'isin' => $isin,
            'names' => RatingsNormalizer::extractQuotedNames($subject),
            'verb' => $verb,
            'ratingTo' => self::extractGrade($primary, $verb),
            'isExpected' => (bool) preg_match('/ожидаем\w+\s+рейтинг/ui', $primary),
            'outlookTo' => $outlookTo,
            'raw' => $line,
        ];
    }

    private static function detectGradeVerb(string $action): ?string
    {
        foreach (self::GRADE_VERBS as $verb) {
            if (preg_match('/\b' . $verb . '\w*\b/ui', $action)) {
                return $verb;
            }
        }

        return null;
    }

    private static function detectWatchStatus(string $action): ?string
    {
        if (preg_match('/снят[аы]?\s+статус\s*«?под\s+наблюдением»?/ui', $action)) {
            return 'concluded';
        }
        if (preg_match('/(установлен[а]?|продлён|продлена)\s+статус\s*«?под\s+наблюдением»?/ui', $action)) {
            return 'set';
        }

        return null;
    }

    private static function extractGrade(string $action, ?string $verb): ?string
    {
        if ($verb === 'отозван') {
            return 'отозван';
        }

        if (preg_match('/(?:на\s+уровне|до\s+уровня)\s+(e?[A-Z]{1,4}[+\-]?(?:\(RU\))?)/u', $action, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function fixCyrillicLookalikes(string $text): string
    {
        static $map = [
            'c' => 'с', 'C' => 'С', 'e' => 'е', 'E' => 'Е', 'o' => 'о', 'O' => 'О',
            'p' => 'р', 'P' => 'Р', 'a' => 'а', 'A' => 'А', 'x' => 'х', 'X' => 'Х',
            'y' => 'у', 'Y' => 'У', 'H' => 'Н', 'K' => 'К', 'M' => 'М', 'T' => 'Т', 'B' => 'В',
        ];

        return strtr($text, $map);
    }
}
