<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use Generator;

/**
 * Постраничная загрузка GetNews: один запрос отдаёт не больше 1000
 * сообщений (ограничение API НРД, параметр limit), а в сутки их выходит
 * 260–420 только по корпоративным действиям — окно в две недели в один
 * запрос не помещается. Первая выгрузка тестового доступа (05.10.2026,
 * запрошено 18.09–02.10) вернула ровно 1000 самых свежих сообщений,
 * покрывших всего два с половиной дня.
 *
 * Листаем параметром skip, пока страница приходит полной. Сообщения
 * отдаются от новых к старым, поэтому новое сообщение, появившееся между
 * запросами, сдвигает остальные на одну позицию — на следующей странице
 * придёт уже виденное. Такие повторы отсекаются по content_id_out (тот же
 * ключ, по которому PaymentProcessor отсекает повторы между прогонами).
 *
 * Страницы отдаются по одной (генератор): вызывающий код сразу пишет
 * страницу в файл или обрабатывает и не держит в памяти всё окно —
 * тысяча сообщений вместе с HTML-телами занимает около 18 МБ.
 */
final class GetNewsPager
{
    /** Больше одной страницы API не отдаёт (руководство пользователя API NSD, параметр limit). */
    public const MAX_PAGE_SIZE = 1000;

    private bool $complete = true;
    private int $requests = 0;
    private int $duplicates = 0;

    public function __construct(
        private readonly GetNewsClientInterface $client,
        private readonly int $pageSize = self::MAX_PAGE_SIZE,
        private readonly int $maxPages = 30,
        private readonly int $pauseSeconds = 1,
    ) {
    }

    /**
     * @param array<string, mixed> $filter фильтр как в GetNewsClientInterface::fetchNews()
     * @return Generator<int, array<int, array<string, mixed>>> номер страницы (с 1) => новые сообщения этой страницы
     */
    public function pages(array $filter): Generator
    {
        $pageSize = max(1, min($this->pageSize, self::MAX_PAGE_SIZE));
        $this->complete = true;
        $this->requests = 0;
        $this->duplicates = 0;
        $seen = [];

        for ($page = 1; ; $page++) {
            if ($page > $this->maxPages) {
                // Упёрлись в предохранитель, а страницы ещё шли полными.
                $this->complete = false;

                return;
            }
            if ($page > 1 && $this->pauseSeconds > 0) {
                sleep($this->pauseSeconds);
            }

            $items = $this->client->fetchNews($filter, $pageSize, ($page - 1) * $pageSize);
            $this->requests++;

            $fresh = [];
            foreach ($items as $item) {
                $key = (string) ($item['content_id_out'] ?? $item['id'] ?? '');
                if ($key !== '') {
                    if (isset($seen[$key])) {
                        $this->duplicates++;
                        continue;
                    }
                    $seen[$key] = true;
                }
                $fresh[] = $item;
            }
            if ($fresh !== []) {
                yield $page => $fresh;
            }

            if (count($items) < $pageSize) {
                return;
            }
        }
    }

    /** false — остановились на предохранителе maxPages, окно получено не целиком. */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function requestsMade(): int
    {
        return $this->requests;
    }

    /** Сколько сообщений пришло повторно из-за сдвига страниц и было отброшено. */
    public function duplicatesSkipped(): int
    {
        return $this->duplicates;
    }
}
