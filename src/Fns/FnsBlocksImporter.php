<?php

declare(strict_types=1);

namespace BondKeeper\Fns;

use BondKeeper\Events\EventPublisher;
use BondKeeper\Payments\WorkingCalendar;
use BondKeeper\Support\Logger;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Наполняет fns_blocks (одна строка на эмитента: блокировка + состояние
 * проверки в одном месте) через официальный сервис ФНС
 * service.nalog.ru/bi.do (см. NalogBiClient).
 *
 * Источник — "действующие приостановления" на сегодня, не история. Значит
 * при каждой проверке эмитента ответ — это полный СРЕЗ его активных
 * блокировок прямо сейчас, а не дельта.
 *
 * Пост-обработка (миграция 010): одна строка на ЭМИТЕНТА, не на банк.
 * Раньше (миграция 008) хранили по строке на каждую пару БИК+номер
 * решения — но на реальных данных (ООО "Контрол Лизинг") выяснилось, что
 * одна и та же непрерывная блокировка периодически переиздаётся ФНС под
 * новым номером решения даже без факта разблокировки — ключ по номеру
 * решения принимал это за "снята старая, появилась новая" и плодил
 * дубли. Плюс дата/сумма/основание оказались одинаковыми во всех банках
 * одного эмитента — построчное хранение по банкам не несло
 * дополнительного смысла, только количество. Теперь: данные берутся из
 * ПЕРВОЙ валидной строки ответа, а число банков — в `active_bank_count`
 * (см. consolidateRows). Строка не удаляется при снятии всех
 * блокировок — так же, как раньше, помечается `unblock_date = CURDATE()`.
 *
 * Пост-обработка (миграция 009, перенесено на fns_blocks миграцией 011):
 * раньше неудачная попытка (капча, сетевая ошибка) вообще не оставляла
 * следа в БД — нельзя было отличить "ещё не проверяли" от "проверяли, но
 * не вышло". Теперь КАЖДАЯ попытка отмечается: date_verification = когда
 * была последняя попытка (успешная или нет), verification =
 * 'success'/'error'. is_fns_blocked и содержимое блокировки меняются
 * ТОЛЬКО при успешном разборе ответа (verification = 'success') —
 * капча/ошибка сети НЕ трактуются как "блокировок нет", это именно "не
 * удалось узнать". Изначально эти четыре поля жили на issuers, миграция
 * 011 перенесла их на fns_blocks — после миграции 010 это та же самая
 * "одна строка на эмитента", логичнее держать состояние проверки рядом
 * с её результатом, а не в отдельной таблице.
 *
 * Постановка задачи намеренно НЕ предполагает ежедневный прогон по всем
 * ~495 эмитентам сразу — вызывающий код (bin/check_fns_blocks.php)
 * передаёт заведомо небольшой список и работает МЕДЛЕННО и БЕЗ прокси —
 * часть эмитентов будет пропущена капчей, это ожидаемо и честно
 * отражается как verification='error', а не как повод её обходить.
 *
 * === Событийный движок (Этап 4, сентябрь 2026) ===
 *
 * Перед апсертом читаем ТЕКУЩЕЕ состояние строки (fetchCurrentState()) —
 * это и есть "было" для EventPublisher::publishFnsBlockChange(),
 * вызываемого после успешной записи. 4 триггера события E1 (решение
 * пользователя, не любое изменение вообще, см. докблок EventPublisher):
 * начало блокировки, изменение СУММЫ при уже активной блокировке,
 * изменение ЧИСЛА банков при той же сумме (добавлено 17 сентября 2026),
 * полное снятие. Неуспешная проверка (капча/сеть, markVerificationError())
 * событие не создаёт вообще — мы не узнали ничего нового.
 *
 * fns_blocks.event_id проставляется отдельным UPDATE ПОСЛЕ публикации —
 * той же строки, что только что апсертили (issuer_id тут первичный
 * ключ, апсерт не создаёт вторую строку). Событие могло не создаться
 * (ни один из 4 триггеров не сработал) — тогда UPDATE вообще не
 * выполняется, старое значение event_id (если было — от предыдущего
 * реального триггера) не трогается, а не затирается NULL'ом. Подробности
 * — docs/STAGE4_EVENT_ENGINE.md.
 *
 * === Повторные попытки внутри одного прогона (17 сентября 2026) ===
 *
 * Раньше неудачная попытка (капча/сеть) просто дожидалась следующего
 * ежедневного крона — целые сутки. Пользователь ожидал автоматический
 * повтор "через какое-то время" в рамках того же прогона, его не было —
 * добавлено: checkIssuers() делает до $maxRetries дополнительных проходов
 * ТОЛЬКО по тем эмитентам, что не прошли проверку (капча или ошибка), с
 * паузой $retryDelaySeconds перед каждым повторным проходом (отдельная,
 * более длинная пауза, чем между обычными эмитентами внутри одного
 * прохода — капча у service.nalog.ru эмпирически завязана на сессию/IP,
 * не только на конкретный эмитент, поэтому имеет смысл переждать дольше).
 * Суточный крон остаётся финальной страховкой, если все повторы исчерпаны
 * — не заменяется этим механизмом, а дополняется.
 *
 * === Подтверждение снятия и числа счетов (13 октября 2026) ===
 *
 * Сервис ФНС временами отдаёт по эмитенту не все строки или ни одной, а
 * через несколько часов или на следующий рабочий день — снова прежние.
 * На бою за 09–13.10.2026 это дало у трёх эмитентов четыре ложных
 * «блокировка снята», три ложные «новые блокировки» и четыре скачка
 * числа счетов (14 → 8 → 0 → 14; 10 → 6 → 0 → 10 → 15 → 10). Решение
 * пользователя от 13.10.2026: снятие и изменение числа счетов объявляем
 * только после подтверждения.
 *
 * В fns_blocks лежит ПОДТВЕРЖДЁННОЕ состояние (is_fns_blocked,
 * active_bank_count) — то, о чём клиенты уже знают. Если ответ сервиса
 * расходится с ним по числу счетов (в том числе «строк нет вовсе»),
 * расхождение записывается как ожидающее: pending_bank_count — сколько
 * счетов показал сервис (0 — блокировок нет), pending_since — день, когда
 * мы это впервые увидели (по Москве). Дальше:
 *   - ответ вернулся к подтверждённому — ожидание снимается молча;
 *   - ответ показал третье число — ожидание начинается заново с него;
 *   - ответ тот же, а день уже другой и рабочий — подтверждено:
 *     состояние меняется, уходит событие (снятие — с датой, когда
 *     блокировка пропала впервые, в unblock_date).
 * Сразу, как и раньше, объявляются новая блокировка и изменение суммы.
 * Пока число счетов не подтверждено, в сообщении о сумме стоит прежнее,
 * подтверждённое число.
 *
 * Каждый раз, когда ответ сервиса отличается от предыдущего, он
 * сохраняется целиком в raw_messages (source = 'fns') — и когда событие
 * есть, и когда его нет: по этим строкам потом видно, что именно отдавал
 * сервис. Колонки ожидания — миграция 033.
 *
 * === Признак капчи (14 октября 2026) ===
 *
 * 13.10 ответ «капча и ни одной строки» стали считать неудачной попыткой
 * (в коде с сервера проверка была закомментирована). На бою это остановило
 * проверку всех эмитентов без блокировок: 14.10 все 58 отслеживаемых
 * эмитентов без блокировок стояли в 'error', все 6 с блокировками — в
 * 'success'. Сервис ставит признак
 * капчи и в обычный ответ «блокировок нет». Теперь неудачной попыткой
 * считается только настоящий отказ — HTTP 400 «Требуется ввести цифры с
 * картинки» (NalogBiResult::$refused); ответ без строк с признаком капчи —
 * «блокировок нет», ложное снятие отсекает подтверждение выше.
 */
