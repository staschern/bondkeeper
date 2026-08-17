<?php

declare(strict_types=1);

namespace BondKeeper\Fns;

/**
 * Разбор ответа /bi2-proc.json service.nalog.ru — три подтверждённых вживую
 * формы (август 2026):
 *   1. Есть активные блокировки: ключ 'rows' — массив решений.
 *   2. Блокировок нет: ключа 'rows' в ответе вообще НЕТ (не пустой массив).
 *   3. Нужна капча: ключа 'rows' тоже нет, но 'captchaRequired' = true.
 * Формы 2 и 3 неразличимы по одному только отсутствию 'rows' — поэтому
 * $captchaRequired проверяется отдельно и в первую очередь.
 */
final class NalogBiResult
{
    /** @param array<int, array<string, mixed>> $rows */
    private function __construct(
        public readonly bool $captchaRequired,
        public readonly array $rows,
    ) {
    }

    /** @param array<string, mixed> $json */
    public static function fromJson(array $json): self
    {
        $captchaRequired = ($json['captchaRequired'] ?? false) === true;
        $rows = isset($json['rows']) && is_array($json['rows']) ? $json['rows'] : [];

        return new self($captchaRequired, $rows);
    }
}
