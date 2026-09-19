# BondKeeper — этап 1: БД + сидирование бесплатными данными

Реализация первого этапа дорожной карты:
1. Создать БД.
2. Заполнить справочные таблицы бесплатными данными (ISS API Мосбиржи), без платных источников (НРД, e-disclosure).
3. Понять, что из схемы (`documents/2026.08.08_BondKeeper_Schema_Reference.pdf`, `documents/2026.08.08_bondkeeper_schema_v2.sql`) вообще можно бесплатно вытащить.

Пошаговая инструкция по разворачиванию на боевом сервере (ISPmanager, домен уже создан) и по тестированию первого прогона — `docs/DEPLOY_STAGE1.md`.

## Стек

PHP 8.3+ (CLI, `pdo_mysql`, `curl`), MySQL 8.0+, cron. Без composer/фреймворка — намеренно: `bin/*.php` должны запускаться на обычном shared-хостинге (см. скриншот конфигурации ПО) через `php bin/seed_market.php` без шага установки зависимостей.

## Структура

```
database/001_schema.sql              — базовая схема БД (см. "Изменения относительно v2" ниже)
database/002_issuer_moex_emitter_id.sql — миграция: ИНН эмитента не отдаётся ISS API (см. ниже)
database/003_widen_value_per_bond.sql   — миграция: DECIMAL(12,4) тесна для номиналов от 100 млн ₽
database/004_coupon_type_unknown.sql    — миграция: coupon_type='unknown' вместо угаданного 'fixed'
database/005_is_mortgage_backed.sql     — миграция: флаг ИЦБ (ипотечные бумаги без полного графика вперёд)
database/006_coupons_amortizations_upsert.sql — миграция: coupons/amortizations больше не "замерзают" после первой строки
database/007_initial_nominal_value.sql        — миграция: фиксированная база для расчёта остатка номинала в redemptions
database/008_fns_blocks_upsert_keys.sql       — миграция: ключ для апсерта блокировок счетов (несколько банков на эмитента)
database/009_fns_verification_tracking.sql    — миграция: issuers.verification/date_verification/last_success_verification
database/010_fns_blocks_one_row_per_issuer.sql — миграция: fns_blocks — одна строка на эмитента, не на банк
database/011_fns_verification_to_fns_blocks.sql — миграция: verification/is_fns_blocked переехали с issuers на fns_blocks
database/012_offers_unknown_and_buyback_flag.sql — миграция: offers — заготовка под первый сев (unknown-плейсхолдеры, has_buyback_date)
database/013_rating_actions_outlook_split.sql    — миграция: rating_actions.outlook_from/outlook_to, review_type default, current_ratings.created_at
src/Database.php                     — подключение к MySQL (PDO)
src/Iss/IssClient.php                — HTTP-клиент ISS API Мосбиржи
src/Iss/SecuritiesImporter.php       — issuers/securities/redemptions(scheduled_maturity)
src/Iss/IssuerNameShortener.php      — issuers.short_name из full_name (эвристика: сокращение ОПФ — ПАО/ООО/АО/... — по словарю, оба формата ИСС: ОПФ префиксом и хвостом в скобках)
src/Iss/BondizationImporter.php      — coupons/amortizations
src/Fns/NalogBiClient.php            — HTTP-клиент service.nalog.ru/bi.do (блокировки счетов)
src/Fns/FnsBlocksImporter.php        — fns_blocks, issuers.is_fns_blocked
src/Iss/OffersImporter.php           — offers: дата — bondization/offers, has_buyback_date/offer_type put/call/unknown — доска-эндпоинт (переписано 14 сентября 2026, см. docs/STAGE1_POSTPROCESSING.md)
src/Ratings/XlsxReader.php           — минимальный читатель .xlsx (ZIP+XML) без зависимостей
src/Ratings/IssuerMatcher.php        — сопоставление эмитента агентства с issuers.id по ИНН → явной связке SPV (findIssuerIdBySpvLink(), issuer_spv_links, миграция 023) → ISIN (АКРА) → точному имени → "по корню" названия (findIssuerIdByRootName(), миграция 022, SPV/материнская компания — см. docs/STAGE3_RATINGS.md)
src/Ratings/RatingsNormalizer.php    — общие преобразования (прогноз, дата) для рейтинговых выгрузок; isBondIssueRedemptionWithdrawal() — фильтр шума "отозван рейтинг выпуска из-за погашения" (не эмитента), см. docs/STAGE3_RATINGS.md
src/Ratings/RatingsHttp.php          — HTTP-загрузчик с ретраями для сайтов рейтинговых агентств
src/Ratings/NkrImporter.php          — current_ratings из Excel-выгрузки НКР (ratings.ru)
src/Ratings/NraImporter.php          — current_ratings из Excel-выгрузки НРА (ra-national.ru)
src/Ratings/ExpertRaClient.php       — постраничный обход raexpert.ru (Эксперт РА) с cookie-сессией
src/Ratings/ExpertRaImporter.php     — current_ratings из raexpert.ru (Эксперт РА)
src/Ratings/AcraImporter.php         — current_ratings из JSON-файла АКРА, который готовит пользователь (см. docs/STAGE3_RATINGS.md)
src/Ratings/ManualRatingsImporter.php — current_ratings из ручного xlsx (рейтинги, не найденные через автоматические источники)
src/Ratings/RatingActionsWriter.php  — общий апсерт в rating_actions (ключ — UNIQUE(issuer_id, agency, action_date), полностью распознанные действия, см. docs/STAGE3_RATINGS.md)
src/Ratings/CurrentRatingsReconciler.php — сверка current_ratings с "истиной" от агентства (только сравнивает и печатает расхождения; applyMissingInOurs() — единственное исключение, пишет новые строки для эмитентов, которых у нас нет вообще; missing_in_snapshot несёт флаг expected — root/SPV-совпадение, ожидаемое расхождение), см. docs/STAGE3_RATINGS.md
bin/reconcile_ratings.php            — запуск сверки (--agency=nkr|expert_ra|nra|all [--apply-missing]), см. docs/STAGE3_RATINGS.md
bin/link_spv.php                     — управление issuer_spv_links: --spv-inn=... --issuer-inn=...|--issuer-id=... [--spv-name=...] [--note=...], --list, --remove — см. docs/STAGE3_RATINGS.md
src/Ratings/CurrentRatingsSync.php   — чтение/запись current_ratings для новостных импортёров (источник rating_from/outlook_from; апсерт кэша только если действие не старше уже сохранённого)
src/Ratings/RatingNewsLog.php        — журнал просмотренных пресс-релизов (rating_news_log) — дедуп/ретрай по (agency, source_url), независимо от rating_actions
src/Ratings/NkrTitleParser.php       — чистый (без БД/сети) разбор заголовков пресс-релизов НКР
src/Ratings/NkrNewsImporter.php      — rating_actions из истории пресс-релизов НКР, скользящее окно (--days)
src/Ratings/ExpertRaNewsImporter.php — rating_actions из ленты пресс-релизов Эксперт РА, скользящее окно (--days), сопоставление по ИНН со страницы релиза + запасной путь по имени
src/Ratings/AcraEmailParser.php      — ШАБЛОН: разбор текста писем АКРА "Новое рейтинговое действие" (чистая функция, IMAP-часть ещё не реализована — см. docs/STAGE3_RATINGS.md)
src/Ratings/AcraNewsTitleParser.php  — разбор заголовков пресс-релизов АКРА с сайта (чистая функция, проверена на 10 реальных заголовках + ISIN-извлечение)
src/Ratings/AcraNewsImporter.php     — rating_actions из ленты пресс-релизов АКРА, скользящее окно (--days), сопоставление ИНН → ISIN → имя (см. docs/STAGE3_RATINGS.md)
bin/seed_market.php                  — запуск сидирования справочника (шаг 1)
bin/seed_bondization.php             — запуск сидирования графика выплат (шаг 2)
bin/seed_offers.php                  — запуск сидирования оферт (шаг 3)
bin/seed_ratings.php                 — запуск сидирования рейтингов (--agency=nkr|nra|expert_ra|acra|manual|nkr-news|expert_ra-news|acra-news [--days=2] [--full], этап 3, см. docs/STAGE3_RATINGS.md)
bin/daemon_nkr_news.php              — автономный цикл nkr-news вместо OS cron (частый/глубокий проход), если на сервере нет доступа к обычному планировщику
bin/daemon_expert_ra_news.php        — то же для expert_ra-news
bin/daemon_nra.php                   — то же для nra (простой цикл, без деления на частый/глубокий)
bin/check_fns_blocks.php             — точечная/по расписанию (каждые 30 мин, будни 5-17) проверка блокировок счетов; по расписанию — --from-watchlist (реальный лист наблюдения пользователей бота, см. docs/STAGE1_POSTPROCESSING.md)
config/fns_watchlist.txt             — статичный список ИНН для ручной точечной проверки (--watchlist-file=), НЕ используется в кроне с 17 сентября 2026
bin/debug_iss_security.php           — разовая диагностика сырого ответа ISS API по ISIN
bin/debug_rating_page.php            — разовая диагностика структуры страницы рейтингового агентства (этап 3, см. docs/STAGE3_RATINGS.md)
bin/debug_acra_news.php              — разведка/сухой прогон парсинга новостей АКРА с сайта (без БД, без записи — см. docs/STAGE3_RATINGS.md)
bin/debug_acra_ratings.php           — разведка списка/карточки эмитента и пагинации АКРА current_ratings (без БД, без записи — см. docs/STAGE3_RATINGS.md)
src/Events/EventPublisher.php        — единственная точка создания events/raw_messages (C5 рейтинговые действия, E1 блокировки ФНС), этап 4, см. docs/STAGE4_EVENT_ENGINE.md
tests/test_event_engine.php          — офлайн-проверка EventPublisher на SQLite (запуск: php -d extension=pdo_sqlite -d extension=mbstring tests/test_event_engine.php)
database/019_bot_ux_tariff_and_dialog_state.sql — миграция: тариф free (10 эмитентов/14 дней), таблицы bot_dialog_state/support_thread_map
database/020_founder_tariff.sql — миграция: тариф founder (max_tracked_issuers=NULL — без ограничения)
bin/grant_founder_subscription.php — разовая выдача тарифа founder двум учредителям по telegram_id (безлимит эмитентов + фактически бессрочно), идемпотентно
database/021_rating_news_log_bond_redemption_status.sql — миграция: новый статус rating_news_log.status для отфильтрованного шума "отозван рейтинг выпуска из-за погашения"
database/022_matched_by_root_name.sql — миграция: rating_actions.matched_by_root_name/current_ratings.matched_by_root_name — флаг сопоставления "по корню" названия (SPV/материнская компания)
database/023_issuer_spv_links.sql — миграция: таблица issuer_spv_links — явная ручная связка "неизвестный ИНН → issuer_id" для пар, где имена текстуально не связаны
src/Telegram/TelegramClientInterface.php — интерфейс Bot API (sendMessage/answerCallbackQuery/editMessageText) для подмены фейком в офлайн-тестах
src/Telegram/TelegramClient.php      — HTTP-клиент Telegram Bot API (long polling, sendMessage/editMessageText/answerCallbackQuery)
src/Telegram/TelegramBotConfig.php   — токен бота + admin_telegram_id из config/telegram_bot.php (не коммитится, см. .example рядом)
src/Telegram/BotFormatting.php       — общие форматтеры (agencyDisplayName/formatDate/formatMoney) для BotCommandHandler и NotificationDispatcher
src/Telegram/BotCommandHandler.php   — разбор команд/кнопок бота: меню, «Выбор эмитентов» (умный поиск + листалка), «Статус», «Подписка», «О сервисе», чат-релей «Помощь» — см. docs/BOT_UX_SPEC.md
src/Telegram/NotificationDispatcher.php — рассылка событий (events) подписчикам из watchlist в Telegram, этап 4 Фаза 3, см. docs/STAGE4_EVENT_ENGINE.md
bin/daemon_telegram_bot.php          — цикл бота: long-polling команд + рассылка раз в 60 с, один процесс
config/telegram_bot.example.php      — шаблон конфига токена бота (скопировать в telegram_bot.php, не коммитить)
tests/test_bot_ux_screens.php        — офлайн-проверка экранов/разделов бота на SQLite (73 проверки)
tests/test_notification_dispatcher.php — офлайн-проверка рассылки на SQLite (27 проверок, покрыта целиком — без MySQL-диалекта)
tests/test_no_duplicate_named_params.php — статическая проверка ->prepare(): нет повторов :имени плейсхолдера в одном запросе (PDO::ATTR_EMULATE_PREPARES=false — MySQL это не прощает, в отличие от SQLite)
tests/test_issuer_name_shortener.php — офлайн-проверка IssuerNameShortener (24 проверки, чистая текстовая логика, БД не нужна)
bin/backfill_issuer_short_names.php  — разовая пересборка issuers.short_name из full_name для строк, накопленных до появления IssuerNameShortener (идемпотентно, безопасно перезапускать)
tests/test_offers_importer.php       — офлайн-проверка OffersImporter (20 проверок: выбор даты/типа оферты из bondization/offers, put/call — чистая логика, БД не нужна)
tests/test_ratings_normalizer.php    — офлайн-проверка RatingsNormalizer::isBondIssueRedemptionWithdrawal() (8 проверок, чистая текстовая логика, БД не нужна)
tests/test_current_ratings_reconciler.php — офлайн-проверка CurrentRatingsReconciler (23 проверки: сравнение + applyMissingInOurs()/кейс 3 + expected-флаг missing_in_snapshot/кейс 5b — MySQL-диалекта нет)
bin/debug_bond_redemption_ratings.php — диагностика (без записи в БД): находит уже записанные ДО фикса 17 сентября ложные "рейтинг отозван" от отзыва выпуска из-за погашения
bin/fix_bond_redemption_ratings.php  — разовое исправление (ПИШЕТ в БД): удаляет эти ложные rating_actions/events и пересчитывает current_ratings из оставшейся истории; запускать после debug_bond_redemption_ratings.php
tests/test_issuer_root_matching.php  — офлайн-проверка IssuerMatcher::rootCompanyName()/findIssuerIdByRootName() (25 проверок, включая реальный случай Аэрофьюэлз/Аэрофьюэлз Групп)
tests/test_issuer_spv_link.php       — офлайн-проверка IssuerMatcher::findIssuerIdBySpvLink() + приоритет связки над root в resolveIssuerIdWithPriority() (7 проверок)
tests/test_default_grade_clears_outlook.php — офлайн-проверка RatingsNormalizer::isDefaultGrade() + CurrentRatingsSync::resolveOutlook() (20 проверок, реальный случай ООО «ЛКХ», рейтинг "D")
tests/test_root_priority_conflict.php — офлайн-проверка приоритета ИНН/связки над root ВНУТРИ одного прогона NkrImporter/ExpertRaImporter/AcraImporter (12 проверок)
```

