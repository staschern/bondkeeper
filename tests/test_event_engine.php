<?php

declare(strict_types=1);

/**
 * Офлайн-проверка Этапа 4 (событийный движок, Фаза 1) на SQLite —
 * тот же приём, что использовался для всего проекта раньше (нет MySQL
 * в песочнице). Самосев минимальной схемы (issuers/event_types/
 * raw_messages/events/rating_actions/fns_blocks), прогон реальных
 * классов EventPublisher/RatingActionsWriter/FnsBlocksImporter поверх
 * PDO SQLite, проверка сценариев из docs/STAGE4_EVENT_ENGINE.md.
 *
 * Запуск (из корня репозитория, с этим файлом в tests/):
 *   php -d extension=pdo_sqlite -d extension=mbstring tests/test_event_engine.php
 *
 * На боевом сервере (с полным набором расширений PHP) флаги -d не нужны.
 */

require dirname(__DIR__) . '/bin/bootstrap.php';

use BondKeeper\Events\EventPublisher;

$failures = 0;
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "  OK   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n";
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// EventPublisher/RatingActionsWriter/FnsBlocksImporter пишут MySQL-диалект
// (NOW(), CURDATE()) — под SQLite их нет из коробки, регистрируем как
// обычно в офлайн-тестах этого проекта.
$db->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
$db->sqliteCreateFunction('CURDATE', static fn (): string => date('Y-m-d'), 0);