final class FnsBlocksImporter
{
    private int $checked = 0;
    private int $blockedFound = 0;
    private int $skippedCaptcha = 0;
    private int $captchaFlagWithoutRows = 0;
    private int $failed = 0;
    private int $recoveredOnRetry = 0;
    private int $awaitingConfirmation = 0;

    /**
     * @param ?WorkingCalendar $calendar рабочие дни — для подтверждения снятия и числа счетов;
     *        без календаря рабочими считаются понедельник — пятница
     * @param ?\Closure $today «сегодня» по Москве (ГГГГ-ММ-ДД) — подменяется в тестах
     */
    public function __construct(
        private readonly NalogBiClientInterface $client,
        private readonly PDO $db,
        private readonly EventPublisher $events,
        private readonly int $delaySeconds = 5,
        private readonly ?WorkingCalendar $calendar = null,
        private readonly ?\Closure $today = null,
    ) {
    }

    /**
     * @param array<int, array{id: int, inn: string}> $issuers
     * @param int $maxRetries сколько ДОПОЛНИТЕЛЬНЫХ проходов сделать по
     *     эмитентам, не прошедшим проверку (0 — без повторов, как раньше)
     * @param int $retryDelaySeconds пауза перед каждым повторным проходом
     */
    public function checkIssuers(array $issuers, int $maxRetries = 2, int $retryDelaySeconds = 90): void
    {
        // Без колонок ожидания подтверждения нечего и начинать: иначе первая же запись упадёт посреди прогона.
        try {
            $this->db->query('SELECT pending_bank_count, pending_since FROM fns_blocks LIMIT 1');
        } catch (PDOException $e) {
            throw new RuntimeException('В таблице fns_blocks нет колонок pending_bank_count / pending_since — не применена миграция '
                . 'database/033_fns_blocks_pending_confirmation.sql. Проверка блокировок ФНС не выполнена.');
        }

        $this->checked = count($issuers);
        $pending = $issuers;

        for ($attempt = 0; $pending !== [] && $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                Logger::info(
                    "ФНС: повторная попытка #{$attempt} для " . count($pending)
                    . " эмитент(ов), не прошедших проверку с первого раза (капча/ошибка) — пауза {$retryDelaySeconds} с"
                );
                sleep($retryDelaySeconds);
            }

            $stillPending = [];
            foreach ($pending as $index => $issuer) {
                if ($index > 0) {
                    sleep($this->delaySeconds);
                }
                $success = $this->checkOne((int) $issuer['id'], (string) $issuer['inn']);
                if (!$success) {
                    $stillPending[] = $issuer;
                } elseif ($attempt > 0) {
                    $this->recoveredOnRetry++;
                }
            }
            $pending = $stillPending;
        }