## Запуск

```bash
cp .env.example .env   # прописать реальные DB_* значения
mysql -u root -p -e "CREATE DATABASE bondkeeper CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p bondkeeper < database/001_schema.sql
mysql -u root -p bondkeeper < database/002_issuer_moex_emitter_id.sql
mysql -u root -p bondkeeper < database/003_widen_value_per_bond.sql
mysql -u root -p bondkeeper < database/004_coupon_type_unknown.sql
mysql -u root -p bondkeeper < database/005_is_mortgage_backed.sql
mysql -u root -p bondkeeper < database/006_coupons_amortizations_upsert.sql
mysql -u root -p bondkeeper < database/007_initial_nominal_value.sql
mysql -u root -p bondkeeper < database/008_fns_blocks_upsert_keys.sql
mysql -u root -p bondkeeper < database/009_fns_verification_tracking.sql
mysql -u root -p bondkeeper < database/010_fns_blocks_one_row_per_issuer.sql
mysql -u root -p bondkeeper < database/011_fns_verification_to_fns_blocks.sql
mysql -u root -p bondkeeper < database/012_offers_unknown_and_buyback_flag.sql
mysql -u root -p bondkeeper < database/013_rating_actions_outlook_split.sql
mysql -u root -p bondkeeper < database/014_rating_actions_dedup_key.sql
mysql -u root -p bondkeeper < database/015_rating_actions_review_queue.sql
mysql -u root -p bondkeeper < database/016_rating_actions_source_title.sql
mysql -u root -p bondkeeper < database/017_rating_news_log.sql
mysql -u root -p bondkeeper < database/018_outlook_under_review.sql
mysql -u root -p bondkeeper < database/019_bot_ux_tariff_and_dialog_state.sql
mysql -u root -p bondkeeper < database/020_founder_tariff.sql
php bin/grant_founder_subscription.php   # разовая выдача тарифа founder учредителям
mysql -u root -p bondkeeper < database/021_rating_news_log_bond_redemption_status.sql
mysql -u root -p bondkeeper < database/022_matched_by_root_name.sql
mysql -u root -p bondkeeper < database/023_issuer_spv_links.sql

php bin/seed_market.php        # issuers, securities, redemptions(scheduled_maturity)
php bin/seed_bondization.php   # coupons, amortizations
php bin/seed_offers.php        # offers (дата, has_buyback_date, offer_type)
```

