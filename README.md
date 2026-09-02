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
src/Iss/BondizationImporter.php      — coupons/amortizations
src/Fns/NalogBiClient.php            — HTTP-клиент service.nalog.ru/bi.do (блокировки счетов)
src/Fns/FnsBlocksImporter.php        — fns_blocks, issuers.is_fns_blocked
src/Iss/OffersImporter.php           — offers (дата, has_buyback_date, offer_type put/call/unknown)
src/Ratings/XlsxReader.php           — минимальный читатель .xlsx (ZIP+XML) без зависимостей
src/Ratings/IssuerMatcher.php        — сопоставление эмитента агентства с issuers.id по ИНН (и по названию, когда ИНН взять неоткуда)
src/Ratings/RatingsNormalizer.php    — общие преобразования (прогноз, дата) для рейтинговых выгрузок
src/Ratings/RatingsHttp.php          — HTTP-загрузчик с ретраями для сайтов рейтинговых агентств
src/Ratings/NkrImporter.php          — current_ratings из Excel-выгрузки НКР (ratings.ru)
src/Ratings/NraImporter.php          — current_ratings из Excel-выгрузки НРА (ra-national.ru)
src/Ratings/ExpertRaClient.php       — постраничный обход raexpert.ru (Эксперт РА) с cookie-сессией
src/Ratings/ExpertRaImporter.php     — current_ratings из raexpert.ru (Эксперт РА)
src/Ratings/AcraImporter.php         — current_ratings из JSON-файла АКРА, который готовит пользователь (см. docs/STAGE3_RATINGS.md)
src/Ratings/ManualRatingsImporter.php — current_ratings из ручного xlsx (рейтинги, не найденные через автоматические источники)
src/Ratings/RatingActionsWriter.php  — общий апсерт в rating_actions
src/Ratings/NkrNewsImporter.php      — rating_actions из истории пресс-релизов НКР
src/Ratings/ExpertRaNewsImporter.php — rating_actions из ленты пресс-релизов Эксперт РА
bin/seed_market.php                  — запуск сидирования справочника (шаг 1)
bin/seed_bondization.php             — запуск сидирования графика выплат (шаг 2)
bin/seed_offers.php                  — запуск сидирования оферт (шаг 3)
bin/seed_ratings.php                 — запуск сидирования рейтингов (--agency=nkr|nra|expert_ra|acra|manual|nkr-news|expert_ra-news, этап 3, см. docs/STAGE3_RATINGS.md)
bin/check_fns_blocks.php             — точечная/по расписанию проверка блокировок счетов (см. docs/STAGE1_POSTPROCESSING.md)
config/fns_watchlist.txt             — список ИНН для ежедневного cron-прогона check_fns_blocks.php
bin/debug_iss_security.php           — разовая диагностика сырого ответа ISS API по ISIN
bin/debug_rating_page.php            — разовая диагностика структуры страницы рейтингового агентства (этап 3, см. docs/STAGE3_RATINGS.md)
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

**Оферта (`offers`) — тоже нашлась бесплатно, но в другом эндпоинте.** Не в уже используемом `description` (`/iss/securities/{ISIN}.json`), а в отдельном, доска-специфичном `/iss/engines/stock/markets/bonds/boards/{board}/securities/{secid}.json`, до этой задачи в проекте нигде не запрашивавшемся. Оттуда же — `PUTOPTIONDATE`/`CALLOPTIONDATE`: ровно одно из двух заполнено у проверенных бумаг с офертой, что даёт `offer_type` (put/call) без похода на RusBonds; если оба поля пусты или оба заполнены — честно `unknown`. Подробности и результаты боевой проверки — `docs/STAGE1_POSTPROCESSING.md`.

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