        // Что осталось в $pending после всех попыток — окончательно не
        // прошедшие проверку в этом прогоне (попадут в следующий крон).
        $this->failed = count($pending);

        $this->printReport();
    }

    private function checkOne(int $issuerId, string $inn): bool
    {
        try {
            $result = $this->client->check($inn);
        } catch (\Throwable $e) {
            Logger::warn("ФНС: ошибка проверки ИНН {$inn}: {$e->getMessage()}");
            $this->markVerificationError($issuerId);
            return false;
        }

        // Сервис отказал в ответе (HTTP 400 «введите цифры с картинки»; NalogBiClient помечает его 'refused'):
        // мы НЕ узнали, есть ли блокировка, — попытка неудачная, состояние не трогаем.
        if ($result->refused) {
            $this->skippedCaptcha++;
            Logger::warn("ФНС: сервис отказал (капча) для ИНН {$inn} — статус блокировки не тронут, отмечена только попытка");
            $this->markVerificationError($issuerId);
            return false;
        }
        // Признак капчи в обычном ответе — не отказ: сервис ставит его и в ответ «блокировок нет» (бой, 14.10.2026:
        // с проверкой «капча и ни одной строки», возвращённой 13.10, все 58 отслеживаемых эмитентов без блокировок
        // застряли в 'error', а все 6 с блокировками проходили). Такой ответ читается как «блокировок нет»; от ложного
        // «снята» защищает подтверждение на следующий рабочий день (см. докблок класса).
        if ($result->captchaRequired && $result->rows === []) {
            $this->captchaFlagWithoutRows++;
        }

        $this->applyResult($issuerId, $inn, $result);
        return true;
    }

    /**
     * Неудачная попытка (капча или сетевая/парсинг-ошибка) — единственное,
     * что меняется, это отметка "когда пытались и что вышло". is_fns_blocked
     * и остальное содержимое блокировки НЕ трогаются — отсутствие ответа не
     * должно интерпретироваться как "блокировок нет". Если строки для
     * эмитента в fns_blocks ещё нет вообще (ни разу не проверяли успешно) —
     * создаём её с is_fns_blocked=FALSE по умолчанию схемы, только чтобы
     * было куда записать verification/date_verification.
     */
    private function markVerificationError(int $issuerId): void
    {
        $this->db->prepare(
            "INSERT INTO fns_blocks (issuer_id, verification, date_verification)
             VALUES (:issuer_id, 'error', NOW())
             ON DUPLICATE KEY UPDATE
                verification = 'error',
                date_verification = NOW()"
        )->execute(['issuer_id' => $issuerId]);
    }

    /**
     * Успешный ответ сервиса → запись в fns_blocks и, если нужно, событие.
     * Правило подтверждения — в докблоке класса.
     */
    private function applyResult(int $issuerId, string $inn, NalogBiResult $result): void
    {
        $old = $this->fetchCurrentState($issuerId);
        $summary = $this->consolidateRows($result->rows);
        $observed = $summary['active_bank_count'] ?? 0;
        $today = $this->today();

        // Подтверждённое состояние — то, о чём клиенты уже знают.
        $confirmedBlocked = $old['is_fns_blocked'];
        $confirmedCount = $confirmedBlocked ? $old['active_bank_count'] : 0;
        // Что сервис показывал при прошлой проверке: ожидающее число, если оно есть, иначе подтверждённое.
        $previousObserved = $old['pending_bank_count'] ?? $confirmedCount;

        // Что будет подтверждённым состоянием после этой проверки и что остаётся ждать.
        $newBlocked = $confirmedBlocked;
        $newCount = $confirmedCount;
        $pendingCount = null;
        $pendingSince = null;
        $unblockDate = null;
        $step = 'unchanged';

        if (!$confirmedBlocked) {
            if ($observed > 0) {
                // Новая блокировка — сразу.
                $newBlocked = true;
                $newCount = $observed;
                $step = 'started';
            }
        } elseif ($observed === $confirmedCount) {
            $step = $old['pending_bank_count'] !== null ? 'pending_cancelled' : 'unchanged';
        } elseif ($old['pending_bank_count'] === $observed && $this->canConfirm($old['pending_since'], $today)) {
            if ($observed === 0) {
                $newBlocked = false;
                $newCount = 0;
                $unblockDate = $old['pending_since'];
                $step = 'lift_confirmed';
            } else {
                $newCount = $observed;
                $step = 'count_confirmed';
            }
        } else {
            $sameAsBefore = $old['pending_bank_count'] === $observed && $old['pending_since'] !== null;
            $pendingCount = $observed;
            $pendingSince = $sameAsBefore ? $old['pending_since'] : $today;
            $step = $sameAsBefore ? 'pending_waiting' : ($observed === 0 ? 'pending_lift' : 'pending_count');
        }

        $this->db->beginTransaction();
        try {
            // Строка эмитента должна быть: дальше — обычные UPDATE.
            $this->db->prepare(
                "INSERT INTO fns_blocks (issuer_id, verification, date_verification)
                 VALUES (:issuer_id, 'success', NOW())
                 ON DUPLICATE KEY UPDATE
                    verification = 'success',
                    date_verification = NOW()"
            )->execute(['issuer_id' => $issuerId]);

            if ($summary !== null) {
                $this->db->prepare(
                    'UPDATE fns_blocks SET
                        is_fns_blocked = 1,
                        last_success_verification = NOW(),
                        active_bank_count = :active_bank_count,
                        pending_bank_count = :pending_bank_count,
                        pending_since = :pending_since,
                        decision_number = :decision_number,
                        block_date = :block_date,
                        unblock_date = NULL,
                        blocked_amount = :blocked_amount,
                        reason = :reason,
                        reason_category = :reason_category,
                        source_reference = :source_reference,
                        updated_at = CURRENT_TIMESTAMP
                     WHERE issuer_id = :issuer_id'
                )->execute([
                    'issuer_id' => $issuerId,
                    'active_bank_count' => $newCount,
                    'pending_bank_count' => $pendingCount,
                    'pending_since' => $pendingSince,
                    'decision_number' => $summary['decision_number'],
                    'block_date' => $summary['block_date'],
                    'blocked_amount' => $summary['blocked_amount'],
                    'reason' => $summary['reason'],
                    'reason_category' => $summary['reason_category'],
                    'source_reference' => $summary['source_reference'],
                ]);
            } elseif ($newBlocked) {
                // В ответе блокировок нет, но снятие ещё не подтверждено: саму блокировку не трогаем.
                $this->db->prepare(
                    'UPDATE fns_blocks SET
                        last_success_verification = NOW(),
                        pending_bank_count = 0,
                        pending_since = :pending_since,
                        updated_at = CURRENT_TIMESTAMP
                     WHERE issuer_id = :issuer_id'
                )->execute(['issuer_id' => $issuerId, 'pending_since' => $pendingSince]);
            } else {
                // Блокировок нет: эмитент и раньше был свободен либо снятие подтверждено.
                // unblock_date ставится только при подтверждённом снятии — днём, когда блокировка пропала впервые.
                $this->db->prepare(
                    'UPDATE fns_blocks SET
                        is_fns_blocked = 0,
                        last_success_verification = NOW(),
                        active_bank_count = 0,
                        pending_bank_count = NULL,
                        pending_since = NULL,
                        unblock_date = COALESCE(:unblock_date, unblock_date),
                        updated_at = CURRENT_TIMESTAMP
                     WHERE issuer_id = :issuer_id'
                )->execute(['issuer_id' => $issuerId, 'unblock_date' => $unblockDate]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($summary !== null) {
            $this->blockedFound++;
        }
        if ($pendingCount !== null) {
            $this->awaitingConfirmation++;
        }
        $this->logStep($step, $inn, $observed, $confirmedCount, $pendingSince ?? $old['pending_since']);

        // Ответ сервиса целиком — в журнал сырых сообщений: вместе с событием, а если события нет, но ответ
        // отличается от предыдущего, — отдельной строкой.
        $answer = [
            'inn' => $inn,
            'step' => $step,
            'captcha_required' => $result->captchaRequired,
            'rows_in_answer' => count($result->rows),
            'observed_bank_count' => $observed,
            'confirmed_bank_count' => $newCount,
            'pending_bank_count' => $pendingCount,
            'pending_since' => $pendingSince,
            'rows' => $result->rows,
        ];
        $sourceReference = $summary['source_reference'] ?? "service.nalog.ru bi.do, ИНН {$inn}: блокировок в ответе нет";

        // Событие — уже ПОСЛЕ commit(): публикуем только то, что реально
        // сохранилось, а не то, что могло откатиться при ошибке записи.
        // На вход идёт подтверждённое состояние «до» и «после»: пока снятие
        // или число счетов ждут подтверждения, они здесь равны прежним, и
        // события о них не будет.
        $eventId = $this->events->publishFnsBlockChange(
            $issuerId,
            $confirmedBlocked,
            $old['blocked_amount'],
            $old['active_bank_count'],
            $newBlocked,
            $summary !== null ? $summary['blocked_amount'] : ($newBlocked ? $old['blocked_amount'] : null),
            $newCount,
            $summary['block_date'] ?? date('Y-m-d'),
            $summary['reason'] ?? null,
            $sourceReference,
            $answer,
        );

        // fns_blocks.event_id — только если событие реально создалось.
        // publishFnsBlockChange() уже содержит проверку фильтра
        // существенности — здесь просто не трогаем строку, если он вернул
        // null, а не затираем прежний event_id NULL'ом.
        if ($eventId !== null) {
            $this->db->prepare(
                'UPDATE fns_blocks SET event_id = :event_id WHERE issuer_id = :issuer_id'
            )->execute([
                'event_id' => $eventId,
                'issuer_id' => $issuerId,
            ]);
        } elseif ($observed !== $previousObserved) {
            $this->events->recordFnsObservation($sourceReference, $answer);
        }
    }

    /**
     * Можно ли подтвердить ожидающее изменение: оно замечено в один из
     * прошлых дней, а сегодня рабочий день. Проверка идёт каждые полчаса,
     * так что подтверждает первая удачная проверка следующего рабочего дня.
     */
    private function canConfirm(?string $pendingSince, string $today): bool
    {
        if ($pendingSince === null || $today <= $pendingSince) {
            return false;
        }

        return $this->calendar !== null
            ? $this->calendar->isWorkingDay($today)
            : (int) date('N', (int) strtotime($today)) <= 5;
    }

    /** «Сегодня» по московскому времени: серверное время может быть другим, а день подтверждения считаем по Москве. */
    private function today(): string
    {
        return $this->today !== null
            ? (string) ($this->today)()
            : (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
    }

    private function logStep(string $step, string $inn, int $observed, int $confirmed, ?string $pendingSince): void
    {
        $since = $pendingSince !== null ? date('d.m.Y', (int) strtotime($pendingSince)) : '?';
        match ($step) {
            'started' => Logger::info("ФНС: ИНН {$inn} — новая блокировка, счетов: {$observed}"),
            'unchanged' => $observed > 0 ? Logger::info("ФНС: ИНН {$inn} — активная блокировка счёта подтверждена ({$observed} банк(ов))") : null,
            'pending_cancelled' => Logger::info("ФНС: ИНН {$inn} — ответ вернулся к прежнему (счетов: {$confirmed}), ожидание подтверждения снято"),
            'pending_lift' => Logger::info("ФНС: ИНН {$inn} — в ответе блокировок нет, в базе счетов: {$confirmed}. Снятие не объявляем: ждём подтверждения на следующий рабочий день"),
            'pending_count' => Logger::info("ФНС: ИНН {$inn} — в ответе счетов: {$observed}, в базе: {$confirmed}. Изменение не объявляем: ждём подтверждения на следующий рабочий день"),
            'pending_waiting' => Logger::info("ФНС: ИНН {$inn} — в ответе по-прежнему счетов: {$observed}, в базе: {$confirmed}; ждём подтверждения (замечено {$since})"),
            'lift_confirmed' => Logger::info("ФНС: ИНН {$inn} — снятие блокировки подтверждено (блокировок нет с {$since})"),
            'count_confirmed' => Logger::info("ФНС: ИНН {$inn} — число счетов подтверждено: было {$confirmed}, стало {$observed} (замечено {$since})"),
            default => null,
        };
    }

    /**
     * Состояние строки fns_blocks ДО этой проверки: подтверждённое (оно же
     * «было» для EventPublisher::publishFnsBlockChange()) и ожидающее
     * подтверждения. Строки ещё нет вообще (эмитент проверяется впервые) —
     * трактуем как «не заблокирован», это и есть нейтральное начальное
     * состояние.
     *
     * @return array{is_fns_blocked: bool, blocked_amount: ?string, active_bank_count: int, pending_bank_count: ?int, pending_since: ?string}
     */
    private function fetchCurrentState(int $issuerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT is_fns_blocked, blocked_amount, active_bank_count, pending_bank_count, pending_since FROM fns_blocks WHERE issuer_id = :issuer_id'
        );
        $stmt->execute(['issuer_id' => $issuerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return ['is_fns_blocked' => false, 'blocked_amount' => null, 'active_bank_count' => 0, 'pending_bank_count' => null, 'pending_since' => null];
        }

        return [
            'is_fns_blocked' => (bool) $row['is_fns_blocked'],
            'blocked_amount' => $row['blocked_amount'],
            'active_bank_count' => (int) $row['active_bank_count'],
            'pending_bank_count' => $row['pending_bank_count'] !== null ? (int) $row['pending_bank_count'] : null,
            'pending_since' => $row['pending_since'] !== null ? substr((string) $row['pending_since'], 0, 10) : null,
        ];
    }

    /**
     * Сводит все строки ответа в одну запись на эмитента: дата, сумма и
     * основание берутся из ПЕРВОЙ валидной строки (на практике совпадают
     * во всех банках одного эмитента — см. докблок класса), а число
     * валидных строк идёт в active_bank_count вместо перечисления
     * конкретных БИК, которые сами по себе не несут продуктовой ценности.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{active_bank_count: int, decision_number: string, block_date: string, blocked_amount: ?string, reason: ?string, reason_category: ?string, source_reference: string}|null
     */
    private function consolidateRows(array $rows): ?array
    {
        $valid = [];
        foreach ($rows as $row) {
            $bik = trim((string) ($row['BIK'] ?? ''));
            $decisionNumber = trim((string) ($row['NOMER'] ?? ''));
            $blockDate = $this->parseDate((string) ($row['DATABEGIN'] ?? $row['DATA'] ?? ''));

            if ($bik === '' || $decisionNumber === '' || $blockDate === null) {
                Logger::warn('ФНС: строка ответа без BIK/NOMER/даты пропущена: ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                continue;
            }

            $valid[] = ['row' => $row, 'decision_number' => $decisionNumber, 'block_date' => $blockDate];
        }

        if ($valid === []) {
            return null;
        }

        $first = $valid[0];
        $kodOsnov = trim((string) ($first['row']['KODOSNOV'] ?? ''));

        return [
            'active_bank_count' => count($valid),
            'decision_number' => $first['decision_number'],
            'block_date' => $first['block_date'],
            'blocked_amount' => $this->parseAmount($first['row']['SALDOENS'] ?? null),
            'reason' => $this->reasonText($kodOsnov),
            'reason_category' => $this->reasonCategory($kodOsnov),
            'source_reference' => sprintf(
                'service.nalog.ru bi.do, решение №%s от %s, банков одновременно: %d',
                $first['decision_number'],
                (string) ($first['row']['DATA'] ?? '?'),
                count($valid)
            ),
        ];
    }

    /**
     * SALDOENS — "Размер отрицательного сальдо ЕНС" (подтверждено вживую
     * пользователем через интерфейс сайта, сверено с колонкой в БД).
     * Приходит только для кодов основания 01/03 (см. сноску "*" в
     * справочнике на самой странице результата) — для остальных кодов
     * поля в ответе нет вообще, blocked_amount останется NULL, и это
     * ожидаемо, а не пробел. Значение приходит с ведущими пробелами
     * (например "       302156193.95") — только тримить, разделитель
     * дробной части уже точка, тысячи ничем не разделены.
     */
    private function parseAmount(mixed $rawSaldoEns): ?string
    {
        if ($rawSaldoEns === null) {
            return null;
        }
        $trimmed = trim((string) $rawSaldoEns);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        return $trimmed;
    }

    private function parseDate(string $ddMmYyyy): ?string
    {
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $ddMmYyyy, $m)) {
            return null;
        }

        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    /**
     * Справочник кодов основания приостановления операций по счетам —
     * подтверждён вживую со страницы результата service.nalog.ru (август
     * 2026). 1 и 3 — про деньги (взыскание задолженности / обеспечение
     * исполнения решения по налоговому правонарушению), 2/4/5/6 — про
     * непредставление документов/отчётности, к сумме долга не сводятся.
     * Это первое приближение, не финальное решение — см.
     * docs/STAGE1_POSTPROCESSING.md.
     */
    private function reasonCategory(string $kodOsnov): ?string
    {
        // KODOSNOV в реальном ответе приходит с ведущим нулём ("01".."06"),
        // не как в человекочитаемой легенде на сайте ("1".."6") — сверено
        // вживую на реальном ответе (bondkeeper.ru, август 2026).
        return match (ltrim($kodOsnov, '0') ?: '0') {
            '1', '3' => 'tax_debt',
            '2', '4', '5', '6' => 'other',
            default => null,
        };
    }

    private function reasonText(string $kodOsnov): ?string
    {
        $map = [
            '1' => 'Принятие налоговым органом решения о взыскании задолженности',
            '2' => 'Непредставление налоговой декларации в течение 20 дней по истечении установленного срока',
            '3' => 'Обеспечение исполнения решения о привлечении к ответственности за налоговое правонарушение (п. 10 ст. 101 НК РФ)',
            '4' => 'Неисполнение обязанности по передаче квитанции о приёме требования/уведомления от налогового органа',
            '5' => 'Неисполнение обязанности по обеспечению получения документов от налогового органа в электронной форме',
            '6' => 'Непредставление налоговым агентом расчёта сумм НДФЛ/страховых взносов в течение 20 дней',
        ];
        $normalized = ltrim($kodOsnov, '0') ?: '0';

        return isset($map[$normalized])
            ? "Код {$kodOsnov}: {$map[$normalized]}"
            : "Код {$kodOsnov}: расшифровка не найдена в справочнике";
    }

    private function printReport(): void
    {
        Logger::info('=== Отчёт по проверке блокировок счетов (ФНС, service.nalog.ru) ===');
        Logger::info("Эмитентов проверено: {$this->checked}");
        Logger::info("С активной блокировкой: {$this->blockedFound}");
        Logger::info("Ждут подтверждения (снятие или число счетов): {$this->awaitingConfirmation}");
        Logger::info("Отказ сервиса (капча, HTTP 400), суммарно по всем попыткам: {$this->skippedCaptcha}");
        Logger::info("Ответов без строк с признаком капчи (прочитаны как «блокировок нет»): {$this->captchaFlagWithoutRows}");
        Logger::info("Восстановлено повтором (не прошли с первого раза, но прошли на повторной попытке): {$this->recoveredOnRetry}");
        Logger::info("Окончательно не прошли проверку в этом прогоне (после всех повторов): {$this->failed}");
    }
}
