<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use RuntimeException;

/**
 * Общий HTTP-загрузчик для сайтов рейтинговых агентств — по духу как
 * Iss\IssClient, но возвращает сырые байты (Excel-выгрузки, HTML-страницы),
 * а не декодированный JSON: у каждого агентства свой формат ответа.
 * Такие же ретраи, как у IssClient — сайты агентств не рассчитаны на
 * автоматизированный доступ так же надёжно, как биржевой API.
 */
final class RatingsHttp
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_SECONDS = 2;

    public static function get(string $url, int $timeoutSeconds = 30): string
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $result = self::fetch($url, $timeoutSeconds);
            if ($result !== null) {
                return $result;
            }
            $lastError = "попытка {$attempt}/" . self::MAX_RETRIES . ' неудачна';
            Logger::warn("Рейтинговое агентство {$url} — {$lastError}");
            if ($attempt < self::MAX_RETRIES) {
                sleep(self::RETRY_DELAY_SECONDS * $attempt);
            }
        }

        throw new RuntimeException("Не удалось получить ответ: {$url} ({$lastError})");
    }

    private static function fetch(string $url, int $timeoutSeconds): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BondKeeperBot/1.0; +data seeding, stage 3)',
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlError !== '') {
            Logger::warn("cURL ошибка для {$url}: {$curlError}");
            return null;
        }
        if ($httpCode !== 200) {
            Logger::warn("{$url} — HTTP {$httpCode}");
            return null;
        }

        return $body;
    }
}
