<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;

/**
 * Сверка `current_ratings` с "истиной" от агентства напрямую (прямой
 * запрос пользователя, 17 сентября 2026) — принципиально НЕ импортёр:
 * ничего не пишет, только сравнивает и отчитывается о расхождениях.
 *
 * Зачем отдельный класс, а не режим внутри существующих импортёров:
 * ответственность другая. Импортёры (NkrImporter/NraImporter/...) знают,
 * КАК получить свежие данные от конкретного агентства (HTTP/Excel/HTML) —
 * этот класс сознательно ничего не знает про источники, только про
 * СРАВНЕНИЕ уже готового нормализованного "снимка сейчас" (агентство-
 * независимый формат) с тем, что лежит в `current_ratings`. Получение
 * снимка — забота вызывающего кода (bin/reconcile_ratings.php):
 *   - НКР — единственное агентство с отдельной страницей "снимок
 *     сейчас" (issuers.php) — переиспользует NkrImporter::fetchSnapshot()
 *     буквально, только БЕЗ записи в БД.
 *   - НРА — истории с 2020 года, отдельного снимка нет — "сейчас"
 *     пересчитывается как последняя по дате строка НА КАЖДОГО ЭМИТЕНТА
 *     среди строк NraImporter::fetchCreditRatingCandidates().
 *
 * Расхождение НЕ чинится автоматически, даже когда однозначно понятно,
 * какое значение "правильное" — решение пользователя: расхождение почти
 * всегда сигнал реальной проблемы выше по цепочке (пропущенное или
 * неправильно разобранное действие в *NewsImporter), а не просто
 * "устаревшие данные" — молчаливая перезапись замаскировала бы саму
 * проблему, а не только её симптом. Отчёт — первый шаг, автоматическое
 * исправление осознанно не реализовано.
 *
 * Три вида расхождений в отчёте:
 *   1. field_mismatches — эмитент есть и там, и там, но какое-то поле
 *      (rating/outlook/last_action_date) отличается.
 *   2. missing_in_ours — агентство знает эмитента, у нас в
 *      current_ratings для этой пары (issuer_id, agency) нет строки
 *      вообще (ни разу не сихронизировался, или ошибка сопоставления).
 *   3. missing_in_snapshot — у нас есть строка current_ratings для этой
 *      пары, но свежий снимок агентства этого эмитента не содержит —
 *      либо агентство сняло рейтинг и убрало из списка целиком (у НКР
 *      возможно: полное отсутствие в issuers.php), либо сбой самого
 *      снимка (не стоит принимать этот вид расхождения за чистую монету
 *      без проверки — источник мог просто не отдать часть строк).
 *
 * === Кейс 3 — автодобавление missing_in_ours (сентябрь 2026) ===
 *
 * По прямому запросу пользователя (реальный найденный случай: ПАО «МТС»,
 * issuer_id=62, nkr — запись была удалена более ранним скриптом чистки
 * шумных "отзывов рейтинга выпуска облигаций", см. bin/
 * fix_bond_redemption_ratings.php): "если появился новый эмитент у
 * агентства, которого ранее не было в БД, то добавить" — в отличие от
 * field_mismatches (реальный сигнал проблемы выше по цепочке, чинить не
 * пытаемся), отсутствие строки ЦЕЛИКОМ — как раз тот случай, где нечего
 * терять: применяется через applyMissingInOurs() ниже, ОТДЕЛЬНЫМ явным
 * вызовом (не автоматически внутри reconcile()), см. bin/
 * reconcile_ratings.php --apply-missing.
 *
 * === Кейс 5b — "ожидаемые" missing_in_snapshot (сентябрь 2026) ===
 *
 * Реальный найденный случай: АО «Аэрофьюэлз» (issuer_id=1561) — у нас
 * есть рейтинг, полученный когда-то из СТАРОЙ новости, называвшей SPV
 * (ООО «Аэрофьюэлз Групп»), а не саму материнскую компанию — актуальный
 * снимок агентства эту SPV-строку не содержит (агентство рейтингует
 * именно SPV, не материнскую компанию, под её собственным именем). По
 * решению пользователя: "менять не нужно ничего... ввести два уровня
 * проверки. Первый как сейчас, а второй по новостям (откуда мы его и
 * взяли)" — вторым уровнем здесь служит ДОСТУПНЫЙ уже сейчас сигнал:
 * matched_by_root_name=1 (строка получена через третий/четвёртый уровень
 * сопоставления, миграция 022) ИЛИ issuer_id — цель явной ручной связки
 * issuer_spv_links (миграция 023). Оба сигнала помечают строку как
 * 'expected' => true в missing_in_snapshot — ожидаемое расхождение,
 * а не сбой снимка. ИЗВЕСТНЫЙ пробел: строки, полученные СТАРЫМ
 * механизмом (составное действие в заголовке + точное имя в кавычках, до
 * появления root-сопоставления вообще — реальный случай самого
 * Аэрофьюэлз) не подхватываются этим флагом задним числом
 * (matched_by_root_name=0 для них) — сознательно не переписываем старые
 * флаги ради этого, см. docs/STAGE3_RATINGS.md.
 */
