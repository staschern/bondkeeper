<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use BondKeeper\Support\Logger;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * HTTP-клиент для raexpert.ru (Эксперт РА) — единственное из 4 агентств,
 * где ИНН не выдаётся ни выгрузкой (Excel-экспорт требует авторизации в
 * личном кабинете — "unauthSubmite()" на кнопке), ни строкой в таблице
 * списка рейтингов (там только название компании, рейтинг, прогноз,
 * дата). ИНН находится только на отдельной странице карточки компании —
 * т.е. один HTTP-запрос на КАЖДУЮ компанию, сверх постраничного обхода
 * самого списка. См. docs/STAGE3_RATINGS.md.
 *
 * Пагинация списка — не по URL-параметру, а через cookie-сессию:
 * клик по номеру страницы шлёт POST на /ratings/index/ajax-set-rating-page-hash/
 * с непрозрачным хэшем (взят из onclick пагинатора уже загруженной
 * страницы) и CSRF-токеном, после чего повторный GET того же URL отдаёт
 * уже следующую страницу. Проверено вживую curl'ом с cookie jar перед
 * тем, как это писать (см. STAGE3_RATINGS.md) — реально работает.
 *
 * Хэши со страницы 1 покрывали все страницы категории в наблюдавшихся
 * случаях (страницы с ~10 страницами пагинации show все номера сразу,
 * без "плавающего окна"), но код не полагается на это молча: хэши
 * пагинатора перечитываются после КАЖДОЙ загруженной страницы и новые
 * добавляются в очередь обхода — так корректно обрабатывается и
 * "окно" пагинации, если оно где-то всё-таки есть.
 */
final class ExpertRaClient
{
    private const BASE_URL = 'https://raexpert.ru';
    private const USER_AGENT = 'Mozilla/5.0 (compatible; BondKeeperBot/1.0; +data seeding, stage 3)';
    private const MAX_PAGES_PER_CATEGORY = 60;

