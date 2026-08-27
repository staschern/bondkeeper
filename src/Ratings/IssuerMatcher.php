<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Сопоставление эмитента с сайта агентства с issuers.id.
 *
 * Изначально (см. docs/STAGE3_RATINGS.md, раздел про риск сопоставления)
 * предполагалось, что понадобится нечёткое сопоставление по названию —
 * на страницах списка рейтингов ИНН не показывается. Но на всех трёх
 * доступных агентствах (Эксперт РА — карточка компании, НРА и НКР —
 * прямо в Excel-выгрузке) ИНН оказался доступен напрямую. Поэтому пока
 * матчер — только точное сопоставление по ИНН; нечёткое сопоставление по
 * названию не реализовано, потому что реальной необходимости в нём не
 * возникло (YAGNI) — если появится агентство без ИНН вообще, это будет
 * отдельная, осознанная задача, а не угадывание.
 *
 * Ошибка сопоставления здесь хуже, чем где-либо ещё в проекте — молча
 * приписывает рейтинг не той компании. Поэтому: не находим по ИНН — не
 * пишем строку вообще, эмитент попадает в отчёт "не сопоставлено", а не
 * привязывается по эвристике.
 */
final class IssuerMatcher
{
    public function __construct(
        private readonly PDO $db,
    ) {
    }

    public function findIssuerIdByInn(?string $rawInn): ?int
    {
        $inn = self::normalizeInn($rawInn);
        if ($inn === null) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT id FROM issuers WHERE inn = :inn');
        $stmt->execute(['inn' => $inn]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Excel часто хранит ИНН как число, а не текст — тогда ведущий 0
     * (у ИНН, начинающихся с кода региона 01-09) молча теряется при
     * экспорте. Подтверждено на реальной выгрузке НРА (2 из 935 строк,
     * см. STAGE3_RATINGS.md): длина 9 вместо 10. Возвращаем один ведущий
     * ноль назад; если и после этого длина не 10 (юрлицо) и не 12
     * (ИП) — считаем ИНН нераспознанным, а не гадаем дальше.
     */
    public static function normalizeInn(?string $rawInn): ?string
    {
        if ($rawInn === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $rawInn) ?? '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 9 || strlen($digits) === 11) {
            $digits = '0' . $digits;
        }

        return in_array(strlen($digits), [10, 12], true) ? $digits : null;
    }
}
