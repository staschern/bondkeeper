<?php

declare(strict_types=1);

namespace BondKeeper\Ratings;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Предложения "сопоставить по названию" (issuer_name_match_reviews,
 * миграция 024) — решение пользователя (сентябрь 2026): там, где
 * сопоставление идёт по названию (точное имя или "по корню"), в базу
 * ничего не пишется без его подтверждения. Причина — живые ложные
 * совпадения при разборе сверки: ООО «Озон» (фармацевтика) получила
 * рейтинги ОЗОН Банка и ОЗОН Капитала, ЗАО «Прогресс» (Курская обл.) —
 * рейтинг АО «ПРОГРЕСС» (Липецк, «ФрутоНяня»).
 *
 * Жизненный цикл: импортёр находит кандидата по названию → propose()
 * (status='pending') → notifyNewProposals() отправляет администратору
 * один раз, с заголовком новости и ссылкой на пресс-релиз → решение
 * через bin/review_matches.php: approve() (для источника с ИНН —
 * связка issuer_spv_links, дальше этот ИНН сопоставляется напрямую; для
 * источника без ИНН — подтверждённое название, см.
 * IssuerMatcher::findIssuerIdByApprovedName()) или reject() (пара больше
 * никогда не предлагается). Сама новость остаётся в rating_news_log со
 * статусом skipped_unmatched и после подтверждения подхватывается
 * следующим прогоном, если ещё в окне --days (иначе — разовый --full).
 *
 * Тёзки не предлагаются вовсе: если источник дал ИНН, у нашей компании
 * другой ИНН, а названия после normalizeCompanyName() совпадают целиком —
 * это две разные компании с одинаковым названием (ровно случай
 * «Прогресс»), а не мать и SPV (у тех названия всегда разные). Сюда же
 * попадает ООО «ОЗОН Банк» → ООО «Озон»: «Банк» нормализация снимает
 * как форму, и названия совпадают целиком. ОЗОН Капитал тёзкой не
 * считается ("Капитал" — маркер SPV, а не форма) — такая пара приходит
 * предложением, и её отклоняет администратор.
 */
final class NameMatchReviews
{
    /** Новое предложение — будет отправлено администратору. */
    public const PROPOSED = 'proposed';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    /** Тёзка: то же название, другой ИНН — не предлагается. */
    public const NAMESAKE = 'namesake';
    /** Источник нечем идентифицировать (нет ни ИНН, ни непустого названия). */
    public const SKIPPED = 'skipped';

    private const AGENCY_LABELS = ['nkr' => 'НКР', 'expert_ra' => 'Эксперт РА', 'acra' => 'АКРА', 'nra' => 'НРА'];
    /** Сайт агентства — для ссылок вида "/путь" (см. RatingsNormalizer::absoluteUrl()). */
    private const AGENCY_HOSTS = ['nkr' => 'ratings.ru', 'expert_ra' => 'raexpert.ru', 'acra' => 'www.acra-ratings.ru', 'nra' => 'www.ra-national.ru'];
    private const MATCH_TYPE_LABELS = [
        'exact_name' => 'точное совпадение названия',
        'root_name' => 'совпадение по корню названия (без ОПФ и слов Финанс/Капитал/Групп)',
    ];

    public function __construct(
        private readonly PDO $db,
    ) {
    }

    /**
     * @param string $matchType 'exact_name' | 'root_name'
     * @return string одна из констант класса
     */
    public function propose(
        string $agency,
        string $matchType,
        ?string $sourceInn,
        string $sourceName,
        int $issuerId,
        ?string $sourceTitle,
        ?string $sourceUrl,
    ): string {
        $inn = IssuerMatcher::normalizeInn($sourceInn);
        [$keyType, $key] = $inn !== null ? ['inn', $inn] : ['name', IssuerMatcher::nameKey($sourceName)];
        if ($key === '') {
            return self::SKIPPED;
        }

        $existing = $this->db->prepare(
            'SELECT status FROM issuer_name_match_reviews
             WHERE source_key_type = :key_type AND source_key = :source_key AND issuer_id = :issuer_id'
        );
        $existing->execute(['key_type' => $keyType, 'source_key' => $key, 'issuer_id' => $issuerId]);
        $status = $existing->fetchColumn();
        if ($status !== false) {
            return (string) $status;
        }

        if ($inn !== null && $this->isNamesake($inn, $sourceName, $issuerId)) {
            return self::NAMESAKE;
        }

        try {
            $this->db->prepare(
                'INSERT INTO issuer_name_match_reviews
                    (source_key_type, source_key, issuer_id, status, match_type, agency, source_name, source_title, source_url)
                 VALUES
                    (:key_type, :source_key, :issuer_id, :status, :match_type, :agency, :source_name, :source_title, :source_url)'
            )->execute([
                'key_type' => $keyType,
                'source_key' => $key,
                'issuer_id' => $issuerId,
                'status' => self::PENDING,
                'match_type' => $matchType,
                'agency' => $agency,
                'source_name' => mb_substr($sourceName, 0, 500),
                'source_title' => $sourceTitle !== null ? mb_substr($sourceTitle, 0, 500) : null,
                'source_url' => self::normalizeUrl($agency, $sourceUrl),
            ]);
        } catch (PDOException $e) {
            // Та же пара только что предложена параллельным прогоном другого агентства.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return self::PENDING;
        }

        return self::PROPOSED;
    }

