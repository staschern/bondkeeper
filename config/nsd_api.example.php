<?php

declare(strict_types=1);

/**
 * Логин/пароль для API НРД (nsddata.ru) — используется GetNewsConfig /
 * GetNewsClient (этап 5, см. docs/STAGE5_PAYMENTS.md). Выдаются НРД на
 * email, указанный при оформлении доступа; технически это аккаунт на
 * весь nsddata.ru (GetNews — один из нескольких сервисов под ним), а
 * не отдельный ключ только для новостей.
 *
 * СКОПИРУЙТЕ этот файл в nsd_api.php (без ".example") в этой же папке
 * и впишите реальные значения — сам nsd_api.php НЕ должен коммититься
 * в git (см. .gitignore), как и telegram_bot.php.
 *
 * Подключение проверено вживую 05.10.2026: POST /api/auth/login с
 * {login, password} отдаёт {access_token, refresh_token}; дальше
 * запросы идут с заголовком Authorization: Bearer <access_token>.
 * GetNewsClient сам логинится при первом запросе и обновляет токен
 * при 401 (сначала /api/auth/refresh, если не вышло — повторный логин).
 */

return [
    'login' => '',    // email, на который НРД выдал доступ
    'password' => '', // пароль из письма/личного кабинета НРД

    // Обычно не нужно менять — адрес сервиса не ожидается другим.
    'base_url' => 'https://nsddata.ru',
];