По расписанию — раз в сутки (справочник меняется редко, см. `documents/2026.08.08_Seeding_And_Polling_Strategy_QA.docx` про частоту опроса): cron-примеры даны в шапке каждого `bin/*.php`.

## Изменения относительно `bondkeeper_schema_v2.sql`

Схема была впервые реально развёрнута (а не только рецензирована построчно) в рамках этого этапа — на настоящем MySQL 8.0.46. Два расхождения с распечаткой v2, найденные только при фактическом `CREATE`/`ALTER`, а не при чтении SQL:

1. **`event_stories.chk_event_stories_exactly_one` убран.** MySQL 8 отклоняет `CHECK` на колонке, у которой есть `FOREIGN KEY ... ON DELETE SET NULL` (ошибка 3823) — сервер не может гарантировать, что каскадный `SET NULL` не нарушит условие задним числом. Правило "ровно одно из `coupon_id`/`amortization_id`/`redemption_id` заполнено" переносится в код классификатора событий (единственное место, создающее строки в этой таблице, — задача следующего этапа).
2. **`watchlist`: FK на `issuer_id`/`security_id` вынесены в отдельный `ALTER TABLE`, `ON DELETE CASCADE` → `RESTRICT`.** `issuer_id` и `security_id` — базовые столбцы генерируемой колонки `issuer_only_key`. MySQL 8 официально запрещает `CASCADE`/`SET NULL`/`SET DEFAULT` как referential action на таких столбцах (ошибка 3192, MySQL Reference Manual §13.1.20.8) и отдельно не позволяет объявить такой FK в одной инструкции с `CREATE TABLE`, где эта generated-колонка определена (ошибка 1215). На практике `issuers`/`securities` физически не удаляются (см. `status`-поле в `securities`), так что `RESTRICT` ничего не меняет по сути — просто корректно проходит на реальном сервере.

