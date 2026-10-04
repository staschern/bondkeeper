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
 *   - НКР, Эксперт РА — fetchSnapshot() соответствующего импортёра.
 *   - АКРА — AcraImporter::readSnapshotFromFile() (П7, JSON-файл).
 *   - НРА — истории с 2020 года, отдельного снимка нет — "сейчас"
 *     пересчитывается как последняя по дате строка НА КАЖДОГО ЭМИТЕНТА
 *     среди строк NraImporter::fetchCreditRatingCandidates().
 *
 * Расхождение НЕ чинится автоматически — решение пользователя:
 * расхождение почти всегда сигнал реальной проблемы выше по цепочке.
 * Сначала человек смотрит отчёт, потом сам запускает перезапись
 * сохранённым снимком (П2, см. bin/reconcile_ratings.php).
 *
 * Три вида расхождений в отчёте:
 *   1. field_mismatches — эмитент есть и там, и там, но какое-то поле
 *      (rating/outlook/last_action_date) отличается. В строке — оба
 *      названия: наше (issuers.short_name) и у агентства (П14: раньше
 *      печаталось только название агентства, и фармацевтическая «Озон»
 *      выглядела в отчёте как «ОЗОН Банк»), источник нашего значения и,
 *      если оно из новости, — заголовок и ссылка этой новости.
 *   2. missing_in_ours — агентство знает эмитента, у нас в
 *      current_ratings для этой пары (issuer_id, agency) нет строки
 *      вообще.
 *   3. missing_in_snapshot — у нас есть строка current_ratings для этой
 *      пары, но свежий снимок агентства этого эмитента не содержит.
 *      Делится на "ожидаемые" (с причиной) и "требуют внимания".
 *
 * === Ожидаемые missing_in_snapshot (П3, сентябрь 2026) ===
 *
 * Разбор сверки от 20.09 показал: все 12 "нет в снимке" были ожидаемыми
 * — 7 внесены вручную из xlsx, 5 — рейтинги выпусков облигаций из
 * новостей (агентство рейтингует облигации компании или её мать, а не
 * саму компанию). Причины, по которым строка считается ожидаемой
 * (explainMissing()):
 *   - source='manual' — внесено вручную (миграция 025);
 *   - рейтинг 'отозван' (решение пользователя: храним, ограничений по
 *     времени нет, П6). В причине — последнее действие: отозванные
 *     рейтинги в списках агентств бывают (у Эксперт РА 407 из 657 строк
 *     трёх категорий, 29.09.2026), так что "нет в снимке" значит, что
 *     агентство не рейтингует саму компанию;
 *   - issuer_id — цель связки issuer_spv_links (рейтинг приходит через
 *     ИНН другой компании);
 *   - issuer_id — цель подтверждённого сопоставления по названию
 *     (issuer_name_match_reviews, источник без ИНН);
 *   - последнее рейтинговое действие по этой паре — рейтинг выпуска
 *     облигаций (RatingsNormalizer::isBondIssueRatingTitle()).
 * Строка со старым флагом matched_by_root_name=1 больше НЕ считается
 * ожидаемой — сопоставление "по корню" дало ложные совпадения (Озон,
 * Прогресс), такие строки выводятся в "требуют внимания" с пометкой.
 *
 * === Значение из новости, которую теперь пропускаем (29.09.2026) ===
 *
 * Сверка Эксперт РА 29.09: СОПФ ДОМ.РФ "отозван" из новости о погашении
 * выпуска попал в ожидаемые ("рейтинг отозван"), хотя это ошибка
 * старого разбора — такие новости (погашение, неразмещение,
 * субординированные, ожидаемый рейтинг) больше не влияют на рейтинг
 * компании (RatingsNormalizer::bondNewsSkipStatus()). Если наше значение
 * пришло из новости (source='action') и последняя новость такая — строка
 * "требует внимания" с пометкой (note), а не ожидаемая. Для расхождений
 * по полям это решает ReconcileSummary по заголовку our_action_title.
 *
 * === Статус наблюдения (29.09.2026) ===
 *
 * Если наше "под наблюдением" остаётся при перезаписи
 * (SnapshotRows::keepsOurWatchStatus() — в списке Эксперт РА статуса нет),
 * расхождение по прогнозу/дате помечается watch_kept=true, и сводка
 * относит его к ожидаемым.
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
     *     field_mismatches: array<int, array{issuer_id: int, our_name: string, agency_name: string, field: string, ours: ?string, theirs: ?string, our_source: ?string, our_action_title: ?string, our_action_url: ?string, watch_kept: bool}>,
     *     missing_in_ours: array<int, array{issuer_id: int, our_name: string, agency_name: string, rating: string, outlook: ?string, last_action_date: string}>,
     *     missing_in_snapshot: array<int, array{issuer_id: int, our_name: string, ours: ?string, last_action_date: ?string, source: ?string, expected: bool, reason: ?string, note: ?string}>
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

            if ($ours === null) {
                // Сохраняем готовые значения из снимка целиком — ради
                // applyMissingInOurs() (кейс 3), чтобы не запрашивать
                // снимок заново.
                $missingInOurs[] = [
                    'issuer_id' => $row['issuer_id'],
                    'our_name' => $this->issuerName($row['issuer_id']),
                    'agency_name' => $row['issuer_name'],
                    'rating' => $row['rating'],
                    'outlook' => $row['outlook'],
                    'last_action_date' => $row['last_action_date'],
                ];
                continue;
            }

            $lastAction = null;
            $watchKept = SnapshotRows::keepsOurWatchStatus($agency, $ours, $row);
            foreach (['rating', 'outlook', 'last_action_date'] as $field) {
                if ($ours[$field] === $row[$field]) {
                    continue;
                }
                if ($ours['source'] === 'action' && $lastAction === null) {
                    $lastAction = $this->fetchLastAction($row['issuer_id'], $agency) ?? ['source_title' => null, 'source_url' => null];
                }
                $fieldMismatches[] = [
                    'issuer_id' => $row['issuer_id'],
                    'our_name' => $ours['short_name'],
                    'agency_name' => $row['issuer_name'],
                    'field' => $field,
                    'ours' => $ours[$field],
                    'theirs' => $row[$field],
                    'our_source' => $ours['source'],
                    'our_action_title' => $lastAction['source_title'] ?? null,
                    'our_action_url' => $lastAction['source_url'] ?? null,
                    'watch_kept' => $watchKept,
                ];
            }
        }

        $missingInSnapshot = [];
        $stmt = $this->db->prepare(
            'SELECT cr.issuer_id, i.short_name, cr.rating, cr.last_action_date, cr.source, cr.matched_by_root_name
             FROM current_ratings cr
             JOIN issuers i ON i.id = cr.issuer_id
             WHERE cr.agency = :agency
             ORDER BY cr.issuer_id'
        );
        $stmt->execute(['agency' => $agency]);
        foreach ($stmt->fetchAll() as $row) {
            $issuerId = (int) $row['issuer_id'];
            if (isset($snapshotIssuerIds[$issuerId])) {
                continue;
            }

            $lastAction = $this->fetchLastAction($issuerId, $agency);
            $skippedNote = $this->skippedNewsNote($row, $lastAction);
            $reason = $skippedNote === null ? $this->explainMissing($issuerId, $row, $lastAction) : null;
            $notes = array_filter([
                (bool) $row['matched_by_root_name'] ? 'строка получена старым сопоставлением «по корню» названия — проверьте, та ли это компания' : null,
                $skippedNote,
            ]);
            $missingInSnapshot[] = [
                'issuer_id' => $issuerId,
                'our_name' => (string) $row['short_name'],
                'ours' => $row['rating'],
                'last_action_date' => $row['last_action_date'],
                'source' => $row['source'],
                'expected' => $reason !== null,
                'reason' => $reason,
                'note' => $notes !== [] ? implode(' · ', $notes) : null,
            ];
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
     * перезапишет чужую более свежую запись.
     *
     * source='reconcile', matched_by_root_name=0 (П8): снимок больше не
     * содержит строк, сопоставленных "по корню" (только ИНН, связка и
     * подтверждённое название), терять флаг нечего.
     *
     * Конфликт первичного ключа (SQLSTATE 23000) при повторе одного
     * issuer_id в списке не роняет прогон — повтор пропускается. Снимки
     * теперь сами сворачиваются до одной строки на эмитента
     * (SnapshotRows::latestPerIssuer()), так что это страховка.
     *
     * @param array<int, array{issuer_id: int, rating: string, outlook: ?string, last_action_date: string}> $missingInOurs
     * @return int сколько строк реально записано
     */
    public function applyMissingInOurs(string $agency, array $missingInOurs): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO current_ratings (issuer_id, agency, rating, outlook, last_action_date, matched_by_root_name, source)
             VALUES (:issuer_id, :agency, :rating, :outlook, :last_action_date, 0, 'reconcile')"
        );

        $count = 0;
        foreach ($missingInOurs as $row) {
            try {
                $stmt->execute([
                    'issuer_id' => $row['issuer_id'],
                    'agency' => $agency,
                    'rating' => mb_substr($row['rating'], 0, 20),
                    'outlook' => $row['outlook'],
                    'last_action_date' => $row['last_action_date'],
                ]);
                $count++;
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        return $count;
    }

    /**
     * @param array{rating: ?string, source: ?string} $row
     * @param array{source_title: ?string, source_url: ?string}|null $lastAction
     * @return string|null причина, по которой отсутствие в снимке ожидаемо; null — требует внимания
     */
    private function explainMissing(int $issuerId, array $row, ?array $lastAction): ?string
    {
        if ($row['source'] === 'manual') {
            return 'внесено вручную из xlsx — агентство рейтингует облигации компании или её мать, а не саму компанию';
        }
        if ($row['rating'] === 'отозван') {
            return 'рейтинг отозван' . ($lastAction !== null && (string) $lastAction['source_title'] !== ''
                ? ', последнее действие: ' . self::quoteAction($lastAction)
                : '');
        }

        $link = $this->db->prepare('SELECT spv_inn, spv_name FROM issuer_spv_links WHERE issuer_id = :issuer_id ORDER BY spv_inn');
        $link->execute(['issuer_id' => $issuerId]);
        $links = $link->fetchAll();
        if ($links !== []) {
            $names = array_map(
                static fn (array $l): string => trim(($l['spv_name'] ?? '') . ' (ИНН ' . $l['spv_inn'] . ')'),
                $links,
            );
            return 'рейтинг приходит через ИНН связанной компании: ' . implode(', ', $names)
                . ' — её нет в текущем списке агентства';
        }

        $approved = $this->db->prepare(
            "SELECT source_name FROM issuer_name_match_reviews
             WHERE issuer_id = :issuer_id AND source_key_type = 'name' AND status = 'approved'
             ORDER BY id LIMIT 1"
        );
        $approved->execute(['issuer_id' => $issuerId]);
        $approvedName = $approved->fetchColumn();
        if ($approvedName !== false) {
            return "подтверждённое сопоставление по названию «{$approvedName}» (источник без ИНН)";
        }

        if ($lastAction !== null && RatingsNormalizer::isBondIssueRatingTitle((string) $lastAction['source_title'])) {
            return 'последнее действие — рейтинг выпуска облигаций: ' . self::quoteAction($lastAction);
        }

        return null;
    }

    /**
     * Пометка "значение из новости, которую теперь пропускаем" — см.
     * докблок класса. NULL — значение не из такой новости.
     *
     * @param array{source: ?string} $row
     * @param array{source_title: ?string, source_url: ?string}|null $lastAction
     */
    private function skippedNewsNote(array $row, ?array $lastAction): ?string
    {
        if ($row['source'] !== 'action' || $lastAction === null) {
            return null;
        }
        $status = RatingsNormalizer::bondNewsSkipStatusForStoredTitle((string) $lastAction['source_title']);
        if ($status === null) {
            return null;
        }

        return 'наше — из новости ' . self::quoteAction($lastAction) . ', такие новости теперь не влияют на рейтинг компании ('
            . RatingsNormalizer::bondNewsSkipLabel($status) . '); записано до обновления правил. Перезапись это не исправит — компании нет в списке агентства'
            . match ($status) {
                'skipped_bond_redemption' => '; исправит bin/fix_bond_redemption_ratings.php',
                'skipped_non_standard' => '; исправит bin/fix_acra_news_ratings.php',
                default => '',
            };
    }

    /** @param array{source_title: ?string, source_url: ?string} $action */
    private static function quoteAction(array $action): string
    {
        return '«' . $action['source_title'] . '»' . (($action['source_url'] ?? '') !== '' ? ' ' . $action['source_url'] : '');
    }

    /** @return array{rating: ?string, outlook: ?string, last_action_date: ?string, source: ?string, short_name: string}|null */
    private function fetchOurs(int $issuerId, string $agency): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT cr.rating, cr.outlook, cr.last_action_date, cr.source, i.short_name
             FROM current_ratings cr
             LEFT JOIN issuers i ON i.id = cr.issuer_id
             WHERE cr.issuer_id = :issuer_id AND cr.agency = :agency'
        );
        $stmt->execute(['issuer_id' => $issuerId, 'agency' => $agency]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'rating' => $row['rating'],
            'outlook' => $row['outlook'],
            'last_action_date' => $row['last_action_date'],
            'source' => $row['source'],
            'short_name' => (string) ($row['short_name'] ?? ''),
        ];
    }

    /** @return array{source_title: ?string, source_url: ?string}|null */
    private function fetchLastAction(int $issuerId, string $agency): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT source_title, source_url FROM rating_actions
             WHERE issuer_id = :issuer_id AND agency = :agency
             ORDER BY action_date DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['issuer_id' => $issuerId, 'agency' => $agency]);
        $row = $stmt->fetch();

        return $row !== false ? ['source_title' => $row['source_title'], 'source_url' => $row['source_url']] : null;
    }

    private function issuerName(int $issuerId): string
    {
        $stmt = $this->db->prepare('SELECT short_name FROM issuers WHERE id = :id');
        $stmt->execute(['id' => $issuerId]);
        $name = $stmt->fetchColumn();

        return $name !== false ? (string) $name : '';
    }
}
