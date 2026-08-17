# Этап 1 — внедрение на сервер и тестирование

Инструкция для домена `bondkeeper.ru` в ISPmanager: сайт и файловая система уже созданы (владелец `www-root`, корень `/var/www/www-root/data/www/bondkeeper.ru`, PHP 8.3.6 module Apache MPM-ITK), БД ещё нет. Этап 1 — это фоновые cron-скрипты (`bin/seed_market.php`, `bin/seed_bondization.php`), у него **нет публичной веб-части** — это важно для шага 1 ниже.

Везде, где это уместно, команды даны от имени `www-root` — при Apache MPM-ITK PHP-процессы сайта и так выполняются под этим пользователем, и держать файлы/cron под тем же UID избавляет от рассинхронизации прав.

---

## Шаг 0. Предварительная проверка окружения по SSH

```bash
ssh root@<ip-сервера>
su - www-root   # дальше все команды — от www-root, если не указано иное

git --version
php -v                                   # должен быть 8.3.x
php -m | grep -E 'pdo_mysql|curl'        # оба должны быть в списке
```

Если `php -v` показывает не 8.3 (в ISPmanager нередко `/usr/bin/php` указывает на другую версию, чем модуль Apache) — найдите нужный бинарник:

```bash
ls /opt/php83/bin/php 2>/dev/null || ls /usr/bin/php8.3 2>/dev/null || update-alternatives --list php
```

Дальше в инструкции используется просто `php` — замените на найденный путь (например `/opt/php83/bin/php`), если он отличается, и используйте тот же путь в cron на шаге 7.

Если `git` не установлен — установить (`apt install git`, от root).

---

## Шаг 1. Куда класть код: НЕ в корень сайта

`/var/www/www-root/data/www/bondkeeper.ru` — это **DocumentRoot**, то, что Apache отдаёт по HTTP напрямую. У этапа 1 нет `index.php` и вообще нет ничего, что должно быть публично доступно — зато есть `.env` с паролем от БД и `database/001_schema.sql` со всей структурой. Класть это в DocumentRoot и полагаться на `.htaccess` — лишний риск (опечатка в `.htaccess`, отключённый `AllowOverride` — и `.env` отдаётся браузером как обычный текстовый файл).

Правильно — положить репозиторий рядом, вне DocumentRoot:

```bash
cd /var/www/www-root/data
git clone <URL-репозитория> bondkeeper-app
cd bondkeeper-app
git checkout claude/project-phases-planning-tqstsk   # или основная ветка, если уже смержено
```

`data/www/bondkeeper.ru/` при этом остаётся пустым — это ожидаемо для этапа 1. Как только появится сайт/бот (следующие этапы), туда будет смотреть только тонкий публичный `public/`, а не весь репозиторий.

Если по какой-то причине разместить нужно строго внутри DocumentRoot (например, SSH есть только к `www/`) — тогда обязательно сразу после клонирования:

```bash
cd /var/www/www-root/data/www/bondkeeper.ru
cat > .htaccess <<'EOF'
<FilesMatch "^\.env|\.sql$">
    Require all denied
</FilesMatch>
<IfModule !mod_authz_core.c>
    <FilesMatch "^\.env|\.sql$">
        Deny from all
    </FilesMatch>
</IfModule>
Options -Indexes
EOF
```
и проверить `curl -I https://bondkeeper.ru/.env` — должен быть 403, а не 200, прежде чем класть туда реальные пароли.

---

## Шаг 2. Создать базу данных в ISPmanager

В панели: **Базы данных → Создать БД**.

- Имя БД — ISPmanager сам добавит префикс владельца, получится примерно `www-root_bondkeeper`.
- Создать отдельного пользователя БД (не root MySQL) с полным доступом только к этой базе — тоже через тот же мастер.
- Кодировка — `utf8mb4` (в схеме используется `utf8mb4_unicode_ci` — если панель предлагает выбор charset/collation при создании, выставить именно так; если нет — не критично, `CREATE DATABASE` в `001_schema.sql` всё равно явно задаёт `utf8mb4`/`utf8mb4_unicode_ci` при накатывании).