Остальные 18 таблиц, таксономия событий (24 типа) и тарифы применились без изменений — полный лог `database/001_schema.sql` воспроизводит первоисточник `bondkeeper_schema_v2.sql` за вычетом этих двух правок.

## Как это было проверено

Часть логики проверена локально (структура схемы, идемпотентность записи — на реальном MySQL 8.0.46 против тестового двойника `IssClient`, потому что в среде разработки не было исходящего доступа в интернет), а часть — уже вживую, на боевом сервере `bondkeeper.ru`, включая находку про ИНН ниже.

## Что бесплатно (ISS API), а что нет — ответ на пункт 3 дорожной карты

**ИНН эмитента — история находки.** Изначально казалось, что ISS API его не отдаёт вообще: карточка бумаги (`/iss/securities/{ISIN}.json`, блок `description`) содержит только `EMITTER_ID` (внутренний числовой ID), а `/iss/emitents.json` не существует как ресурс (`/iss/index.json` перечисляет ровно 8 групп ресурсов, эмитентов среди них нет). Это привело к промежуточному решению в `database/002_issuer_moex_emitter_id.sql` — сделать `issuers.inn` необязательным и сидировать эмитентов по одному `EMITTER_ID`.

Оказалось, что дело было не в отсутствии данных, а не в том эндпоинте: **`/iss/securities.json?q={ISIN}` (общий поиск) отдаёт `emitent_inn`, `emitent_title`, `emitent_id` и `secid` прямыми полями** — не только для отдельных случаев, а системно (проверено и на обычной корпоративной бумаге, и на ОФЗ, где эмитент — Минфин РФ). Импортёр переписан на этот эндпоинт как основной источник identity эмитента; `database/002_...sql` (необязательный `inn` + `moex_emitter_id` как резервный ключ) остаётся в силе — `moex_emitter_id` по-прежнему полезен как более стабильный ключ на случай будущих расхождений, но теперь `inn` реально заполняется, а не остаётся `NULL`.

