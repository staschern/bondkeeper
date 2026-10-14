<?php

declare(strict_types=1);

namespace BondKeeper\Partners;

use PDO;
use PDOException;

/**
 * Партнёрская программа (решения пользователя от 13.10.2026,
 * docs/PARTNER_PROGRAM.md): кто партнёр, какой у него промокод, кого он
 * привёл и сколько эти клиенты заплатили.
 *
 * Партнёр — пользователь бота, которому администратор назначил промокод
 * (таблица partners). Клиент получает промокод одним из двух способов:
 * приходит по ссылке t.me/бот?start=КОД или сам присылает код сообщением.
 * В обоих случаях у клиента записывается, кто его привёл
 * (users.referred_by_user_id), каким кодом (used_referral_code), откуда
 * взят код (referral_source) и когда (referred_at).
 *
 * Правила:
 *   - промокод записывается один раз и не меняется;
 *   - свой собственный промокод применить нельзя;
 *   - скидка по промокоду — только на первую оплату: после неё
 *     (users.first_paid_at заполнен) промокод не принимается;
 *   - по приостановленному партнёру (status = paused) новые клиенты не
 *     записываются, прежние остаются за ним.
 *
 * Оплаты в продукте пока нет: таблица payments пуста, сумма оплат в отчёте
 * будет нулевой до её появления. Отчёт к этому готов — считает успешные
 * платежи приведённых клиентов за месяц.
 *
 * Запросы переносимые (MySQL и SQLite) — офлайн-тест:
 * tests/test_partner_program.php.
 */
final class PartnerProgram
{
    public const SOURCE_LINK = 'link';
    public const SOURCE_MANUAL = 'manual';

    /** Результаты applyCode(). */
    public const APPLIED = 'applied';
    public const NOT_FOUND = 'not_found';
    public const OWN_CODE = 'own_code';
    public const ALREADY_SET = 'already_set';
    public const AFTER_PAYMENT = 'after_payment';

    /** Результаты assign(). */
    public const ASSIGN_CREATED = 'created';
    public const ASSIGN_UPDATED = 'updated';
    public const ASSIGN_CODE_TAKEN = 'code_taken';
    public const ASSIGN_BAD_CODE = 'bad_code';
    public const ASSIGN_BAD_PERCENT = 'bad_percent';

    public function __construct(private readonly PDO $db)
    {
    }

    /** null — структура на месте; иначе текст для лога и разработчика. */
    public static function schemaProblem(PDO $db): ?string
    {
        try {
            $db->query('SELECT user_id, promo_code, status, reward_percent FROM partners LIMIT 1');
            $db->query('SELECT referral_source, referred_at FROM users LIMIT 1');
            $db->query('SELECT user_id, kind, due_at, sent_at FROM bot_delayed_messages LIMIT 1');
        } catch (PDOException $e) {
            return 'В базе нет таблиц партнёрской программы — не применена миграция database/034_partner_program.sql.';
        }

        return null;
    }

    /**
     * Промокод в том виде, в каком он хранится: верхний регистр, от 3 до 32
     * символов, латинские буквы, цифры, «_» и «-» — ровно то, что Telegram
     * допускает в параметре ссылки t.me/бот?start=…, чтобы один и тот же код
     * годился и для ссылки, и для ввода руками. null — так выглядеть
     * промокод не может.
     */
    public static function normalizeCode(string $raw): ?string
    {
        $code = strtoupper(trim($raw));

        return preg_match('/^[A-Z0-9_-]{3,32}$/', $code) === 1 ? $code : null;
    }

    /**
     * Назначить пользователю промокод (сделать партнёром) или сменить его.
     * $rewardPercent: null — не трогать прежний процент.
     *
     * @return array{result: string, code: ?string, previous_code: ?string}
     */
    public function assign(int $userId, string $rawCode, ?string $rewardPercent = null): array
    {
        $code = self::normalizeCode($rawCode);
        if ($code === null) {
            return ['result' => self::ASSIGN_BAD_CODE, 'code' => null, 'previous_code' => null];
        }
        if ($rewardPercent !== null) {
            $rewardPercent = str_replace(',', '.', trim($rewardPercent));
            if (!is_numeric($rewardPercent) || (float) $rewardPercent < 0 || (float) $rewardPercent > 100) {
                return ['result' => self::ASSIGN_BAD_PERCENT, 'code' => $code, 'previous_code' => null];
            }
        }

        $owner = $this->db->prepare('SELECT user_id FROM partners WHERE promo_code = :code');
        $owner->execute(['code' => $code]);
        $ownerId = $owner->fetchColumn();
        if ($ownerId !== false && (int) $ownerId !== $userId) {
            return ['result' => self::ASSIGN_CODE_TAKEN, 'code' => $code, 'previous_code' => null];
        }

        $existing = $this->partner($userId);
        if ($existing === null) {
            $this->db->prepare("INSERT INTO partners (user_id, promo_code, status, reward_percent) VALUES (:user_id, :code, 'active', :percent)")
                ->execute(['user_id' => $userId, 'code' => $code, 'percent' => $rewardPercent]);

            return ['result' => self::ASSIGN_CREATED, 'code' => $code, 'previous_code' => null];
        }

        $this->db->prepare("UPDATE partners SET promo_code = :code, status = 'active', reward_percent = :percent WHERE user_id = :user_id")
            ->execute(['user_id' => $userId, 'code' => $code, 'percent' => $rewardPercent ?? $existing['reward_percent']]);

        return ['result' => self::ASSIGN_UPDATED, 'code' => $code, 'previous_code' => $existing['promo_code'] !== $code ? $existing['promo_code'] : null];
    }

