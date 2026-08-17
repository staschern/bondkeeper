<?php

declare(strict_types=1);

namespace BondKeeper\Fns;

use RuntimeException;

/**
 * Клиент официального сервиса ФНС service.nalog.ru/bi.do — "Запрос о
 * действующих приостановлениях операций по счетам". У сервиса нет API,
 * это HTML-форма с AJAX-эндпоинтом под капотом. Контракт подтверждён
 * вживую (август 2026, bondkeeper.ru), включая проверку голым curl без
 * браузера — сервер отвечает нормальным JSON, не требует куки,
 * выставляемые JS (Яндекс.Метрика и т.п.):
 *
 *   1. GET  /bi.do        — получить свежую сессию (Set-Cookie: JSESSIONID)
 *   2. POST /bi2-proc.json — requestType=FINDPRS&innPRS=<ИНН>&bikPRS=<любой>
 *                             (остальные поля формы пустые)
 *
 * bikPRS реально не фильтрует результат — подтверждено: запрос с одним
 * БИК вернул решения по СОВСЕМ другим банкам. Нужен просто непустым.
 *
 * Капча: на практике вылезает в среднем на 3-й запрос подряд в ОДНОЙ
 * сессии (подтверждено дважды, в двух независимых браузерных сессиях —
 * 1-й и 2-й запрос проходят, 3-й требует капчу). Поэтому здесь — ОДНА
 * свежая сессия (заново GET) на КАЖДУЮ проверку, а не переиспользование
 * куки между вызовами. Это не обход защиты, а имитация обычного
 * поведения — "один человек проверяет одну компанию за раз".
 *
 * Если сервис всё равно вернул captchaRequired=true — это НЕ обрабатывается
 * здесь попыткой решить капчу (умышленно не реализовано и не будет).
 * Вызывающий код (FnsBlocksImporter) обязан пропустить эмитента в этом
 * прогоне и не писать в БД, а не гадать.
 */
final class NalogBiClient implements NalogBiClientInterface
{
    private const BASE_URL = 'https://service.nalog.ru';
    private const FORM_URL = self::BASE_URL . '/bi.do';
    private const PROC_URL = self::BASE_URL . '/bi2-proc.json';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/153.0';
    // Синтаксически валидный, но по факту не влияющий на выборку БИК —
    // см. докблок класса.
    private const PLACEHOLDER_BIK = '044525225';

    public function __construct(
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function check(string $inn): NalogBiResult
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'fns_bi_');
        if ($cookieJar === false) {
            throw new RuntimeException('Не удалось создать временный файл для cookie-jar');
        }

        try {
            $this->fetchFreshSession($cookieJar);
            $json = $this->submitQuery($inn, $cookieJar);

            return NalogBiResult::fromJson($json);
        } finally {
            @unlink($cookieJar);
        }
    }

    private function fetchFreshSession(string $cookieJar): void
    {
        $ch = curl_init(self::FORM_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || $error !== '') {
            throw new RuntimeException("bi.do: не удалось получить сессию (HTTP {$httpCode}, {$error})");
        }
    }

    /** @return array<string, mixed> */
    private function submitQuery(string $inn, string $cookieJar): array
    {
        $body = http_build_query([
            'requestType' => 'FINDPRS',
            'innPRS' => $inn,
            'bikPRS' => self::PLACEHOLDER_BIK,
            'fileName' => '',
            'bik' => '',
            'innSmev' => '',
            'kodTU' => '',
            'dateSAFN' => '',
            'bikAFN' => '',
            'dateAFN' => '',
            'fileNameED' => '',
            'captcha' => '',
            'captchaToken' => '',
        ]);

        $ch = curl_init(self::PROC_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Referer: ' . self::FORM_URL,
                'Origin: ' . self::BASE_URL,
                'X-Requested-With: XMLHttpRequest',
            ],
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new RuntimeException("bi2-proc.json: cURL ошибка: {$error}");
        }

        $decoded = json_decode((string) $raw, true);

        // Вторая, отдельная от HTTP 200 {captchaRequired:true} форма
        // капчи — подтверждена вживую (bondkeeper.ru, август 2026):
        // HTTP 400 с телом {"ERRORS":{"captcha":["Требуется ввести цифры
        // с картинки..."]}}. По смыслу это то же самое "нужна капча", а
        // не ошибка нашего запроса — приводим к общей форме
        // {captchaRequired:true}, чтобы NalogBiResult::fromJson и вызывающий
        // код (FnsBlocksImporter) не различали, откуда пришёл сигнал.
        if ($httpCode === 400 && is_array($decoded) && !empty($decoded['ERRORS']['captcha'] ?? null)) {
            return ['captchaRequired' => true];
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("bi2-proc.json вернул HTTP {$httpCode}: " . substr((string) $raw, 0, 300));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('bi2-proc.json: не удалось распарсить JSON: ' . substr((string) $raw, 0, 300));
        }

        return $decoded;
    }
}
