<?php

declare(strict_types=1);

namespace BondKeeper\Fns;

/**
 * Разбор ответа /bi2-proc.json service.nalog.ru — три подтверждённых вживую
 * формы (август 2026):
 *   1. Есть активные блокировки: ключ 'rows' — массив решений.
 *   2. Блокировок нет: ключа 'rows' в ответе вообще НЕТ (не пустой массив).
 *   3. Нужна капча: ключа 'rows' тоже нет, но 'captchaRequired' = true.
 *
 * Уточнение по бою (14.10.2026): признак 'captchaRequired' сервис ставит и в
 * обычный ответ «блокировок нет». После того как 13.10 ответ «капча и ни
 * одной строки» стали считать неудачной попыткой, все 58 отслеживаемых
 * эмитентов без блокировок перестали проходить проверку, а все 6 с
 * блокировками проходили, — значит, формы 2 и 3 по признаку капчи не
 * различить. Настоящий отказ — HTTP 400 «Требуется ввести цифры с
 * картинки»: NalogBiClient помечает его ключом 'refused' => true, это
 * $refused. Признак капчи без отказа ($captchaRequired) теперь только
 * пишется в журнал и в отчёт прогона.
 */
final class NalogBiResult
{
    /** @param array<int, array<string, mixed>> $rows */
    private function __construct(
        public readonly bool $captchaRequired,
        public readonly array $rows,
        public readonly bool $refused = false,
    ) {
    }

    /** @param array<string, mixed> $json */
    public static function fromJson(array $json): self
    {
        $captchaRequired = ($json['captchaRequired'] ?? false) === true;
        $rows = isset($json['rows']) && is_array($json['rows']) ? $json['rows'] : [];
        $refused = ($json['refused'] ?? false) === true;

        return new self($captchaRequired, $rows, $refused);
    }
}
