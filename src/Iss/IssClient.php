<?php

declare(strict_types=1);

namespace BondKeeper\Iss;

use BondKeeper\Support\Logger;
use RuntimeException;

/**
 * Тонкий HTTP-клиент поверх ISS API Московской биржи (iss.moex.com) —
 * бесплатного, не требующего авторизации источника, который мы используем
 * для сидирования справочника на этапе 1 (issuers/securities/coupons/
 * amortizations/redemptions/offers), без обращения к платным источникам
 * (НРД, e-disclosure) — те относятся к событийному движку следующего этапа.
 *
 * ВАЖНО: этот клиент не был протестирован против реального iss.moex.com —
 * песочница, в которой писался этот код, не имеет исходящего доступа в
 * интернет (см. README.md, раздел "Что не проверено вживую"). Перед боевым
 * запуском нужно свериться с реальными ответами API на нескольких ISIN и
 * поправить парсинг блоков при расхождениях.
 *
 * Класс намеренно не final: SecuritiesImporter/BondizationImporter получают
 * его через конструктор, что позволяет подставить тестовый двойник —
 * так весь путь "распарсили ответ -> записали в БД" можно проверять без
 * реального сетевого доступа (см. README.md, "Как это было проверено").
 */
class IssClient
{
    private const DEFAULT_BASE_URL = 'https://iss.moex.com/iss';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_SECONDS = 2;

    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    /**
     * Выполняет GET-запрос к ISS API и возвращает декодированный JSON.
     *
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $query['iss.meta'] = 'off';
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/') . '?' . http_build_query($query);

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $result = $this->fetch($url);
            if ($result !== null) {
                return $result;
            }
            $lastError = "попытка {$attempt}/" . self::MAX_RETRIES . " неудачна";
            Logger::warn("ISS API {$url} — {$lastError}");
            if ($attempt < self::MAX_RETRIES) {
                sleep(self::RETRY_DELAY_SECONDS * $attempt);
            }
        }

        throw new RuntimeException("Не удалось получить ответ от ISS API: {$url} ({$lastError})");
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'BondKeeper/1.0 (data seeding, stage 1)',
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
            Logger::warn("ISS API вернул HTTP {$httpCode} для {$url}");
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::warn("Не удалось распарсить JSON от {$url}");
            return null;
        }

        return $decoded;
    }

    /**
     * ISS API отдаёт каждый блок данных как {"columns": [...], "data": [[...], ...]}.
     * Преобразует это в массив ассоциативных строк (имя колонки => значение),
     * чтобы дальше не завязываться на порядок колонок.
     *
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    public static function block(array $response, string $blockName): array
    {
        if (!isset($response[$blockName]['columns'], $response[$blockName]['data'])) {
            return [];
        }

        $columns = $response[$blockName]['columns'];
        $rows = [];
        foreach ($response[$blockName]['data'] as $rawRow) {
            $rows[] = array_combine($columns, $rawRow);
        }

        return $rows;
    }
}