    /** Приостановить или вернуть партнёра. false — такого партнёра нет. */
    public function setActive(int $userId, bool $active): bool
    {
        if ($this->partner($userId) === null) {
            return false;
        }
        $this->db->prepare('UPDATE partners SET status = :status WHERE user_id = :user_id')
            ->execute(['status' => $active ? 'active' : 'paused', 'user_id' => $userId]);

        return true;
    }

    /** @return array{user_id: int, promo_code: string, status: string, reward_percent: ?string}|null */
    public function partner(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT user_id, promo_code, status, reward_percent FROM partners WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false
            ? ['user_id' => (int) $row['user_id'], 'promo_code' => (string) $row['promo_code'], 'status' => (string) $row['status'],
                'reward_percent' => $row['reward_percent'] !== null ? (string) $row['reward_percent'] : null]
            : null;
    }

    /**
     * Записать клиенту промокод.
     *
     * @return array{result: string, code: ?string} code — принятый промокод (APPLIED) или уже записанный раньше (ALREADY_SET)
     */
    public function applyCode(int $userId, string $rawCode, string $source): array
    {
        $stmt = $this->db->prepare('SELECT used_referral_code, first_paid_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            return ['result' => self::NOT_FOUND, 'code' => null];
        }
        if ($user['used_referral_code'] !== null && $user['used_referral_code'] !== '') {
            return ['result' => self::ALREADY_SET, 'code' => (string) $user['used_referral_code']];
        }
        if ($user['first_paid_at'] !== null) {
            return ['result' => self::AFTER_PAYMENT, 'code' => null];
        }

        $code = self::normalizeCode($rawCode);
        if ($code === null) {
            return ['result' => self::NOT_FOUND, 'code' => null];
        }
        $partner = $this->db->prepare("SELECT user_id FROM partners WHERE promo_code = :code AND status = 'active'");
        $partner->execute(['code' => $code]);
        $partnerUserId = $partner->fetchColumn();
        if ($partnerUserId === false) {
            return ['result' => self::NOT_FOUND, 'code' => null];
        }
        if ((int) $partnerUserId === $userId) {
            return ['result' => self::OWN_CODE, 'code' => null];
        }

        // Условие в WHERE — на случай двух сообщений подряд: промокод записывается один раз.
        $this->db->prepare(
            'UPDATE users SET referred_by_user_id = :partner, used_referral_code = :code, referral_source = :source, referred_at = :now
             WHERE id = :id AND used_referral_code IS NULL'
        )->execute(['partner' => (int) $partnerUserId, 'code' => $code, 'source' => $source, 'now' => date('Y-m-d H:i:s'), 'id' => $userId]);

        return ['result' => self::APPLIED, 'code' => $code];
    }

