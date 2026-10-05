<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use RuntimeException;

/**
 * Логин/пароль для API НРД (nsddata.ru) — вынесены из кода в отдельный
 * конфиг-файл (config/nsd_api.php), как config/telegram_bot.php: секрет
 * отдельно от логики, реальный файл не коммитится в git, в репозитории —
 * только config/nsd_api.example.php (шаблон без настоящих значений).
 */
final class GetNewsConfig
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $login,
        public readonly string $password,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                "Не найден конфиг API НРД: {$path}. Скопируйте config/nsd_api.example.php"
                . ' в config/nsd_api.php и впишите login/password, выданные НРД (не коммитить в git).'
            );
        }

        /** @var array<string, mixed> $config */
        $config = require $path;
        $login = trim((string) ($config['login'] ?? ''));
        $password = trim((string) ($config['password'] ?? ''));
        if ($login === '' || $password === '') {
            throw new RuntimeException("config/nsd_api.php не содержит непустые 'login'/'password' — см. {$path} рядом (.example.php) для образца.");
        }
        $baseUrl = rtrim(trim((string) ($config['base_url'] ?? 'https://nsddata.ru')), '/');

        return new self($baseUrl, $login, $password);
    }
}