$db->exec('
CREATE TABLE event_types (
    code TEXT PRIMARY KEY,
    default_priority TEXT NOT NULL
)');
$db->exec("INSERT INTO event_types (code, default_priority) VALUES ('C5', 'yellow'), ('E1', 'yellow')");

$db->exec('
CREATE TABLE raw_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    source_ref TEXT,
    raw_payload TEXT NOT NULL,
    fetched_at TEXT DEFAULT CURRENT_TIMESTAMP,
    processed_at TEXT,
    processing_status TEXT
)');

$db->exec('
CREATE TABLE events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    issuer_id INTEGER NOT NULL,
    event_type_code TEXT NOT NULL,
    raw_message_id INTEGER,
    event_date TEXT NOT NULL,
    published_at TEXT,
    status_text TEXT,
    amount_actual TEXT,
    priority TEXT,
    payload_json TEXT
)');

$db->exec('
CREATE TABLE fns_blocks (
    issuer_id INTEGER PRIMARY KEY,
    is_fns_blocked INTEGER NOT NULL DEFAULT 0,
    verification TEXT,
    date_verification TEXT,
    last_success_verification TEXT,
    active_bank_count INTEGER,
    decision_number TEXT,
    block_date TEXT,
    unblock_date TEXT,
    blocked_amount TEXT,
    reason TEXT,
    reason_category TEXT,
    source_reference TEXT,
    updated_at TEXT
)');

$events = new EventPublisher($db);

// ---------------------------------------------------------------------
// Сценарий 1: C5 — рейтинговое действие БЕЗ изменений (агентство
// подтвердило рейтинг) — событие должно быть создано ВСЕГДА, фильтра нет.
//
// Вызываем EventPublisher::publishRatingAction() напрямую (не через
// RatingActionsWriter::upsert()) — сам upsert() пишет `rating_actions`
// через MySQL-диалект (`ON DUPLICATE KEY UPDATE`), которого в SQLite нет
// в принципе (это давнее, не связанное с Этапом 4 ограничение офлайн-
// тестирования всего проекта — см. docs/STAGE3_RATINGS.md, "не
// проверено на реальном MySQL"). Materiality-логика Этапа 4 живёт
// целиком в EventPublisher — она и есть предмет проверки; сам upsert()
// вокруг неё — тонкая обвязка (INSERT + вызов паблишера + UPDATE
// event_id), проверенная чтением кода (RatingActionsWriter.php).
// ---------------------------------------------------------------------
echo "=== C5: рейтинговое действие ===\n";

$events->publishRatingAction(
    issuerId: 1,
    agency: 'nkr',
    actionDate: '2026-09-01',
    ratingFrom: 'A+.ru',
    ratingTo: 'A+.ru',
    outlookFrom: 'stable',
    outlookTo: 'stable',
    sourceUrl: 'https://ratings.ru/press/1',
    sourceTitle: 'НКР подтвердило рейтинг на уровне A+.ru',
);

$c5Events = $db->query("SELECT * FROM events WHERE event_type_code = 'C5'")->fetchAll(PDO::FETCH_ASSOC);
check('одно C5-событие создано на "подтверждено без изменений"', count($c5Events) === 1);
check('C5 статус — заголовок пресс-релиза как есть (source_title в приоритете)', $c5Events[0]['status_text'] === 'НКР подтвердило рейтинг на уровне A+.ru');

// Второе действие — реальное изменение рейтинга, тот же эмитент, другая дата.
$events->publishRatingAction(
    issuerId: 1,
    agency: 'nkr',
    actionDate: '2026-09-02',
    ratingFrom: 'A+.ru',
    ratingTo: 'AA-.ru',
    outlookFrom: 'stable',
    outlookTo: 'positive',
    sourceUrl: 'https://ratings.ru/press/2',
    sourceTitle: null,
);
$c5Events = $db->query("SELECT * FROM events WHERE event_type_code = 'C5' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check('второе C5-событие создано (повышение рейтинга)', count($c5Events) === 2);
check('без source_title статус собран из rating_from/rating_to/outlook_to', $c5Events[1]['status_text'] === 'A+.ru → AA-.ru, прогноз: positive');

// Третье действие — то же самое "без изменений", НО без source_title —
// проверяем, что buildRatingStatusText() даёт "подтверждён на уровне",
// а не "X → X" (X совпадает с X).
$events->publishRatingAction(
    issuerId: 1,
    agency: 'expert_ra',
    actionDate: '2026-09-03',
    ratingFrom: 'ruAA',
    ratingTo: 'ruAA',
    outlookFrom: 'stable',
    outlookTo: 'stable',
    sourceUrl: null,
    sourceTitle: null,
);
$c5Events = $db->query("SELECT * FROM events WHERE event_type_code = 'C5' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check('третье C5-событие создано (подтверждение без source_title)', count($c5Events) === 3);
check('текст — "подтверждён на уровне", НЕ "X → X" при неизменном рейтинге', $c5Events[2]['status_text'] === 'подтверждён на уровне ruAA, прогноз: stable');

// ---------------------------------------------------------------------
// Сценарий 2: E1 — блокировка ФНС, 3 триггера + 1 "не триггер".
// Симулируем applyResult() напрямую через EventPublisher, минуя реальный
// HTTP-клиент ФНС (FnsBlocksImporter требует NalogBiClientInterface —
// нам нужна только сама логика publishFnsBlockChange + запись в БД).
// ---------------------------------------------------------------------
echo "\n=== E1: блокировка ФНС ===\n";

// Триггер 1: начало блокировки (было false -> стало true).
$id = $events->publishFnsBlockChange(
    issuerId: 2,
    oldBlocked: false,
    oldBlockedAmount: null,
    newBlocked: true,
    newBlockedAmount: '100000.00',
    newActiveBankCount: 2,
    blockDate: '2026-09-01',
    reason: 'Код 01: взыскание задолженности',
    sourceReference: 'service.nalog.ru bi.do, решение №123',
);
check('триггер "начало блокировки" создаёт событие', $id !== null);

// Не-триггер: только число банков изменилось (сумма та же) — НЕ должно
// создавать событие (явное решение пользователя).
$id = $events->publishFnsBlockChange(
    issuerId: 2,
    oldBlocked: true,
    oldBlockedAmount: '100000.00',
    newBlocked: true,
    newBlockedAmount: '100000.00',
    newActiveBankCount: 5, // изменилось, но это не триггер
    blockDate: '2026-09-01',
    reason: 'Код 01: взыскание задолженности',
    sourceReference: 'service.nalog.ru bi.do, решение №123',
);
check('изменение ТОЛЬКО active_bank_count НЕ создаёт событие', $id === null);

// Триггер 2: сумма изменилась при активной блокировке.
$id = $events->publishFnsBlockChange(
    issuerId: 2,
    oldBlocked: true,
    oldBlockedAmount: '100000.00',
    newBlocked: true,
    newBlockedAmount: '250000.50',
    newActiveBankCount: 5,
    blockDate: '2026-09-01',
    reason: 'Код 01: взыскание задолженности',
    sourceReference: 'service.nalog.ru bi.do, решение №123',
);
check('триггер "изменение суммы" создаёт событие', $id !== null);

// Триггер 3: снятие блокировки.
$id = $events->publishFnsBlockChange(
    issuerId: 2,
    oldBlocked: true,
    oldBlockedAmount: '250000.50',
    newBlocked: false,
    newBlockedAmount: null,
    newActiveBankCount: 0,
    blockDate: '2026-09-01',
    reason: null,
    sourceReference: 'service.nalog.ru bi.do',
);
check('триггер "снятие блокировки" создаёт событие', $id !== null);

// Не-триггер: не было блокировки и не появилось (обычная "чистая" проверка).
$id = $events->publishFnsBlockChange(
    issuerId: 3,
    oldBlocked: false,
    oldBlockedAmount: null,
    newBlocked: false,
    newBlockedAmount: null,
    newActiveBankCount: 0,
    blockDate: '2026-09-01',
    reason: null,
    sourceReference: null,
);
check('нет блокировки -> нет блокировки — событие НЕ создаётся', $id === null);

$e1Events = $db->query("SELECT payload_json FROM events WHERE event_type_code = 'E1' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
check('всего ровно 3 события E1 (started/amount_changed/lifted)', count($e1Events) === 3);

$kinds = array_map(static fn (array $r): string => json_decode((string) $r['payload_json'], true)['kind'], $e1Events);
check('kind-последовательность верна: started, amount_changed, lifted', $kinds === ['started', 'amount_changed', 'lifted']);

// ---------------------------------------------------------------------
// Статическая проверка проводки event_id (НЕ поведенческий тест — SQLite
// не понимает "ON DUPLICATE KEY UPDATE", поэтому FnsBlocksImporter::
// applyResult()/RatingActionsWriter::upsert() целиком через PDO SQLite не
// прогнать, та же граница, что честно описана в докблоке выше). Это
// ровно тот класс регрессии, который нашёлся вживую при первой версии
// Фазы 1: EventPublisher::publishFnsBlockChange() вызывался, но
// fns_blocks.event_id так и оставался NULL навсегда, потому что
// FnsBlocksImporter не делал обратный UPDATE. Здесь — дешёвая защита от
// повторения именно этой ошибки: читаем исходники классов и проверяем,
// что нужный UPDATE физически присутствует в коде.
// ---------------------------------------------------------------------
echo "\n=== Статическая проверка: event_id действительно проставляется ===\n";

$fnsSource = file_get_contents(dirname(__DIR__) . '/src/Fns/FnsBlocksImporter.php');
check(
    'FnsBlocksImporter пишет UPDATE fns_blocks SET event_id',
    $fnsSource !== false && str_contains($fnsSource, 'UPDATE fns_blocks SET event_id')
);

$writerSource = file_get_contents(dirname(__DIR__) . '/src/Ratings/RatingActionsWriter.php');
check(
    'RatingActionsWriter пишет UPDATE rating_actions SET event_id',
    $writerSource !== false && str_contains($writerSource, 'UPDATE rating_actions SET event_id')
);

// НРА исторически дублировала INSERT INTO rating_actions вместо вызова
// RatingActionsWriter::upsert() — если бы это осталось так, НРА тихо
// осталась бы без событий C5 вообще (RatingActionsWriter — единственное
// место, откуда публикуется C5). Проверяем, что дублирующего SQL больше
// нет и апсерт идёт через общий класс.
$nraSource = file_get_contents(dirname(__DIR__) . '/src/Ratings/NraImporter.php');
check(
    'NraImporter НЕ дублирует INSERT INTO rating_actions (использует RatingActionsWriter)',
    $nraSource !== false && !str_contains($nraSource, 'INSERT INTO rating_actions')
);
check(
    'NraImporter вызывает $this->writer->upsert(...)',
    $nraSource !== false && str_contains($nraSource, '$this->writer->upsert(')
);

echo "\n";
if ($failures === 0) {
    echo "ВСЕ {$checks} ПРОВЕРОК ПРОШЛИ.\n";
    exit(0);
}
echo "{$failures} ИЗ {$checks} ПРОВЕРОК ПРОВАЛЕНО.\n";
exit(1);
