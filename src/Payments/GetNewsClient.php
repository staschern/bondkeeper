<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use BondKeeper\Support\Logger;
use RuntimeException;

/**
 * HTTP-клиент GetNews (API НРД, nsddata.ru) — тот же стиль голого cURL,
 * что Ratings\RatingsHttp/Iss\IssClient, без внешних библиотек.
 *
 * Подключение проверено вживую 05.10.2026 (без реальных логина/пароля —
 * только структура запросов/ответов, curl на реальный https://nsddata.ru):
 *   POST /api/auth/login {login, password} -> {access_token, refresh_token}
 *   (неверные данные отвечают HTTP 400 "Login failed" — не 404, путь верный)
 *   POST /api/auth/refresh {refresh_token} -> {access_token, refresh_token}
 *   GET /api/auth/check (Bearer) -> {valid: "true"|"false"}
 *   GET /api/get/news (Bearer) -> массив News (тариф Standard, который
 *   нам выдан) или NewsLite — зависит от прав аккаунта, см. OpenAPI-схему
 *   (Swagger UI: https://nsddata.ru/ru/products/api/docs, спека отдаётся
 *   с /ru/products/api/scheme).
 *
 * В ОТЛИЧИЕ от RatingsHttp/IssClient — НЕТ автоматических ретраев на
 * сетевые ошибки: это платный аккаунт с логином/паролем, а не бесплатный
 * анонимный источник, повторные попытки при сбое логина рискуют похожим
 * на brute-force поведением. Один запрос; на HTTP 401 — одна попытка
 * обновить токен (/api/auth/refresh), если не вышло — один повторный
 * логин; если и это не помогло — исключение, разбираться вручную.
 *
 * Семантика полей сообщения (что означают конкретные значения
 * data.state.code/ca_type — "получено"/"передано депонентам"/"не
 * исполнено" и т.п.) здесь НЕ реализована: ни презентация, ни PDF-словарь
 * полей, ни сама OpenAPI-схема эти значения не перечисляют (только тип
 * string, без enum) — см. docs/STAGE5_PAYMENTS.md, раздел про открытые
 * вопросы. Этот клиент — только транспорт; разбор сообщений пишется
 * после того, как bin/debug_getnews.php покажет реальные значения.
 */
final class GetNewsClient implements GetNewsClientInterface
{
    private ?string $accessToken = null;
    private ?string $refreshToken = null;

    public function __construct(
        private readonly GetNewsConfig $config,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function fetchNews(array $filter = [], int $limit = 10, int $skip = 0): array
    {
        $query = ['limit' => $limit, 'skip' => $skip];
        if ($filter !== []) {
            $query['filter'] = json_encode($filter, JSON_UNESCAPED_UNICODE);
        }

        $decoded = $this->request('GET', '/api/get/news', $query);

        return is_array($decoded) ? $decoded : [];
    }

    /** GET /api/auth/check — живой ли токен (используется bin/debug_getnews.php). */
    public function checkToken(): bool
    {
        if ($this->accessToken === null) {
            $this->login();
        }
        $response = $this->send('GET', '/api/auth/check', [], $this->accessToken);
        if ($response['http_code'] === 401) {
            return false;
        }
        $decoded = json_decode($response['body'], true);
        $valid = is_array($decoded) ? ($decoded['valid'] ?? null) : null;

        return $valid === 'true' || $valid === true;
    }

    /** @return array<string, mixed>|array<int, mixed> */
    private function request(string $method, string $path, array $query = []): array
    {
        if ($this->accessToken === null) {
            $this->login();
        }

        $response = $this->send($method, $path, $query, $this->accessToken);
        if ($response['http_code'] === 401) {
            Logger::warn("NSD API: токен истёк на {$method} {$path}, пробую обновить");
            if (!$this->refresh()) {
                $this->login();
            }
            $response = $this->send($method, $path, $query, $this->accessToken);
        }

        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            throw new RuntimeException("NSD API {$method} {$path} — HTTP {$response['http_code']}: {$response['body']}");
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException("NSD API {$method} {$path} — не удалось разобрать JSON-ответ: {$response['body']}");
        }

        return $decoded;
    }

    private function login(): void
    {
        $response = $this->send('POST', '/api/auth/login', [], null, [
            'login' => $this->config->login,
            'password' => $this->config->password,
        ]);
        if ($response['http_code'] !== 200) {
            throw new RuntimeException(
                "NSD API: не удалось авторизоваться (HTTP {$response['http_code']}: {$response['body']})"
                . ' — проверьте login/password в config/nsd_api.php'
            );
        }

        $decoded = json_decode($response['body'], true);
        $accessToken = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($accessToken === '') {
            throw new RuntimeException('NSD API: ответ /api/auth/login не содержит access_token');
        }

        $this->accessToken = $accessToken;
        $this->refreshToken = is_array($decoded) ? (string) ($decoded['refresh_token'] ?? '') : '';
    }

    private function refresh(): bool
    {
        if ($this->refreshToken === null || $this->refreshToken === '') {
            return false;
        }

        $response = $this->send('POST', '/api/auth/refresh', [], null, ['refresh_token' => $this->refreshToken]);
        if ($response['http_code'] !== 200) {
            return false;
        }

        $decoded = json_decode($response['body'], true);
        $accessToken = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($accessToken === '') {
            return false;
        }

        $this->accessToken = $accessToken;
        $this->refreshToken = is_array($decoded) ? (string) ($decoded['refresh_token'] ?? $this->refreshToken) : $this->refreshToken;

        return true;
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, scalar>|null $jsonBody
     * @return array{http_code: int, body: string}
     */
    private function send(string $method, string $path, array $query, ?string $bearerToken = null, ?array $jsonBody = null): array
    {
        $url = $this->config->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['Accept: application/json'];
        if ($bearerToken !== null) {
            $headers[] = "Authorization: Bearer {$bearerToken}";
        }
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'BondKeeper/1.0 (GetNews, этап 5)',
        ];
        if ($jsonBody !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlError !== '') {
            throw new RuntimeException("NSD API: cURL-ошибка для {$method} {$path}: {$curlError}");
        }

        return ['http_code' => $httpCode, 'body' => $body];
    }
}
