<?php

declare(strict_types=1);

namespace BondKeeper\Payments;

use RuntimeException;

/**
 * Включатели уведомлений о выплатах (Этап 5, docs/STAGE5_PAYMENTS.md) —
 * config/payments.php. Файла нет — всё выключено: скрипты выплат можно
 * положить на сервер заранее, они ничего не сделают, пока их не включат.
 *
 *   reminders      — напоминания в последний рабочий день перед
 *                    выплатой (R1). От НРД не
 *                    зависят, работают по графику с Мосбиржи.
 *   offer_reminders — напоминания о предстоящей оферте (R2), по
 *                    умолчанию за 30 календарных дней. Создаёт тот же
 *                    скрипт, что и напоминания о выплатах.
 *   offer_put_reminders_from — разовая настройка первого запуска
 *                    напоминаний об офертах (решение пользователя,
 *                    12.10.2026): о путах с датой оферты раньше этой
 *                    даты не напоминаем. Ставится «день включения
 *                    плюс 10 дней»: о путах, до которых в момент
 *                    включения осталось меньше 10 дней, напоминать
 *                    уже поздно. Через 10 дней ни на что не влияет.
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
        public readonly bool $offerReminders = false,
        public readonly ?string $offerPutRemindersFrom = null,
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

        $putFrom = $config['offer_put_reminders_from'] ?? null;
        if ($putFrom !== null && (!is_string($putFrom) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $putFrom) !== 1 || date('Y-m-d', (int) strtotime($putFrom)) !== $putFrom)) {
            throw new RuntimeException("Конфиг выплат {$path}: offer_put_reminders_from должен быть датой вида '2026-10-23' (в кавычках).");
        }

        return new self(
            (bool) ($config['reminders'] ?? false),
            $checks,
            (bool) ($config['getnews_polling'] ?? false),
            (bool) ($config['offer_reminders'] ?? false),
            $putFrom,
        );
    }
}
