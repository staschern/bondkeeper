<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

/**
 * Интерфейс клиента GetNews (API НРД, nsddata.ru) — по образцу
 * Telegram\TelegramClientInterface: позволяет подменить фейком в
 * офлайн-тестах будущего разбора сообщений, не трогая реальный HTTP.
 */
interface GetNewsClientInterface
{
    /**
     * GET /api/get/news — сырые сообщения, как их отдаёт НРД (схема
     * News при тарифе Standard, который нам выдан), без какой-либо
     * нашей интерпретации.
     *
     * @param array<string, mixed> $filter MongoDB-подобный фильтр, как в
     *     документации API, например ['category' => 'CORP_ACTION'] или
     *     ['pub_date' => ['$gte' => '2026-10-01', '$lte' => '2026-10-02']].
     * @return array<int, array<string, mixed>>
     */
    public function fetchNews(array $filter = [], int $limit = 10, int $skip = 0): array;
}