    /** Промокод, записанный клиенту (null — нет). */
    public function usedCode(int $userId): ?string
    {
        $stmt = $this->db->prepare('SELECT used_referral_code FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $code = $stmt->fetchColumn();

        return $code !== false && $code !== null && $code !== '' ? (string) $code : null;
    }

    /** Может ли клиент ещё ввести промокод: кода нет и оплат не было. */
    public function canEnterCode(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT used_referral_code, first_paid_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user !== false && ($user['used_referral_code'] === null || $user['used_referral_code'] === '') && $user['first_paid_at'] === null;
    }

    /**
     * Положена ли клиенту скидка по промокоду при оплате: промокод записан,
     * оплат ещё не было. Вызывать при выставлении счёта (когда появится
     * оплата) — скидка только на первую оплату.
     */
    public function discountCode(int $userId): ?string
    {
        $stmt = $this->db->prepare('SELECT used_referral_code, first_paid_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false || $user['used_referral_code'] === null || $user['used_referral_code'] === '' || $user['first_paid_at'] !== null) {
            return null;
        }

        return (string) $user['used_referral_code'];
    }

    /**
     * Пользователь по «@имя» или числовому Telegram ID — для команд
     * администратора.
     *
     * @return array{id: int, telegram_id: int, telegram_username: ?string}|null
     */
    public function findUser(string $reference): ?array
    {
        $reference = trim($reference);
        if (preg_match('/^\d{4,20}$/', $reference) === 1) {
            $stmt = $this->db->prepare('SELECT id, telegram_id, telegram_username FROM users WHERE telegram_id = :value');
            $stmt->execute(['value' => $reference]);
        } else {
            $name = ltrim($reference, '@');
            if ($name === '') {
                return null;
            }
            $stmt = $this->db->prepare('SELECT id, telegram_id, telegram_username FROM users WHERE LOWER(telegram_username) = :value');
            $stmt->execute(['value' => strtolower($name)]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false
            ? ['id' => (int) $row['id'], 'telegram_id' => (int) $row['telegram_id'], 'telegram_username' => $row['telegram_username'] !== null ? (string) $row['telegram_username'] : null]
            : null;
    }

    /**
     * Итоги по партнёрам за месяц: сколько клиентов привёл (всего и за
     * месяц), сколько из них платили и на какую сумму, какое выходит
     * вознаграждение. «Впервые оплатили за месяц» — клиенты, у которых
     * первая оплата (users.first_paid_at) пришлась на этот месяц.
     *
     * Считаются успешные платежи (payments.status = 'succeeded') приведённых
     * клиентов с датой оплаты в этом месяце; сумма — payments.amount, то
     * есть то, что клиент заплатил с учётом скидки. Вознаграждение — эта
     * сумма, умноженная на процент партнёра; процент не задан — null.
     *
     * @param string $month ГГГГ-ММ
     * @return array<int, array{user_id: int, telegram_id: int, telegram_username: ?string, promo_code: string, status: string, reward_percent: ?string,
     *     referred_total: int, referred_by_link: int, referred_manual: int, referred_in_month: int, paid_clients_total: int,
     *     first_paid_in_month: int, paying_clients_in_month: int, payments_in_month: int, amount_in_month: string, reward_in_month: ?string}>
     */
    public function report(string $month): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new \InvalidArgumentException("Месяц должен быть в виде ГГГГ-ММ, получено «{$month}».");
        }
        $from = $month . '-01 00:00:00';
        $to = date('Y-m-d 00:00:00', (int) strtotime($month . '-01 +1 month'));

        $partners = $this->db->query(
            'SELECT p.user_id, p.promo_code, p.status, p.reward_percent, u.telegram_id, u.telegram_username
             FROM partners p JOIN users u ON u.id = p.user_id ORDER BY p.created_at, p.user_id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $clients = $this->db->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN referral_source = 'link' THEN 1 ELSE 0 END) AS by_link,
                    SUM(CASE WHEN referral_source = 'manual' THEN 1 ELSE 0 END) AS manual,
                    SUM(CASE WHEN referred_at >= :date_from AND referred_at < :date_to THEN 1 ELSE 0 END) AS in_month,
                    SUM(CASE WHEN first_paid_at IS NOT NULL THEN 1 ELSE 0 END) AS paid_total,
                    SUM(CASE WHEN first_paid_at >= :paid_from AND first_paid_at < :paid_to THEN 1 ELSE 0 END) AS first_paid_in_month
             FROM users WHERE referred_by_user_id = :partner"
        );
        $payments = $this->db->prepare(
            "SELECT COUNT(*) AS payments, COUNT(DISTINCT pay.user_id) AS clients, COALESCE(SUM(pay.amount), 0) AS amount
             FROM payments pay JOIN users u ON u.id = pay.user_id
             WHERE u.referred_by_user_id = :partner AND pay.status = 'succeeded' AND pay.paid_at >= :date_from AND pay.paid_at < :date_to"
        );

        $rows = [];
        foreach ($partners as $partner) {
            $clients->execute(['partner' => $partner['user_id'], 'date_from' => $from, 'date_to' => $to, 'paid_from' => $from, 'paid_to' => $to]);
            $c = $clients->fetch(PDO::FETCH_ASSOC);
            $payments->execute(['partner' => $partner['user_id'], 'date_from' => $from, 'date_to' => $to]);
            $p = $payments->fetch(PDO::FETCH_ASSOC);
            $amount = number_format((float) $p['amount'], 2, '.', '');
            $percent = $partner['reward_percent'] !== null ? (string) $partner['reward_percent'] : null;

            $rows[] = [
                'user_id' => (int) $partner['user_id'],
                'telegram_id' => (int) $partner['telegram_id'],
                'telegram_username' => $partner['telegram_username'] !== null ? (string) $partner['telegram_username'] : null,
                'promo_code' => (string) $partner['promo_code'],
                'status' => (string) $partner['status'],
                'reward_percent' => $percent,
                'referred_total' => (int) $c['total'],
                'referred_by_link' => (int) $c['by_link'],
                'referred_manual' => (int) $c['manual'],
                'referred_in_month' => (int) $c['in_month'],
                'paid_clients_total' => (int) $c['paid_total'],
                'first_paid_in_month' => (int) $c['first_paid_in_month'],
                'paying_clients_in_month' => (int) $p['clients'],
                'payments_in_month' => (int) $p['payments'],
                'amount_in_month' => $amount,
                'reward_in_month' => $percent !== null ? number_format(round((float) $amount * (float) $percent / 100, 2), 2, '.', '') : null,
            ];
        }

        return $rows;
    }
}
