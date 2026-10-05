<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use RuntimeException;

/**
 * Включатели уведомлений о выплатах (Этап 5, docs/STAGE5_PAYMENTS.md) —
 * config/payments.php. Файла нет — всё выключено: скрипты выплат можно
 * положить на сервер заранее, они ничего не сделают, пока их не включат.
 *
 *   reminders      — напоминания накануне выплаты (R1). От НРД не
 *                    зависят, работают по графику с Мосбиржи.
 *   checks         — проверки «сообщения о получении нет»:
 *                    off     — выключены (так и должно быть, пока
 *                              сообщения НРД не поступают: иначе
 *                              тревога по каждой выплате);
 *                    admin   — списки только администратору, клиентам
 *                              ничего (обкатка первых дней после
 *                              подключения НРД);
 *                    clients — жёлтые уведомления клиентам,
 *                              администратору — только случаи «от НРД
 *                              нет вообще ничего».
 *   getnews_polling — bin/poll_getnews.php реально пишет в БД (а не
 *                     только печатает --dry-run превью). ОСТОРОЖНО:
 *                     включение сразу означает настоящие уведомления
 *                     клиентам по A2/A4/A6/B1/B2/B4/B5 — эти коды
 *                     notify_client=TRUE с самой первой миграции, их
 *                     не отключить только для одного источника.
 */
final class PaymentsConfig
{
    public const CHECKS_OFF = 'off';
    public const CHECKS_ADMIN = 'admin';
    public const CHECKS_CLIENTS = 'clients';

    public function __construct(
        public readonly bool $reminders,
        public readonly string $checks,
        public readonly bool $getNewsPolling,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self(false, self::CHECKS_OFF, false);
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException("Конфиг выплат {$path} должен возвращать массив.");
        }
        $checks = (string) ($config['checks'] ?? self::CHECKS_OFF);
        if (!in_array($checks, [self::CHECKS_OFF, self::CHECKS_ADMIN, self::CHECKS_CLIENTS], true)) {
            throw new RuntimeException("Конфиг выплат {$path}: checks должен быть off, admin или clients, получено «{$checks}».");
        }

        return new self(
            (bool) ($config['reminders'] ?? false),
            $checks,
            (bool) ($config['getnews_polling'] ?? false),
        );
    }
}