Записать: имя БД, пользователя, пароль, хост (обычно `localhost`, порт `3306`).

---

## Шаг 3. Настроить `.env`

```bash
cd /var/www/www-root/data/bondkeeper-app
cp .env.example .env
nano .env
```

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=www-root_bondkeeper
DB_USER=<пользователь из шага 2>
DB_PASSWORD=<пароль из шага 2>
```

Проверить права на файл — читать должен только владелец:

```bash
chmod 600 .env
```

---

## Шаг 4. Накатить схему

Вариант А — по SSH, если есть доступ к `mysql`-клиенту:

```bash
mysql -u <пользователь> -p www-root_bondkeeper < database/001_schema.sql
mysql -u <пользователь> -p www-root_bondkeeper < database/002_issuer_moex_emitter_id.sql
mysql -u <пользователь> -p www-root_bondkeeper < database/003_widen_value_per_bond.sql
mysql -u <пользователь> -p www-root_bondkeeper < database/004_coupon_type_unknown.sql
mysql -u <пользователь> -p www-root_bondkeeper < database/005_is_mortgage_backed.sql
mysql -u <пользователь> -p www-root_bondkeeper < database/006_coupons_amortizations_upsert.sql
mysql -u <пользователь> -p www-root_bondkeeper < database/007_initial_nominal_value.sql
mysql -u <пользователь> -p www-root_bondkeeper < database/008_fns_blocks_upsert_keys.sql
```

Вариант Б — через phpMyAdmin (он уже установлен по конфигурации ПО): зайти в базу `www-root_bondkeeper` → вкладка **Импорт** → выбрать файл `database/001_schema.sql` → Выполнить → повторить по очереди для `002_issuer_moex_emitter_id.sql`, `003_widen_value_per_bond.sql`, `004_coupon_type_unknown.sql`, `005_is_mortgage_backed.sql`, `006_coupons_amortizations_upsert.sql`, `007_initial_nominal_value.sql`, `008_fns_blocks_upsert_keys.sql`.

После миграции `008` — точечная проверка блокировок счетов (не часть общего `seed_market.php`/`seed_bondization.php`, отдельный инструмент, намеренно не по всему рынку сразу):
```bash
php bin/check_fns_blocks.php --inns=1101148661,3702151662,7730176955,7805485840,7826108963,9727020246
```
Подробности — `docs/STAGE1_POSTPROCESSING.md`, раздел про `fns_blocks`.

После миграции `006` обязательно прогнать `php bin/seed_bondization.php --force` один раз (не обычный режим) — это разово пересеет график для ВСЕХ активных бумаг под новой upsert-логикой, включая те, что раньше "замёрзли" с первой строки. После этого разового прогона обычный (без `--force`) режим по расписанию продолжит донаполнять только реально незаполненные бумаги — подробности в `docs/STAGE1_POSTPROCESSING.md`.

После миграции `007` обязательно прогнать `php bin/seed_market.php` (обычный режим — он один backfill'ит `initial_nominal_value` у уже существующих бумаг через `COALESCE`) и следом `php bin/seed_bondization.php --force` — пересчитает `redemptions.value_per_bond` по исправленной формуле для всех амортизируемых бумаг. Порядок важен: `seed_market.php` обязательно раньше, иначе `initial_nominal_value` ещё не заполнен, а `adjustScheduledRedemption` подстрахуется через `COALESCE(initial_nominal_value, nominal_value)`, дав менее точный (но не сломанный) результат — подробности в `docs/STAGE1_POSTPROCESSING.md`.

`002_...sql` обязателен — без него `issuers.inn` останется `NOT NULL`, а ISS API его не отдаёт (см. README, раздел «Что бесплатно»), и `seed_market.php` не запишет ни одного эмитента. `003_...sql` нужен для бумаг с крупным номиналом (от 100 млн ₽ за бумагу) — без него часть институциональных выпусков будет падать с `SQLSTATE 22003 Out of range`. `004_...sql` добавляет честное значение `unknown` для `coupon_type` вместо угаданного `fixed` — без него `seed_market.php` отработает, но часть бумаг (ипотечные/валютные/бессрочные, где `BOND_TYPE` не про ставку) останется неверно помечена как `fixed`.

Проверка сразу после накатки:

```bash
mysql -u <пользователь> -p www-root_bondkeeper -e "
SHOW TABLES;
SELECT COUNT(*) AS event_types FROM event_types;   -- ожидается 24
SELECT * FROM tariffs;                              -- ожидается 4 строки: free/basic/pro/expert
"
```

20 таблиц, 24 строки в `event_types`, 4 строки в `tariffs` — схема накатилась так же, как в проверке на этапе разработки (см. `README.md`, раздел «Как это было проверено»).

---

## Шаг 5. Первый ручной прогон — и это главная часть тестирования

Схема и маппинг полей уже проверены на реальном ответе ISS API (bondkeeper.ru, август 2026 — см. README, раздел «Что бесплатно»), но точечно, на паре ISIN через `bin/debug_iss_security.php`. Первый полный прогон `seed_market.php` на всём рынке (~3000 бумаг) на сервере — это проверка, что маппинг держится не только на паре примеров.

```bash
php bin/seed_market.php 2>&1 | tee /tmp/seed_market_first_run.log
```

Смотреть в вывод построчно:

- `Обнаружено бумаг для импорта: N` — если N = 0, значит discovery-эндпоинт (`/engines/stock/markets/bonds/boards/{TQCB,TQIR,TQOB}/securities.json`) вернул не то, что ожидалось, — открыть его напрямую (`curl https://iss.moex.com/iss/engines/stock/markets/bonds/boards/TQCB/securities.json`) и свериться со списком колонок в `SecuritiesImporter::discoverIsinList()`.
- `Пропущено (не нашлась в поиске ISS API): N` — импортёр ищет каждую бумагу через `/iss/securities.json?q={ISIN}`; должно быть близко к нулю. Если тут много бумаг — открыть `bin/debug_iss_security.php` на нескольких из них.
- Строки `Поля с пропусками...` в конце отчёта — ожидаемая часть работы, не баг: `is_subordinated`/`is_structured` (поле в ISS API не найдено) там будут всегда. А вот `issuers.inn` и `is_qualified_investors_only` расти не должны (или должны быть единичными) — оба теперь читаются из реальных полей ответа (`emitent_inn` из поиска и `ISQUALIFIEDINVESTORS` из карточки бумаги соответственно); если растут — стоит проверить через `debug_iss_security.php`, что изменилось в ответе API.
- Если на сервере уже есть эмитенты, созданные более ранней версией импортёра (без ИНН — только `moex_emitter_id`), повторный прогон дозаполнит им `inn`/названия автоматически: `resolveOrCreateIssuer()` перезаписывает эти поля при каждом апсерте, а не только при первом создании.