    /**
     * @return array<int, array{name: string, card_url: string, rating: string, outlook: string, date: string}>
     */
    public function fetchCategoryRows(string $categorySlug, int $delayMicroseconds): array
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'bondkeeper_re_cookies_');
        if ($cookieJar === false) {
            throw new RuntimeException('Не удалось создать временный файл для cookie jar');
        }

        try {
            $listingUrl = self::BASE_URL . "/ratings/{$categorySlug}/";
            $html = $this->get($listingUrl, $cookieJar);
            $rows = $this->parseRows($html);

            // Хэши пагинации ОДНОРАЗОВЫЕ/меняются при каждой перезагрузке
            // страницы (подтверждено вживую: без этого код зацикливался,
            // принимая заново сгенерированный хэш той же самой страницы за
            // "ещё не посещённую" — потому что значение хэша каждый раз
            // новое). Поэтому дедуп — по ВИДИМОЙ подписи ссылки пагинатора
            // ("2", "3", ..., "»"), а не по значению хэша, и хэш для
            // перехода берётся каждый раз ЗАНОВО из только что загруженной
            // страницы, а не из очереди, накопленной на предыдущих шагах.
            $visitedLabels = ['1' => true]; // страница 1 уже получена выше
            $guard = 0;

            while ($guard < self::MAX_PAGES_PER_CATEGORY) {
                $csrf = $this->extractCsrf($html);
                if ($csrf === null) {
                    break;
                }

                $nextLabel = null;
                $nextHash = null;
                foreach ($this->extractPageLinks($html) as $label => $hash) {
                    if (!isset($visitedLabels[$label])) {
                        $nextLabel = $label;
                        $nextHash = $hash;
                        break;
                    }
                }
                if ($nextLabel === null) {
                    break;
                }

                $visitedLabels[$nextLabel] = true;
                $guard++;

                usleep($delayMicroseconds);
                $this->postPageHash($nextHash, $csrf, $cookieJar);
                usleep($delayMicroseconds);
                $html = $this->get($listingUrl, $cookieJar);
                $rows = array_merge($rows, $this->parseRows($html));
            }

            if ($guard >= self::MAX_PAGES_PER_CATEGORY) {
                Logger::warn("Эксперт РА ({$categorySlug}): достигнут предохранитель в " . self::MAX_PAGES_PER_CATEGORY . ' страниц — обход категории остановлен досрочно');
            }

            return $rows;
        } finally {
            @unlink($cookieJar);
        }
    }

    /**
     * Карточка компании — обычная страница без сессии/куки, отдельный
     * GET. Пустой результат (не найден блок "Реквизиты ИНН ...") —
     * ожидаемо для иностранных юрлиц без российской регистрации
     * (проверено вживую на "Rissa Investments Limited") — не ошибка.
     */
    public function fetchCompanyInn(string $cardUrl): ?string
    {
        $html = RatingsHttp::get($cardUrl);
        $text = $this->toPlainText($html);

        if (preg_match('/Реквизиты.{0,60}?ИНН\s*(\d{10,12})/su', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Лента пресс-релизов (/news/ — вкладка "Пресс-релизы", подтверждена
     * вживую как содержащая рейтинговые действия, см. STAGE3_RATINGS.md).
     * Подгружается через AJAX постранично (jscroll на сайте): GET
     * /articles/news/ajax-get-news/?page=N&CSRFToken=... — токен читается
     * с исходной страницы /news/ (та же cookie-сессия), пустой ответ
     * означает "дальше страниц нет". В отличие от списка рейтингов, ИНН
     * тут никогда не даётся — только название компании в тексте, поэтому
     * сопоставление у вызывающего кода идёт по названию, не по ИНН.
     *
     * $stopBeforeDate (ISO-формат ГГГГ-ММ-ДД, не формат самой ленты) —
     * если задан, обход останавливается, как только на очередной
     * странице не осталось ни одной новости С ЭТОЙ ДАТОЙ ИЛИ ПОЗЖЕ (лента
     * отсортирована по убыванию даты). Без этого инкрементальность на
     * стороне вызывающего кода была бы бессмысленна: страницы всё равно
     * приходилось бы скачивать ВСЕ при каждом прогоне, только чтобы потом
     * отбросить бо́льшую часть.
     *
     * @return array<int, array{title: string, subtitle: string, url: string, date: string}>
     */
    public function fetchNewsItems(int $delayMicroseconds, ?string $stopBeforeDate = null, int $maxPages = 500): array
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'bondkeeper_re_news_cookies_');
        if ($cookieJar === false) {
            throw new RuntimeException('Не удалось создать временный файл для cookie jar');
        }

        try {
            $html = $this->get(self::BASE_URL . '/news/', $cookieJar);
            $csrf = $this->extractNewsCsrf($html);
            if ($csrf === null) {
                throw new RuntimeException('Эксперт РА: не нашёлся CSRFToken для ленты новостей — вёрстка страницы могла измениться');
            }

            $items = [];
            for ($page = 1; $page <= $maxPages; $page++) {
                usleep($delayMicroseconds);
                $ajaxUrl = self::BASE_URL . "/articles/news/ajax-get-news/?page={$page}&CSRFToken={$csrf}";
                $chunk = $this->get($ajaxUrl, $cookieJar);
                if (trim($chunk) === '') {
                    break;
                }

                $pageItems = $this->parseNewsChunk($chunk);
                $items = array_merge($items, $pageItems);

                if ($stopBeforeDate !== null && $this->allOlderThan($pageItems, $stopBeforeDate)) {
                    break;
                }
            }

            return $items;
        } finally {
            @unlink($cookieJar);
        }
    }

    /**
     * @param array<int, array{date: string}> $items
     * @param string $stopBeforeDateIso дата в ISO-формате (ГГГГ-ММ-ДД) — строки этого формата сравнимы как обычный текст
     */
    private function allOlderThan(array $items, string $stopBeforeDateIso): bool
    {
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            $parsed = RatingsNormalizer::parseDate($item['date']);
            if ($parsed !== null && $parsed >= $stopBeforeDateIso) {
                return false;
            }
        }

        return true;
    }

    private function extractNewsCsrf(string $html): ?string
    {
        if (preg_match('/ajax-get-news\/\?page="\s*\+\s*page\s*\+\s*"&CSRFToken=([^"]+)"/', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<int, array{title: string, subtitle: string, url: string, date: string}>
     */
    private function parseNewsChunk(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        // contains(@class, "b-articles-list-item") ловит и сам контейнер
        // одной новости, И его дочерний div class="b-articles-list-item__info"
        // (это ПОДСТРОКА, не точное совпадение класса) — без явной проверки
        // границ токена одна и та же новость попадала бы в список дважды
        // (подтверждено вживую при первой проверке).
        $itemClassExact = "contains(concat(' ', normalize-space(@class), ' '), ' b-articles-list-item ')";

        $items = [];
        foreach ($xpath->query("//*[{$itemClassExact}]") as $item) {
            $titleLinks = $xpath->query('.//a[@href]', $item);
            if ($titleLinks->length === 0) {
                continue;
            }
            $titleLink = $titleLinks->item(0);
            $title = trim(preg_replace('/\s+/u', ' ', $titleLink->textContent) ?? '');
            $href = $titleLink->getAttribute('href');

            $subtitleNodes = $xpath->query('.//*[contains(@class, "b-articles-list-item__subtitle")]', $item);
            $subtitle = $subtitleNodes->length > 0
                ? trim(preg_replace('/\s+/u', ' ', $subtitleNodes->item(0)->textContent) ?? '')
                : '';

            $timeNodes = $xpath->query('.//*[contains(@class, "b-articles-list-item__time")]', $item);
            $date = $timeNodes->length > 0
                ? trim(preg_replace('/\s+/u', ' ', $timeNodes->item(0)->textContent) ?? '')
                : '';

            if ($title === '' || $href === '' || $date === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'subtitle' => $subtitle,
                'url' => self::BASE_URL . $href,
                'date' => $date,
            ];
        }

        return $items;
    }

    private function get(string $url, string $cookieJar): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_USERAGENT => self::USER_AGENT,
            // Нужен для AJAX-эндпоинтов сайта (подтверждено вживую — без
            // него /articles/news/ajax-get-news/ отдаёт пустой ответ);
            // на обычных страницах безвреден, поэтому шлём его всегда,
            // а не только на AJAX-запросах.
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException("cURL ошибка для {$url}: {$error}");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("{$url} — HTTP {$httpCode}");
        }

        return $body;
    }

    private function postPageHash(string $hash, string $csrf, string $cookieJar): void
    {
        $ch = curl_init(self::BASE_URL . '/ratings/index/ajax-set-rating-page-hash/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'rating_page_hash' => $hash,
                'CSRFAjaxToken' => $csrf,
            ]),
            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
        ]);
        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new RuntimeException("Эксперт РА: не удалось переключить страницу пагинации: {$error}");
        }
    }

    private function extractCsrf(string $html): ?string
    {
        if (preg_match("/CSRFAjaxTokenPageHash\s*=\s*'([^']+)'/", $html, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Ссылки пагинатора текущей загруженной страницы: видимая подпись
     * ("2", "3", ..., "»" у последней) => хэш перехода. Подпись — то, что
     * реально видит браузер (текст внутри <span>), а не порядковый номер
     * из документа — так безопаснее, если разметка когда-нибудь изменит
     * порядок.
     *
     * @return array<string, string>
     */
    private function extractPageLinks(string $html): array
    {
        preg_match_all(
            "/<span[^>]*onclick=\"setRatingPageHash\('([^']+)'\);\"[^>]*>([^<]*)<\/span>/u",
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $links = [];
        foreach ($matches as $match) {
            $label = trim($match[2]);
            if ($label !== '') {
                $links[$label] = $match[1];
            }
        }

        return $links;
    }

    /**
     * @return array<int, array{name: string, card_url: string, rating: string, outlook: string, date: string}>
     */
    private function parseRows(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $rows = [];
        foreach ($xpath->query('//table//tr') as $tr) {
            $cells = $xpath->query('./td', $tr);
            if ($cells->length < 4) {
                continue;
            }

            $links = $xpath->query('.//a[contains(@href, "/database/companies/")]', $cells->item(0));
            if ($links->length === 0) {
                continue;
            }
            $link = $links->item(0);
            $href = $link->getAttribute('href');
            $name = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');
            if ($name === '' || $href === '') {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'card_url' => self::BASE_URL . $href,
                'rating' => trim(preg_replace('/\s+/u', ' ', $cells->item(1)->textContent) ?? ''),
                'outlook' => trim(preg_replace('/\s+/u', ' ', $cells->item(2)->textContent) ?? ''),
                'date' => trim(preg_replace('/\s+/u', ' ', $cells->item(3)->textContent) ?? ''),
            ];
        }

        return $rows;
    }

    private function toPlainText(string $html): string
    {
        $html = preg_replace('/<script.*?<\/script>/su', ' ', $html) ?? $html;
        $html = preg_replace('/<style.*?<\/style>/su', ' ', $html) ?? $html;
        $text = preg_replace('/<[^>]+>/', ' ', $html) ?? $html;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