final class CurrentRatingsReconciler
{
    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /**
     * @param array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string}> $snapshot
     * @return array{
     *     snapshot_count: int,
     *     field_mismatches: array<int, array{issuer_id: int, issuer_name: string, field: string, ours: ?string, theirs: ?string}>,
     *     missing_in_ours: array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string}>,
     *     missing_in_snapshot: array<int, array{issuer_id: int, issuer_name: string, ours: ?string, expected: bool}>
     * }
     */
    public function reconcile(string $agency, array $snapshot): array
    {
        $fieldMismatches = [];
        $missingInOurs = [];
        $snapshotIssuerIds = [];

        foreach ($snapshot as $row) {
            $snapshotIssuerIds[$row['issuer_id']] = true;
            $ours = $this->fetchOurs($row['issuer_id'], $agency);

            if ($ours['rating'] === null && $ours['last_action_date'] === null) {
                // current_ratings.rating — NOT NULL в схеме: обе NULL
                // одновременно возможны ТОЛЬКО когда строки для этой пары
                // нет вообще, не как значение реальной строки. Сохраняем
                // готовые значения из снимка целиком (не только issuer_id/
                // issuer_name) — ради applyMissingInOurs() ниже (кейс 3),
                // чтобы не запрашивать снимок заново.
                $missingInOurs[] = [
                    'issuer_id' => $row['issuer_id'],
                    'issuer_name' => $row['issuer_name'],
                    'rating' => $row['rating'],
                    'outlook' => $row['outlook'],
                    'last_action_date' => $row['last_action_date'],
                ];
                continue;
            }

            foreach (['rating', 'outlook', 'last_action_date'] as $field) {
                if ($ours[$field] !== $row[$field]) {
                    $fieldMismatches[] = [
                        'issuer_id' => $row['issuer_id'],
                        'issuer_name' => $row['issuer_name'],
                        'field' => $field,
                        'ours' => $ours[$field],
                        'theirs' => $row[$field],
                    ];
                }
            }
        }

        $missingInSnapshot = [];
        // matched_by_root_name (миграция 022) и присутствие в
        // issuer_spv_links (миграция 023) — оба сигнала "эта строка была
        // получена через SPV/материнскую замену сопоставления, а не
        // напрямую" — см. докблок класса, кейс 5b.
        $stmt = $this->db->prepare(
            'SELECT cr.issuer_id, i.short_name, cr.rating, cr.matched_by_root_name,
                    EXISTS(SELECT 1 FROM issuer_spv_links l WHERE l.issuer_id = cr.issuer_id) AS has_spv_link
             FROM current_ratings cr
             JOIN issuers i ON i.id = cr.issuer_id
             WHERE cr.agency = :agency'
        );
        $stmt->execute(['agency' => $agency]);
        foreach ($stmt->fetchAll() as $row) {
            $issuerId = (int) $row['issuer_id'];
            if (!isset($snapshotIssuerIds[$issuerId])) {
                $missingInSnapshot[] = [
                    'issuer_id' => $issuerId,
                    'issuer_name' => (string) $row['short_name'],
                    'ours' => $row['rating'],
                    'expected' => (bool) $row['matched_by_root_name'] || (bool) $row['has_spv_link'],
                ];
            }
        }

        return [
            'snapshot_count' => count($snapshot),
            'field_mismatches' => $fieldMismatches,
            'missing_in_ours' => $missingInOurs,
            'missing_in_snapshot' => $missingInSnapshot,
        ];
    }

    /**
     * Кейс 3 (ПАО «МТС», issuer_id=62, сентябрь 2026, прямой запрос
     * пользователя): "если появился новый эмитент у агентства, которого
     * ранее не было в БД, то добавить" — пишет current_ratings НАПРЯМУЮ
     * из уже готового результата reconcile()['missing_in_ours'], не
     * запрашивает снимок заново. Сознательно ПРОСТОЙ INSERT, а не "ON
     * DUPLICATE KEY UPDATE": по определению missing_in_ours — пары
     * (issuer_id, agency), для которых строки ЕЩЁ не существует;
     * конфликт первичного ключа тут означал бы гонку с параллельным
     * прогоном импортёра — пусть тогда упадёт явной ошибкой, а не молча
     * перезапишет чужую более свежую запись. НЕ покрывает случай
     * "эмитента вообще нет в issuers" — такие строки никогда не попадают
     * в снимок (см. докблок класса), отдельная более сложная задача.
     *
     * @param array<int, array{issuer_id: int, issuer_name: string, rating: string, outlook: ?string, last_action_date: string}> $missingInOurs
     * @return int сколько строк записано
     */
    public function applyMissingInOurs(string $agency, array $missingInOurs): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date)
             VALUES (:issuer_id, :agency, :rating, :outlook, :last_action_date)'
        );

        $count = 0;
        foreach ($missingInOurs as $row) {
            $stmt->execute([
                'issuer_id' => $row['issuer_id'],
                'agency' => $agency,
                'rating' => mb_substr($row['rating'], 0, 20),
                'outlook' => $row['outlook'],
                'last_action_date' => $row['last_action_date'],
            ]);
            $count++;
        }

        return $count;
    }

    /** @return array{rating: ?string, outlook: ?string, last_action_date: ?string} */
    private function fetchOurs(int $issuerId, string $agency): array
    {
        $stmt = $this->db->prepare(
            'SELECT rating, outlook, last_action_date FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency'
        );
        $stmt->execute(['issuer_id' => $issuerId, 'agency' => $agency]);
        $row = $stmt->fetch();

        return $row !== false
            ? ['rating' => $row['rating'], 'outlook' => $row['outlook'], 'last_action_date' => $row['last_action_date']]
            : ['rating' => null, 'outlook' => null, 'last_action_date' => null];
    }
}
