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
src/Ratings/IssuerMatcher.php        — сопоставление эмитента агентства с issuers.id по ИНН → явной связке SPV (findIssuerIdBySpvLink(), issuer_spv_links, миграция 023) → ISIN (АКРА) → подтверждённому названию (findIssuerIdByApprovedName(), миграция 024); findIssuerIdByName()/findIssuerIdByRootName() только НАХОДЯТ кандидата — решение принимает NameMatchResolver, см. docs/STAGE3_RATINGS.md
src/Ratings/NameMatchReviews.php     — предложения "сопоставить по названию" (issuer_name_match_reviews, миграция 024): предложить/подтвердить/отклонить/уведомить администратора (с кнопками "Подтвердить"/"Отклонить", proposalKeyboard()), тёзки не предлагаются; ссылка в предложении — всегда полный адрес, для любого агентства (RatingsNormalizer::absoluteUrl())
src/Ratings/NameMatchResolver.php    — последний шаг сопоставления во всех 7 импортёрах: подтверждённое название или новое предложение (не пишет в current_ratings/rating_actions)
src/Ratings/SnapshotRows.php         — снимок "сейчас" от агентства: одна строка на эмитента (latestPerIssuer()), запись в current_ratings (apply(), source='snapshot'), сохранение/чтение JSON-файла снимка (saveToFile()/loadFromFile(), П2); keepsOurWatchStatus() — у Эксперт РА в списке нет статуса "под наблюдением", перезапись не стирает наш, если рейтинг тот же и дата агентства не новее
src/Telegram/AdminNotifier.php       — служебное сообщение администратору (config/telegram_bot.php: admin_telegram_id) из CLI-скриптов, опционально с inline-клавиатурой (кнопки предложений); sendLines() — длинный текст (сводка сверки) несколькими сообщениями, по границам строк; ошибка отправки не роняет импорт/сверку
src/Ratings/RatingsNormalizer.php    — общие преобразования для рейтинговых выгрузок: прогноз/дата, absoluteUrl() (ссылка без схемы → полный адрес), normalizeGrade() (кириллические двойники букв → латиница), outlookForRating()/isWithdrawnRating() (отозванный рейтинг — прогноз пустой, для любого источника), ratingFromNraColumn() (отзыв НРА прочерком "—" → 'отозван'), bondNewsSkipStatus() (новости про облигации, не влияющие на рейтинг компании — погашение/неразмещение/субординированные (в т.ч. по признаку "T2" в коде серии)/ожидаемый рейтинг, единое для НКР/Эксперт РА/АКРА), bondNewsSkipStatusForStoredTitle()/bondNewsSkipLabel() (та же классификация для уже сохранённого заголовка — нужно сверке), isNonStandardRating() (шкала .sf и новости про ипотечные ценные бумаги — отдельный продукт, не пишутся вообще), watchStatusFromText()/combineWithWatch()/outlookFromNraColumns() (статус "под наблюдением" — единое правило для всех агентств), см. docs/STAGE3_RATINGS.md
src/Ratings/RatingsHttp.php          — HTTP-загрузчик с ретраями для сайтов рейтинговых агентств
src/Ratings/NkrImporter.php          — current_ratings из Excel-выгрузки НКР (ratings.ru)
src/Ratings/NraImporter.php          — current_ratings из Excel-выгрузки НРА (ra-national.ru)
src/Ratings/ExpertRaClient.php       — постраничный обход raexpert.ru (Эксперт РА) с cookie-сессией
src/Ratings/ExpertRaImporter.php     — current_ratings из raexpert.ru (Эксперт РА); applySnapshot() логирует issuer_id, у которых перезапись оставила наш статус "под наблюдением" (SnapshotRows::keepsOurWatchStatus())
src/Ratings/AcraImporter.php         — current_ratings из JSON-файла АКРА, который готовит пользователь (см. docs/STAGE3_RATINGS.md)
src/Ratings/ManualRatingsImporter.php — current_ratings из ручного xlsx (рейтинги, не найденные через автоматические источники)
src/Ratings/RatingActionsWriter.php  — общий апсерт в rating_actions (ключ — UNIQUE(issuer_id, agency, action_date), полностью распознанные действия, см. docs/STAGE3_RATINGS.md)
src/Ratings/CurrentRatingsReconciler.php — сверка current_ratings с "истиной" от агентства (только сравнивает и печатает расхождения — оба названия, наш источник, заголовок/ссылка новости; applyMissingInOurs() — единственное исключение, пишет новые строки, source='reconcile'; missing_in_snapshot делит расхождение на ожидаемые — с причиной: manual/отозван/issuer_spv_links/подтверждённое название/рейтинг выпуска — и требующие внимания; field_mismatches несёт флаг watch_kept — наш статус "под наблюдением" оставлен перезаписью; значение из новости, которую правила теперь пропускают, — не ожидаемое, "требует внимания" с пометкой), см. docs/STAGE3_RATINGS.md
src/Ratings/BondRedemptionCleanup.php — общая логика для debug_bond_redemption_ratings.php/fix_bond_redemption_ratings.php: план и применение очистки ложных "отозван" от отзыва рейтинга выпуска облигаций из-за погашения; текущий рейтинг пересчитывается только там, где он всё ещё этот ложный "отозван" — перезапись снимком или ручной ввод не затираются, см. docs/STAGE3_RATINGS.md
src/Ratings/ReconcileSummary.php     — текст сводки сверки для Telegram (чистая функция): все строки без обрезки, одна строка на компанию, деление на "требуют внимания"/"ожидаемые"/"есть у агентства, нет у нас", см. docs/STAGE3_RATINGS.md
bin/reconcile_ratings.php            — запуск сверки (--agency=nkr|expert_ra|nra|acra --file=...|all [--apply-missing]); сохраняет снимок каждого агентства в var/snapshots/, печатает команду перезаписи, шлёт полную сводку администратору в Telegram несколькими сообщениями (ReconcileSummary + AdminNotifier::sendLines()), см. docs/STAGE3_RATINGS.md
bin/review_matches.php               — подтверждение/отклонение предложений сопоставления по названию: --list, --approve=ID, --reject=ID, см. docs/STAGE3_RATINGS.md
bin/apply_2026_09_review_decisions.php — РАЗОВЫЙ скрипт решений по разбору сверки 20-26 сентября 2026 (dry-run по умолчанию, --apply): связки issuer_spv_links, отклонённые пары (Озон/Прогресс), чистка их чужих рейтингов, метка source='manual', см. docs/STAGE3_RATINGS.md
bin/link_spv.php                     — управление issuer_spv_links: --spv-inn=... --issuer-inn=...|--issuer-id=... [--spv-name=...] [--note=...], --list, --remove — см. docs/STAGE3_RATINGS.md
src/Ratings/StoredRatingsNormalizer.php — разовое выравнивание уже сохранённых рейтингов: кириллица → латиница, отзыв НРА прочерком → 'отозван', у отозванного и у дефолтного (D/SD, любое агентство) рейтинга прогноз пустой, см. docs/STAGE3_RATINGS.md
bin/normalize_stored_ratings.php     — РАЗОВЫЙ скрипт: применение StoredRatingsNormalizer к current_ratings/rating_actions (dry-run по умолчанию, --apply)
src/Ratings/CurrentRatingsSync.php   — чтение/запись current_ratings для новостных импортёров (источник rating_from/outlook_from; апсерт кэша только если действие не старше уже сохранённого)
src/Ratings/RatingNewsLog.php        — журнал просмотренных пресс-релизов (rating_news_log) — дедуп/ретрай по (agency, source_url), независимо от rating_actions; isKnown() — уже встречался (с любым статусом), нужно листанию ленты АКРА
src/Ratings/NkrTitleParser.php       — чистый (без БД/сети) разбор заголовков пресс-релизов НКР; extractOutlook() — прогноз из заголовка, а если там его нет — из вводного абзаца (extractLeadFromDetailHtml(), страница и так скачивается ради ИНН), см. docs/STAGE3_RATINGS.md
src/Ratings/NkrNewsImporter.php      — rating_actions из истории пресс-релизов НКР, скользящее окно (--days)
src/Ratings/ExpertRaNewsImporter.php — rating_actions из ленты пресс-релизов Эксперт РА, скользящее окно (--days), сопоставление по ИНН со страницы релиза + запасной путь по имени
src/Ratings/AcraEmailParser.php      — ШАБЛОН: разбор текста писем АКРА "Новое рейтинговое действие" (чистая функция, IMAP-часть ещё не реализована — см. docs/STAGE3_RATINGS.md)
src/Ratings/AcraNewsTitleParser.php  — разбор заголовков пресс-релизов АКРА с сайта (чистая функция); extractGrade() — рейтинг берётся только с "(RU)" или сразу после "на уровне"/"до уровня", не первый похожий на грейд токен (раньше предлог "С" давал рейтинг "C", название класса «А1» — "A")
src/Ratings/AcraNewsImporter.php     — rating_actions из ленты пресс-релизов АКРА; окно не короче 14 дней, листает ленту постранично, пока есть ещё не виденные новости (не больше 8 страниц, обычный прогон — один запрос); ипотечные ценные бумаги и шкала структурированного финансирования не пишутся (см. docs/STAGE3_RATINGS.md)
src/Ratings/AcraNewsRecheck.php      — разовая перепроверка уже записанных новостей АКРА исправленным кодом (общая логика для bin/fix_acra_news_ratings.php): неверный рейтинг исправляется, новость без рейтинга/про ипотечные ЦБ удаляется вместе с событием; текущий рейтинг меняется только там, где он всё ещё взят из этой самой новости, см. docs/STAGE3_RATINGS.md
bin/seed_market.php                  — запуск сидирования справочника (шаг 1)
bin/seed_bondization.php             — запуск сидирования графика выплат (шаг 2)
bin/seed_offers.php                  — запуск сидирования оферт (шаг 3)
bin/seed_ratings.php                 — запуск сидирования рейтингов (--agency=nkr|nra|expert_ra|acra|manual|nkr-news|expert_ra-news|acra-news [--days=2] [--full], этап 3, см. docs/STAGE3_RATINGS.md); --agency=nkr|expert_ra|acra --snapshot=var/snapshots/ФАЙЛ.json [--force] — перезапись проверенным на сверке снимком (П2), без повторного скачивания
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
database/022_matched_by_root_name.sql — миграция: rating_actions.matched_by_root_name/current_ratings.matched_by_root_name — флаг сопоставления "по корню" названия (SPV/материнская компания); с миграции 024 всегда пишется 0, старые строки с флагом 1 — на разбор
database/023_issuer_spv_links.sql — миграция: таблица issuer_spv_links — явная ручная связка "неизвестный ИНН → issuer_id" для пар, где имена текстуально не связаны
database/024_issuer_name_match_reviews.sql — миграция: таблица issuer_name_match_reviews — предложения сопоставления по названию на подтверждение администратора (точное имя/по корню), см. docs/STAGE3_RATINGS.md
database/025_current_ratings_source.sql — миграция: current_ratings.source (snapshot/action/manual/reconcile) — сверка понимает, откуда взято значение, см. docs/STAGE3_RATINGS.md
database/026_outlook_indefinite.sql — миграция: коды прогноза 'indefinite'/'under_review_indefinite' (НКР "неопределённый" — отдельное от "развивающийся"); rating_news_log.status — 'skipped_bond_not_placed'/'skipped_subordinated'/'skipped_expected', см. docs/STAGE3_RATINGS.md
src/Telegram/TelegramClientInterface.php — интерфейс Bot API (sendMessage/answerCallbackQuery/editMessageText) для подмены фейком в офлайн-тестах
src/Telegram/TelegramClient.php      — HTTP-клиент Telegram Bot API (long polling, sendMessage/editMessageText/answerCallbackQuery)
src/Telegram/TelegramBotConfig.php   — токен бота + admin_telegram_id из config/telegram_bot.php (не коммитится, см. .example рядом)
src/Telegram/BotFormatting.php       — общие форматтеры (agencyDisplayName/formatDate/formatMoney/escapeHtml) для BotCommandHandler и NotificationDispatcher
src/Telegram/BotCommandHandler.php   — разбор команд/кнопок бота: меню, «Выбор эмитентов» (умный поиск + листалка), «Статус» (разбивка "Весь список" на несколько сообщений при превышении лимита Telegram, splitIntoTelegramChunks()), «Подписка», «О сервисе», чат-релей «Помощь», подтверждение/отклонение предложений сопоставления по названию кнопками (dispatchReviewCallback(), только admin_telegram_id) — см. docs/BOT_UX_SPEC.md, docs/STAGE3_RATINGS.md
src/Telegram/NotificationDispatcher.php — рассылка событий (events) подписчикам из watchlist в Telegram, этап 4 Фаза 3; каждое уведомление начинается с жирного заголовка темы ("🔔 Новости рейтингов:"/"⚠️/✅ Блокировки ФНC:"/"⏰/✅/🔴/🟡 Выплаты:"), parse_mode=HTML, ссылка на пресс-релиз без разворота превью-карточкой; этап 5 (выплаты, см. docs/STAGE5_PAYMENTS.md) — события по конкретной бумаге (security_id), а не только по эмитенту
bin/daemon_telegram_bot.php          — цикл бота: long-polling команд + рассылка раз в 60 с, один процесс
config/telegram_bot.example.php      — шаблон конфига токена бота (скопировать в telegram_bot.php, не коммитить)
tests/test_bot_ux_screens.php        — офлайн-проверка экранов/разделов бота на SQLite (143 проверки: меню/статус/подписка + разбивка "Весь список" на сообщения + кнопки подтверждения предложений)
tests/test_notification_dispatcher.php — офлайн-проверка рассылки на SQLite (56 проверок, покрыта целиком — без MySQL-диалекта; включая экранирование внешнего текста под parse_mode=HTML и тексты уведомлений о выплатах, этап 5)
tests/test_no_duplicate_named_params.php — статическая проверка ->prepare(): нет повторов :имени плейсхолдера в одном запросе (PDO::ATTR_EMULATE_PREPARES=false — MySQL это не прощает, в отличие от SQLite)
tests/test_issuer_name_shortener.php — офлайн-проверка IssuerNameShortener (24 проверки, чистая текстовая логика, БД не нужна)
bin/backfill_issuer_short_names.php  — разовая пересборка issuers.short_name из full_name для строк, накопленных до появления IssuerNameShortener (идемпотентно, безопасно перезапускать)
tests/test_offers_importer.php       — офлайн-проверка OffersImporter (20 проверок: выбор даты/типа оферты из bondization/offers, put/call — чистая логика, БД не нужна; даты в тесте считаются от сегодняшнего дня, П9)
tests/test_ratings_normalizer.php    — офлайн-проверка RatingsNormalizer (39 проверок: isBondIssueRedemptionWithdrawal()/isBondIssueRatingTitle() — прилагательные между "рейтинг"/"выпуск" и "облигаций" узнаются по окончанию, а не по списку слов, bondNewsSkipStatus() — общее правило пропуска новостей про облигации для НКР/Эксперт РА/АКРА, чистая текстовая логика, БД не нужна)
tests/test_current_ratings_reconciler.php — офлайн-проверка CurrentRatingsReconciler (33 проверки: сравнение с обоими названиями + applyMissingInOurs()/кейс 3 + explainMissing() — ожидаемые расхождения с причиной у missing_in_snapshot; поле watch_kept у field_mismatches — наш статус "под наблюдением" остаётся при перезаписи Эксперт РА; значение из новости, которую теперь пропускают правила, — "требует внимания", не "ожидаемо" — MySQL-диалекта нет)
tests/test_nkr_importer_url.php      — офлайн-проверка RatingsNormalizer::absoluteUrl()/NkrImporter::describeRow() (15 проверок: ссылка на пресс-релиз НКР без схемы https:// теперь распознаётся, не теряется в тексте заголовка; общий метод, используется также в NameMatchReviews)
tests/test_nkr_outlook_parsing.php   — офлайн-проверка NkrTitleParser::extractOutlook()/extractLeadFromDetailHtml() (42 проверки на дословных фразах НКР: прогноз из вводного абзаца пресс-релиза, если в заголовке его нет)
tests/test_watch_status.php          — офлайн-проверка общего правила статуса "под наблюдением" (33 проверки: RatingsNormalizer::watchStatusFromText()/combineWithWatch()/outlookFromNraColumns() — одно правило вместо пяти расходившихся между собой)
tests/test_stored_ratings_normalizer.php — офлайн-проверка StoredRatingsNormalizer (28 проверок: кириллица в рейтингах, отзыв НРА прочерком → 'отозван', прогноз пустой у отозванного и у дефолтного грейда D/SD в уже сохранённых данных)
tests/test_reconcile_summary.php     — офлайн-проверка ReconcileSummary::lines() (26 проверок: текст сводки сверки для Telegram, все строки без обрезки, деление на "требуют внимания"/"ожидаемые"; значение из новости, которую правила теперь пропускают, — в "требуют внимания"; статус "под наблюдением", оставленный при перезаписи Эксперт РА, — в "ожидаемые")
tests/test_name_match_reviews.php    — офлайн-проверка NameMatchReviews (44 проверки: предложить/подтвердить/отклонить, тёзки не предлагаются, ссылка в предложении — полный адрес для любого агентства)
tests/test_snapshot_rows.php         — офлайн-проверка SnapshotRows (30 проверок: latestPerIssuer()/apply()/saveToFile()/loadFromFile(), keepsOurWatchStatus() — статус "под наблюдением" Эксперт РА не стирается перезаписью снимком, если рейтинг тот же и дата агентства не новее)
tests/test_bond_redemption_cleanup.php — офлайн-проверка BondRedemptionCleanup (17 проверок: текущий рейтинг пересчитывается только там, где всё ещё стоит ложный "отозван" от отзыва облигаций из-за погашения — значения из перезаписи снимком или ручного ввода не затираются)
bin/debug_bond_redemption_ratings.php — диагностика (без записи в БД): план BondRedemptionCleanup — какие ложные "рейтинг отозван" от отзыва выпуска из-за погашения удалит fix_bond_redemption_ratings.php и что станет с текущим рейтингом (только там, где он всё ещё этот ложный "отозван")
bin/fix_bond_redemption_ratings.php  — разовое исправление (ПИШЕТ в БД): удаляет ложные rating_actions/events; current_ratings пересчитывает, только если он всё ещё тот самый ложный "отозван" — значения из перезаписи снимком или ручного ввода не трогает; запускать после debug_bond_redemption_ratings.php
bin/fix_acra_news_ratings.php        — РАЗОВЫЙ скрипт (dry-run по умолчанию, --apply): перепроверяет уже записанные новости АКРА — исправляет неверный рейтинг, удаляет новости без рейтинга в заголовке или про ипотечные ЦБ; current_ratings трогает только там, где значение всё ещё из этой новости, см. docs/STAGE3_RATINGS.md
tests/test_issuer_root_matching.php  — офлайн-проверка IssuerMatcher::rootCompanyName()/findIssuerIdByRootName() (25 проверок, включая реальный случай Аэрофьюэлз/Аэрофьюэлз Групп; сами эти методы только находят кандидата, не пишут — решение за NameMatchResolver)
tests/test_issuer_spv_link.php       — офлайн-проверка IssuerMatcher::findIssuerIdBySpvLink() + приоритет связки над названием в resolveIssuerId() (8 проверок)
tests/test_default_grade_clears_outlook.php — офлайн-проверка RatingsNormalizer::isDefaultGrade() + CurrentRatingsSync::resolveOutlook() (21 проверка, реальный случай ООО «ЛКХ», рейтинг "D"; отзыв рейтинга тоже обнуляет прогноз — АО «АВТОБАН-Финанс»)
tests/test_root_priority_conflict.php — офлайн-проверка приоритета ИНН/связки над сопоставлением по названию ВНУТРИ одного прогона NkrImporter/ExpertRaImporter/AcraImporter (23 проверки)
tests/test_acra_news.php             — офлайн-проверка новостей АКРА (46 проверок): AcraNewsTitleParser::extractGrade() — рейтинг только с "(RU)"/после "на уровне"; AcraNewsImporter — листание ленты, ипотечные ЦБ не пишутся; AcraNewsRecheck — разовое исправление уже записанных новостей
database/027_payment_events.sql      — миграция: типы событий R1 (напоминание о выплате) и B2b (от НРД нет сообщений — только администратору), этап 5, см. docs/STAGE5_PAYMENTS.md
config/working_calendar.php          — производственный календарь РФ (2026, 2027): праздничные будни и рабочие субботы; пополняется раз в год
config/payments.example.php          — образец config/payments.php: включение напоминаний и проверок выплат (по умолчанию всё выключено)
src/Payments/WorkingCalendar.php     — рабочие дни: день исполнения выплаты (перенос с выходного), +N рабочих дней (срок полного дефолта), рабочих дней между датами
src/Payments/PaymentMessage.php      — сообщение о выплате в нашем виде (ISIN, вид, дата по графику, этап получено/передано, исполнение полное/частичное/нет, сумма); в него будет переводиться сообщение GetNews
src/Payments/PaymentProcessor.php    — обработка сообщения о выплате: архив raw_messages, поиск выплаты по ISIN и дате, статус/факт в графике, история выплаты (event_stories), события A2–A7, B1, B2, B4, B5
src/Payments/PaymentWatch.php        — события, которые считаем сами по графику: R1 накануне выплаты, B2a «денег пока нет» (вечер дня выплаты и утро следующего рабочего дня), B2b «от НРД нет ничего»
src/Payments/PaymentsConfig.php      — чтение config/payments.php (нет файла — всё выключено)
bin/payment_reminders.php            — напоминания о выплатах на завтра (по расписанию, 10:00 мск; --dry-run — показать список)
bin/payment_checks.php               — проверки «сообщения о получении денег нет» (--at=evening в 19:00 мск, --at=morning в 10:00 мск; --dry-run)
tests/test_working_calendar.php      — офлайн-проверка WorkingCalendar (25 проверок: 247 рабочих дней в 2026 и 2027, переносы, сроки дефолта на реальных кейсах)
tests/test_payments.php              — офлайн-проверка этапа 5 на SQLite (66 проверок: PaymentProcessor на кейсах СибАвтоТранс/КЛВЗ/ВЗВТ/ЕвроТранс/Нэппи Клаб, PaymentWatch, тексты и получатели рассылки)
src/Payments/GetNewsConfig.php       — login/password для API НРД (nsddata.ru) из config/nsd_api.php, не коммитится, см. .example рядом
src/Payments/GetNewsClientInterface.php — интерфейс клиента GetNews (для подмены фейком в будущих офлайн-тестах разбора сообщений)
src/Payments/GetNewsClient.php       — HTTP-клиент GetNews: POST /api/auth/login → Bearer-токен, обновление по /api/auth/refresh на 401, GET /api/get/news (filter/limit/skip); адрес и формат подтверждены вживую 05.10.2026, см. docs/STAGE5_PAYMENTS.md
config/nsd_api.example.php           — шаблон config/nsd_api.php (login/password от НРД, не коммитить)
bin/debug_getnews.php                — разведка (без записи в БД): проверка токена + вывод реальных ca_type/data.state.code за выбранное окно — нужно для разбора сообщений в PaymentProcessor (см. STAGE5_PAYMENTS.md, раздел 9)
src/Payments/GetNewsMessageMapper.php — перевод сырого сообщения GetNews в PaymentMessage: state.code (A/T/N/C), получено/передано по тексту заголовка, ca_type → купон/амортизация/погашение; подтверждён на реальных сообщениях тестового доступа НРД (05.10.2026), см. STAGE5_PAYMENTS.md
tests/test_getnews_message_mapper.php — офлайн-проверка GetNewsMessageMapper (25 проверок) на урезанных копиях РЕАЛЬНЫХ сообщений (суммы/даты/content_id_out настоящие)
bin/poll_getnews.php                 — опрос GetNews: получить → GetNewsMessageMapper → PaymentProcessor::process(); --dry-run всегда доступен и ничего не пишет, реальная запись — только при config/payments.php: getnews_polling=true (настоящие уведомления клиентам по A2/A4/A6/B1/B2/B4/B5), см. STAGE5_PAYMENTS.md
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
mysql -u root -p bondkeeper < database/024_issuer_name_match_reviews.sql
mysql -u root -p bondkeeper < database/025_current_ratings_source.sql
php bin/apply_2026_09_review_decisions.php --apply   # разовые решения по разбору сверки 20-26 сентября (см. docs/STAGE3_RATINGS.md)
mysql -u root -p bondkeeper < database/026_outlook_indefinite.sql
php bin/normalize_stored_ratings.php --apply   # разовое выравнивание сохранённых рейтингов: кириллица, отзыв НРА прочерком, прогноз у отозванных
mysql -u root -p bondkeeper < database/027_payment_events.sql   # типы событий выплат R1/B2b, этап 5, см. docs/STAGE5_PAYMENTS.md

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