Затем:

```bash
php bin/seed_bondization.php 2>&1 | tee /tmp/seed_bondization_first_run.log
```

Смотреть `Купонов импортировано` / `Амортизаций импортировано` — должны быть кратны числу успешно импортированных бумаг (у бумаги без амортизации — только купоны).

---

## Шаг 6. Проверочный чек-лист после первого прогона

```sql
-- Общие количества
SELECT COUNT(*) FROM issuers;
SELECT COUNT(*) FROM securities;
SELECT COUNT(*) FROM coupons;
SELECT COUNT(*) FROM amortizations;
SELECT COUNT(*) FROM redemptions WHERE redemption_type = 'scheduled_maturity';

-- Точечная проверка одной знакомой бумаги (замените на реальный ISIN из вашего рынка)
SELECT * FROM securities WHERE isin = 'RU000A107KV4';
SELECT * FROM coupons WHERE security_id = (SELECT id FROM securities WHERE isin = 'RU000A107KV4') ORDER BY period_end_date;

-- Остаток номинала в redemptions должен быть МЕНЬШЕ nominal_value, если у бумаги есть амортизация
SELECT s.isin, s.nominal_value, r.value_per_bond
FROM redemptions r JOIN securities s ON s.id = r.security_id
WHERE r.redemption_type = 'scheduled_maturity' AND r.value_per_bond < s.nominal_value
LIMIT 20;

-- Эмитенты без ИНН — теперь должно быть близко к нулю (ИНН резолвится через
-- /iss/securities.json?q=, см. README). Если тут много строк — см. шаг 5.
SELECT COUNT(*) AS без_инн, (SELECT COUNT(*) FROM issuers) AS всего FROM issuers WHERE inn IS NULL;
```