Заодно нашлась и вторая, независимая причина части пропусков: **у гособлигаций (ОФЗ) `SECID` не совпадает с `ISIN`** (пример: ISIN `RU0002868001` = SECID `SU46012RMFS9`) — карточку бумаги (`description`) нужно запрашивать по `secid` (который теперь тоже приходит из `/iss/securities.json?q=`), а не по ISIN. Раньше запрос по ISIN для ОФЗ молча возвращал пустой ответ — из-за этого пропадали все 59 ОФЗ в первом прогоне.

**Подтверждено вживую, на реальном ответе ISS API (bondkeeper.ru, август 2026):**
ИНН эмитента, юридическое название эмитента, ISIN, SECID, рег. номер, краткое/полное наименование бумаги, номинал, дата погашения, объём выпуска, уровень листинга, признак «только квалифицированным инвесторам» (`ISQUALIFIEDINVESTORS`), частота купона (`COUPONFREQUENCY` — число выплат в год), весь график купонов и амортизаций (bondization-эндпоинт, включая реальную ставку купона — поле `valueprc`, не `couponpercent`, которого в ответе не существует). Отдельно: валюта в ISS API приходит как `FACEUNIT='SUR'` — устаревший код рубля до деноминации 1998 года, а не `RUB`; импортёр нормализует это на входе.

