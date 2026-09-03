<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Сопоставление эмитента с сайта агентства с issuers.id.
 *
 * Изначально (см. docs/STAGE3_RATINGS.md, раздел про риск сопоставления)
 * предполагалось, что понадобится нечёткое сопоставление по названию —
 * на страницах списка рейтингов ИНН не показывается. На всех источниках
 * "текущих рейтингов" (current_ratings) ИНН всё же нашёлся напрямую тем
 * или иным путём — там матчер работал только по ИНН.
 *
 * Для парсинга НОВОСТЕЙ (rating_actions) реальная необходимость всё же
 * возникла: у Эксперт РА в ленте пресс-релизов эмитент виден только по
 * названию в тексте новости, ИНН там не даётся вообще (в отличие от
 * страницы конкретной компании, которая используется для current_ratings).
 * По прямому указанию пользователя — точное сопоставление по названию
 * (после нормализации: без кавычек/организационно-правовой формы/
 * регистра), НЕ приблизительное/расстояние Левенштейна и т.п. — как
 * запасной вариант, когда ИНН взять неоткуда.
 *
 * Ошибка сопоставления здесь хуже, чем где-либо ещё в проекте — молча
 * приписывает рейтинг не той компании. Поэтому: не находим — не пишем
 * строку вообще, эмитент попадает в отчёт "не сопоставлено", а не
 * привязывается по эвристике.
 */
final class IssuerMatcher
{
    /** @var array<string, int>|null нормализованное имя => issuer_id, строится один раз на прогон */
    private ?array $nameIndex = null;

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
     * Сопоставление по ISIN конкретного выпуска (securities.isin, УНИКАЛЬНЫЙ
     * ключ, миграция 001) — добавлено для АКРА (ШАБЛОН, сентябрь 2026,
     * не проверено на реальной БД): часть их писем даёт рейтинг КОНКРЕТНОГО
     * выпуска облигаций с указанием ISIN прямо в тексте (см.
     * AcraEmailParser/AcraMailImporter), а не название компании в кавычках.
     * Это единственный источник в проекте, где нашёлся такой путь —
     * потенциально закрывает категорию "регион/субъект РФ без ИНН и без
     * кавычек", которая у Эксперт РА была принципиально нерешаемой (сама
     * бумага регистрируется на конкретного эмитента даже тогда, когда у
     * него, как у муниципалитета, нет ИНН юрлица в привычном смысле).
     * ISIN присутствует НЕ в каждом письме АКРА даже для одного и того же
     * выпуска (см. докблок AcraEmailParser) — поэтому это ЗАПАСНОЙ путь
     * наравне с сопоставлением по имени, не замена ему.
     */
    public function findIssuerIdByIsin(?string $rawIsin): ?int
    {
        $isin = $rawIsin !== null ? mb_strtoupper(trim($rawIsin)) : null;
        if ($isin === null || !preg_match('/^[A-Z]{2}[A-Z0-9]{9}\d$/', $isin)) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT issuer_id FROM securities WHERE isin = :isin');
        $stmt->execute(['isin' => $isin]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Точное (после нормализации) сопоставление по названию — только
     * когда ИНН взять неоткуда (см. класс-докблок). Индекс "нормализованное
     * имя => id" строится один раз при первом обращении и держится в
     * памяти на весь прогон (эмитентов около полутысячи — недорого).
     * Неоднозначное совпадение (два разных эмитента дали одно и то же
     * нормализованное имя) — намеренно НЕ сопоставляем ни с одним из
     * них, это тоже "не нашли", а не "повезло угадать".
     */
    public function findIssuerIdByName(string $rawName): ?int
    {
        $normalized = self::normalizeCompanyName($rawName);
        if ($normalized === '') {
            return null;
        }

        if ($this->nameIndex === null) {
            $this->nameIndex = $this->buildNameIndex();
        }

        $id = $this->nameIndex[$normalized] ?? null;

        return $id === -1 ? null : $id;
    }

    /** @return array<string, int> нормализованное имя => id (-1 = неоднозначно, несколько эмитентов дали одно имя) */
    private function buildNameIndex(): array
    {
        $index = [];
        $stmt = $this->db->query('SELECT id, full_name, short_name FROM issuers');
        foreach ($stmt->fetchAll() as $row) {
            foreach ([$row['full_name'], $row['short_name']] as $name) {
                $normalized = self::normalizeCompanyName((string) $name);
                if ($normalized === '') {
                    continue;
                }
                if (isset($index[$normalized]) && $index[$normalized] !== (int) $row['id']) {
                    $index[$normalized] = -1;
                    continue;
                }
                $index[$normalized] = (int) $row['id'];
            }
        }

        return $index;
    }

    /**
     * Кавычки, организационно-правовая форма и регистр — не то, на что
     * должно влиять совпадение ("АО МФК «МК»" из новости и "Общество с
     * ограниченной ответственностью..." — не тот случай, тут просто
     * убираем шум вокруг одного и того же имени "МК"/"MK", а не пытаемся
     * угадывать ОПФ). Список форм — только реально встречавшиеся в
     * новостях/issuers.full_name на практике, не исчерпывающий справочник
     * всех форм из ГК РФ.
     */
    public static function normalizeCompanyName(string $name): string
    {
        $name = mb_strtoupper(trim($name));
        $name = str_replace(['«', '»', '"', '„', '“', '(', ')'], '', $name);
        $name = preg_replace(
            '/\b(ООО|ОАО|ЗАО|ПАО|АО|АКБ|ПАО\s+БАНК|БАНК|ГК|ХК|НКО|МКК|МФК|КБ|ГРУППА)\b\.?/u',
            '',
            $name
        ) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
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