Проверка идемпотентности — запустить оба скрипта повторно и убедиться, что:
- `seed_market.php` не падает и не плодит дублей (`securities.isin` уникален, апсерт обновляет, а не вставляет заново);
- `seed_bondization.php` во второй раз обрабатывает 0 бумаг (он выбирает только бумаги без единого купона — это ожидаемо, а не баг; полноценное обновление уже загруженного графика — задача события A1/A4 в событийном движке следующего этапа, не этапа 1).

```bash
php bin/seed_market.php
php bin/seed_bondization.php   # "Бумаг обработано: 0" — ожидаемо
```

---

## Шаг 7. Cron

В ISPmanager — раздел планировщика заданий (может называться «Cron» или быть в дополнительных настройках сайта/пользователя); либо напрямую:

```bash
crontab -u www-root -e
```

```cron
0 3  * * * /usr/bin/php /var/www/www-root/data/bondkeeper-app/bin/seed_market.php >> /var/www/www-root/data/bondkeeper-app/var/log/seed_market.log 2>&1
30 3 * * * /usr/bin/php /var/www/www-root/data/bondkeeper-app/bin/seed_bondization.php >> /var/www/www-root/data/bondkeeper-app/var/log/seed_bondization.log 2>&1
```

(Раз в сутки — справочник и так меняется редко, см. `documents/2026.08.08_Seeding_And_Polling_Strategy_QA.docx`.)

Создать каталог логов и проверить путь до `php`, если он не `/usr/bin/php` (см. шаг 0):

```bash
mkdir -p /var/www/www-root/data/bondkeeper-app/var/log
```

Через сутки — проверить, что задания реально отработали (`SELECT MAX(last_synced_at) FROM securities;` должно обновиться) и что в логах нет повторяющихся `ERROR`/`WARN` сверх ожидаемых из шага 5.

---

## Быстрый список проблем и решений

| Симптом | Причина | Что делать |
|---|---|---|
| `Не удалось получить ответ от ISS API` | нет исходящего доступа в интернет с сервера, либо блокировка firewall/DNS | `curl -I https://iss.moex.com/iss/index.json` с сервера; если не отвечает — вопрос к хостеру/файрволу, не к коду |
| `SQLSTATE[HY000] [1045] Access denied` | неверные данные в `.env` | перепроверить пользователя/пароль/имя БД из шага 2 |
| `Обнаружено бумаг для импорта: 0` | discovery-эндпоинт/имена колонок разошлись с реальным ответом API | см. шаг 5, свериться напрямую через `curl` |
| Почти все бумаги пропущены с `в description нет даже EMITTER_ID` | это уже не про ИНН (тот вопрос закрыт в `002_issuer_moex_emitter_id.sql`) — если пропадает даже `EMITTER_ID`, значит поменялась сама форма ответа `description` | прогнать `bin/debug_iss_security.php <ISIN>` на паре пропущенных бумаг, сверить с кодом в `resolveOrCreateIssuer()` |
| `SQLSTATE[42S22] Unknown column 'moex_emitter_id'` | забыли применить `database/002_issuer_moex_emitter_id.sql` | накатить миграцию 002 (шаг 4) |
| `.env` открывается в браузере по `https://bondkeeper.ru/.env` | код лежит внутри DocumentRoot без `.htaccess`-защиты | перенести код за пределы DocumentRoot (шаг 1) или добавить `.htaccess` |