**`BOND_TYPE` — не таксономия типа купона, а тег самой заметной особенности бумаги.** У одних бумаг это характер ставки («Фикс с известным купоном»), у других — структура погашения («Амортизируемые облигации», ипотечные бумаги ДОМ.РФ), тип инструмента («Структурная облигация») или валюта номинала («Валютные облигации» — причём один из наблюдавшихся примеров этой категории вдобавок оказался ещё и бессрочной облигацией). Значит, отсутствие ключевых слов floating/indexed/zero_coupon не означает «точно fixed» — значит «BOND_TYPE сейчас про что-то другое». `coupon_type` в схеме (миграция `004_coupon_type_unknown.sql`) получил значение `unknown`: `fixed` теперь ставится только при явном совпадении с «фикс», иначе — честно «не определено», а не угаданное значение по умолчанию (у структурных облигаций `coupon_type='unknown'` так и останется — выплата у них часто зависит от базового актива и не сводится к простому fixed/floating, это ожидаемо).

Из этой же находки — **`is_structured` тоже читается напрямую из `BOND_TYPE`** (ключевое слово «структурн»), просто предыдущая эвристика искала там только признаки характера ставки и игнорировала эту метку. **`is_amortized` берётся не из текста, а из факта: есть ли у бумаги реально загруженные строки в `amortizations`** — это надёжнее, чем текстовое совпадение, и правится не в `SecuritiesImporter`, а в `BondizationImporter` (ставится по итогам импорта графика выплат). Раньше оба поля нигде не выставлялись и оставались `FALSE` для всех бумаг независимо от реальности.