    /** @return array<string, mixed> строка предложения после решения */
    public function approve(int $id): array
    {
        $row = $this->fetch($id);

        $this->db->beginTransaction();
        try {
            if ($row['source_key_type'] === 'inn') {
                // Переносимо (без ON DUPLICATE KEY UPDATE): один ИНН — одна связка.
                $this->db->prepare('DELETE FROM issuer_spv_links WHERE spv_inn = :spv_inn')
                    ->execute(['spv_inn' => $row['source_key']]);
                $this->db->prepare(
                    'INSERT INTO issuer_spv_links (spv_inn, issuer_id, spv_name, note)
                     VALUES (:spv_inn, :issuer_id, :spv_name, :note)'
                )->execute([
                    'spv_inn' => $row['source_key'],
                    'issuer_id' => (int) $row['issuer_id'],
                    'spv_name' => $row['source_name'],
                    'note' => "подтверждено предложение #{$id} (bin/review_matches.php)",
                ]);
            }
            $this->setStatus($id, self::APPROVED);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->fetch($id);
    }

    /**
     * Отклоняет пару. Заодно удаляет строку current_ratings этой компании
     * по этому агентству, если она была записана старым сопоставлением
     * "по корню" (matched_by_root_name=1) — администратор только что
     * подтвердил, что совпадение ложное. rating_actions не трогаем: по ним
     * не видно, из какого источника пришло действие, — см. сообщение
     * bin/review_matches.php.
     *
     * @return array<string, mixed> строка предложения после решения + removed_current_ratings
     */
    public function reject(int $id): array
    {
        $row = $this->fetch($id);

        $this->db->beginTransaction();
        try {
            if ($row['source_key_type'] === 'inn') {
                // Если пару раньше подтвердили, а теперь отклоняют — снимаем и связку.
                $this->db->prepare('DELETE FROM issuer_spv_links WHERE spv_inn = :spv_inn AND issuer_id = :issuer_id')
                    ->execute(['spv_inn' => $row['source_key'], 'issuer_id' => (int) $row['issuer_id']]);
            }
            $cleanup = $this->db->prepare(
                'DELETE FROM current_ratings WHERE issuer_id = :issuer_id AND agency = :agency AND matched_by_root_name = 1'
            );
            $cleanup->execute(['issuer_id' => (int) $row['issuer_id'], 'agency' => $row['agency']]);
            $removed = $cleanup->rowCount();

            $this->setStatus($id, self::REJECTED);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->fetch($id) + ['removed_current_ratings' => $removed];
    }

    /** @return array<int, array<string, mixed>> */
    public function listPending(): array
    {
        $stmt = $this->db->query(
            "SELECT r.*, i.short_name AS issuer_short_name, i.inn AS issuer_inn
             FROM issuer_name_match_reviews r
             JOIN issuers i ON i.id = r.issuer_id
             WHERE r.status = 'pending'
             ORDER BY r.id"
        );

        return $stmt->fetchAll();
    }

    /**
     * Отправляет администратору каждое ещё не отправленное предложение
     * отдельным сообщением (не больше $maxMessages за вызов — остальные
     * уйдут следующим прогоном), с кнопками "Подтвердить"/"Отклонить"
     * (proposalKeyboard()) — администратор решает прямо в Telegram, без
     * SSH и bin/review_matches.php (тот остаётся резервным способом,
     * команды в тексте предложения тоже показываются). Если отправка не
     * удалась (не настроен Telegram) — останавливается, notified_at не
     * ставится, попробуем в следующий раз.
     *
     * @param callable(string, ?array=null): bool $send
     * @return int сколько предложений отправлено
     */
    public function notifyNewProposals(callable $send, int $maxMessages = 20): int
    {
        $stmt = $this->db->query(
            "SELECT r.*, i.short_name AS issuer_short_name, i.inn AS issuer_inn
             FROM issuer_name_match_reviews r
             JOIN issuers i ON i.id = r.issuer_id
             WHERE r.status = 'pending' AND r.notified_at IS NULL
             ORDER BY r.id"
        );
        $rows = $stmt->fetchAll();

        $mark = $this->db->prepare('UPDATE issuer_name_match_reviews SET notified_at = CURRENT_TIMESTAMP WHERE id = :id');
        $sent = 0;
        foreach ($rows as $row) {
            if ($sent >= $maxMessages) {
                $left = count($rows) - $sent;
                $send("Ещё предложений на подтверждение: {$left} — придут следующим прогоном. Все ждущие: php bin/review_matches.php --list");
                break;
            }
            $id = (int) $row['id'];
            if (!$send(self::formatProposal($row), self::proposalKeyboard($id))) {
                break;
            }
            $mark->execute(['id' => $id]);
            $sent++;
        }

        return $sent;
    }

    /** Кнопки "Подтвердить"/"Отклонить" под сообщением-предложением — обрабатываются BotCommandHandler::dispatchReviewCallback() (только для admin_telegram_id). */
    public static function proposalKeyboard(int $id): array
    {
        return ['inline_keyboard' => [[
            ['text' => '✅ Подтвердить', 'callback_data' => "review:approve:{$id}"],
            ['text' => '❌ Отклонить', 'callback_data' => "review:reject:{$id}"],
        ]]];
    }

    /** @param array<string, mixed> $row строка issuer_name_match_reviews + issuer_short_name/issuer_inn */
    public static function formatProposal(array $row): string
    {
        $id = (int) $row['id'];
        $agency = self::AGENCY_LABELS[$row['agency']] ?? (string) $row['agency'];
        $matchType = self::MATCH_TYPE_LABELS[$row['match_type']] ?? (string) $row['match_type'];
        $sourceInn = $row['source_key_type'] === 'inn' ? "ИНН {$row['source_key']}" : 'ИНН в источнике не указан';
        $issuerInn = ($row['issuer_inn'] ?? '') !== '' ? "ИНН {$row['issuer_inn']}" : 'ИНН не указан';
        // У новостей — заголовок пресс-релиза; у строк полных выгрузок
        // заголовка нет, импортёр описывает саму строку ("Полная выгрузка
        // НКР: ... — рейтинг ..., дата ...").
        $title = ($row['source_title'] ?? '') !== '' ? "«{$row['source_title']}»" : '—';
        $url = ($row['source_url'] ?? '') !== '' ? (string) $row['source_url'] : '—';

        return "Предложение #{$id}: сопоставить по названию ({$agency}, {$matchType})\n\n"
            . "У агентства: {$row['source_name']}, {$sourceInn}\n"
            . "У нас: {$row['issuer_short_name']}, issuer_id={$row['issuer_id']}, {$issuerInn}\n\n"
            . "Заголовок: {$title}\n"
            . "Ссылка: {$url}\n\n"
            . "Подтвердить: php bin/review_matches.php --approve={$id}\n"
            . "Отклонить: php bin/review_matches.php --reject={$id}";
    }

    /**
     * Ссылка в предложении всегда кликабельная, для любого агентства
     * (решение пользователя, 28.09.2026): выгрузка НКР отдаёт адрес без
     * https://, остальные сейчас дают полные — это страховка на случай
     * смены формата. Не похоже на адрес — сохраняем как есть.
     */
    private static function normalizeUrl(string $agency, ?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $absolute = RatingsNormalizer::absoluteUrl($url, self::AGENCY_HOSTS[$agency] ?? '');

        return mb_substr($absolute ?? trim($url), 0, 500);
    }

    private function isNamesake(string $sourceInn, string $sourceName, int $issuerId): bool
    {
        $stmt = $this->db->prepare('SELECT full_name, short_name, inn FROM issuers WHERE id = :id');
        $stmt->execute(['id' => $issuerId]);
        $issuer = $stmt->fetch();
        if ($issuer === false) {
            return false;
        }

        $issuerInn = IssuerMatcher::normalizeInn($issuer['inn'] ?? null);
        $sourceNormalized = IssuerMatcher::normalizeCompanyName($sourceName);
        if ($issuerInn === null || $issuerInn === $sourceInn || $sourceNormalized === '') {
            return false;
        }

        foreach ([$issuer['full_name'], $issuer['short_name']] as $name) {
            if (IssuerMatcher::normalizeCompanyName((string) $name) === $sourceNormalized) {
                return true;
            }
        }

        return false;
    }

    private function setStatus(int $id, string $status): void
    {
        $this->db->prepare('UPDATE issuer_name_match_reviews SET status = :status, decided_at = CURRENT_TIMESTAMP WHERE id = :id')
            ->execute(['status' => $status, 'id' => $id]);
    }

    /** @return array<string, mixed> */
    private function fetch(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, i.short_name AS issuer_short_name, i.inn AS issuer_inn
             FROM issuer_name_match_reviews r
             JOIN issuers i ON i.id = r.issuer_id
             WHERE r.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Предложение #{$id} не найдено.");
        }

        return $row;
    }
}