**Оферта (`offers`) — тоже нашлась бесплатно, но не в одном эндпоинте.** Дата оферты (14 сентября 2026, переписано) берётся из `bondization`-эндпоинта (`/iss/statistics/engines/stock/markets/bonds/bondization/{ISIN}.json?iss.only=offers`) — того же, что уже используется для купонов/амортизаций — он полнее доска-специфичного `.../boards/{board}/securities/{secid}.json`, который использовался раньше как единственный источник и на практике пропускал реальные оферты (живой пример — `RU000A10B313`, см. `docs/STAGE1_POSTPROCESSING.md`). Вид оферты (put/call) по-прежнему берётся только с доска-специфичного эндпоинта — `PUTOPTIONDATE`/`CALLOPTIONDATE`: ровно одно из двух заполнено у проверенных бумаг с офертой; если оба поля пусты или оба заполнены — честно `unknown`. Свободного источника типизации put/call для всего рынка не существует в принципе — сверено с providers-страницей `bondana.app`, где даты и тип оферты идут от Cbonds (платный источник). Подробности и результаты боевой проверки — `docs/STAGE1_POSTPROCESSING.md`.

**Точно НЕДОСТУПНО бесплатно через ISS API** (не предположение — проверено):
- `is_instruction_based` — в проверенном ответе описания бумаги без активной оферты этих полей не было; требует проверки на бумаге, у которой оферта реально есть.
- `is_subordinated` — подтверждённого поля под этот флаг в ответе не нашлось (в отличие от `is_structured`/`is_amortized`, для которых сигнал нашёлся); в коде остаётся значением по умолчанию из схемы.

**Требует ручного расчёта поверх бесплатных данных (не отдельное поле):**
НКД на произвольную дату (считается по графику купонов), `full_default_date_planned` (плановая дата полного дефолта — считается сами: `period_end_date` + 10 рабочих дней по производственному календарю), доходность к оферте отдельно от доходности к погашению.

Практический вывод: справочник **бумаг, эмитентов (включая ИНН), графика выплат и большинства риск-флагов** (кластер 1 целиком) сидируется бесплатно и автоматически. Единственный настоящий пробел, оставшийся после этой ревизии, — вид оферты и `is_subordinated`, которые касаются небольшой доли выпусков и не блокируют основной импорт.

**Итог боевых прогонов** (`seed_market.php` на bondkeeper.ru, весь рынок 3061 бумага):
- До находки про `/iss/securities.json?q=`: 3002/3061 (98,1%), `inn = NULL` у всех эмитентов, 59 ОФЗ пропущены (SECID≠ISIN).
- После переписывания на новый эндпоинт: **3061/3061 (100%)**, 495 эмитентов, 3004 строки `redemptions`. Из 495 эмитентов `inn` отсутствует ровно у 2 — `RZD Capital P.L.C.` и `SUEK Securities Designated Activity Company` (ирландские SPV для выпуска евробондов, ISIN с префиксом `XS`, вне НРД/Euroclear-Clearstream) — у них физически нет российского ИНН, `NULL` здесь корректен, а не пробел. 57 бумаг (~1,9%) без даты/номинала для `redemptions` — не исследовано отдельно, не блокирует остальное.

## Пост-обработка этапа 1

После первого прогона нашлось ещё несколько незаполненных полей в
`issuers`/`securities` — разбор по каждому (что уже закрыто кодом, что
нужно диагностировать на боевой БД, что требует внешнего сервиса или
вашего решения) — `docs/STAGE1_POSTPROCESSING.md`.
