# A04 — backend CI и конфигурации тестовых БД

Дата: 26 октября 2026 года. **A04 выполнен.** Проверены настройки и команды; запуск GitHub Actions, миграций и полных тестов не выполнялся. Реальные прогоны остаются A18/A19 из [плана](refactoring-plan-2026-10-26.md).

## Найденные проблемы

1. В SQLite XML переменные `<env>` уступали уже установленным переменным процесса. При внешних APP_ENV/DB_CONNECTION/DB_DATABASE/DB_URL Laravel мог выбрать `production` и другую базу вместо SQLite в памяти.
2. PostgreSQL XML уже использовал `force="true"` для трёх DB-переменных, но этого оказалось недостаточно. В установленных PHPUnit 12.5.33 и Laravel 13.25.0 обработчик `<env>` меняет process env и `$_ENV`, а Laravel/phpdotenv сначала читает `$_SERVER`. Внешние переменные оставались там и побеждали даже принудительные env-значения. Это воспроизведено загрузкой конфигурации без подключения к БД.
3. Отдельный Artisan migration step не читает PHPUnit XML. Его env не задавал APP_ENV=testing, пустой DB_URL и тестовые cache/session/queue/mail. Шаблон `.env.example` содержит APP_ENV=local, имя обычной базы `family_education`, host `postgres` и сервисные драйверы; workflow должен явно задавать свои значения.

Для воспроизведения использовались вымышленные `not_a_test_database`, `unused.invalid` и фиктивные credentials. Ни реальная база, ни реальные credentials не читались диагностическим скриптом: он загружал копию `.env.example` в `/tmp` и только собирал конфигурацию.

## Изменения

- [phpunit.xml](../phpunit.xml) и [phpunit.pgsql.xml](../phpunit.pgsql.xml): тестовые значения перенесены в `<server ... force="true"/>`, которые имеют приоритет в Laravel environment repository. Зафиксированы APP_ENV/testing, DB_CONNECTION, DB_DATABASE, пустой DB_URL и тестовые сервисные настройки.
- [.github/workflows/ci.yml](../.github/workflows/ci.yml): общие настройки testing и PostgreSQL service вынесены в job env. Это распространяет их на Composer/Artisan/setup/migration, а не только на PHPUnit.
- В SQLite step сохранены явные overrides `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` и пустой DB_URL.
- Перед генерацией ключа добавлена очистка config cache. Перед обоими тестовыми прогонами и PostgreSQL migration очистка также предусмотрена: SQLite использует `composer test`, чей первый script — `artisan config:clear`; PostgreSQL делает это явно.
- PostgreSQL migration command теперь явно задаёт `--database=pgsql --force --no-interaction`. Повторяющиеся PostgreSQL env-блоки шагов заменены единым job env.

Бизнес-код, миграции, зависимости, frontend и infrastructure в этой задаче не изменялись. Существующее разделение backend/frontend CI и удаление legacy deployment сохранены.

## Выбор базы и порядок CI

| Этап | Окружение | Соединение / база | Примечание |
| --- | --- | --- | --- |
| Composer install и Prepare environment | testing | pgsql / family_education_test | Job env действует до package discovery; создаётся `.env` из example, cache очищается, генерируется ключ. |
| Style/static analysis | testing | pgsql / family_education_test | Выполняются `composer lint` и `composer analyse`. |
| SQLite suite | testing | sqlite / :memory: | `composer test` очищает cache, затем PHPUnit использует `phpunit.xml`. |
| PostgreSQL migrations | testing | pgsql / family_education_test | Cache очищается; явный выбор connection; service database создана PostgreSQL container. |
| PostgreSQL suite | testing | pgsql / family_education_test | Cache очищается; `php artisan test --configuration=phpunit.pgsql.xml`. |

PostgreSQL service и job env согласованы: host `127.0.0.1`, port `5432`, database `family_education_test`, user `family_education`, одинаковый тестовый password. Service healthcheck ожидает готовность PostgreSQL. База `family_education` из `.env.example` в проверенных CI-сценариях не выбирается.

XML фиксирует driver/name/URL. Host, port и credentials PostgreSQL намеренно остаются конфигурируемыми через окружение; в CI они заданы явно. При локальном A19 надо указать адрес именно тестового PostgreSQL instance. Одно имя базы не является защитой от намеренного подключения к неподходящему серверу.

Многие feature-тесты используют `RefreshDatabase`: в A19 требуется отдельная тестовая база, поскольку этот механизм выполняет миграции и сброс схемы. В A04 его выполнение не запускалось. Redis service в workflow сохранён; suite с array cache/session и sync queue сам по себе не подтверждает работу настоящего Redis/queue worker.

## Проверки

Конфигурации загружались установленным PHPUnit `XmlConfiguration\Loader`/`PhpHandler`, затем Laravel `LoadEnvironmentVariables`/`LoadConfiguration`. DB_URL разбирался настоящим `ConfigurationUrlParser`, без открытия соединения. Данные окружения CI получены из самого workflow через PyYAML BaseLoader, сохраняя YAML-ключ `on` и строковые значения.

| Сценарий | Результат |
| --- | --- |
| SQLite XML + только `.env.example` | PASS: testing, sqlite, :memory:, пустой URL. |
| SQLite XML + конфликтующие внешние env | PASS: testing, sqlite, :memory:, пустой URL. |
| PostgreSQL XML + только `.env.example` | PASS: testing, pgsql, family_education_test, пустой URL. |
| PostgreSQL XML + конфликтующие внешние env | PASS: testing, pgsql, family_education_test, пустой URL. |
| Workflow Prepare environment + конфликтующие внешние env | PASS: тестовый PostgreSQL на 127.0.0.1:5432. |
| Workflow SQLite step + конфликтующие внешние env | PASS: SQLite :memory:. |
| Workflow PostgreSQL migration step + конфликтующие внешние env | PASS: тестовый PostgreSQL на 127.0.0.1:5432; только загрузка настроек команды. |
| Workflow PostgreSQL test step + конфликтующие внешние env | PASS: тестовый PostgreSQL на 127.0.0.1:5432. |

Во всех восьми случаях дополнительно проверены cache=array, session=array, queue=sync и mail=array.

- Обе XML прошли `DOMDocument::schemaValidate` по `vendor/phpunit/phpunit/phpunit.xsd`.
- `php vendor/bin/phpunit --configuration=phpunit.xml --list-tests` — exit 0.
- `php vendor/bin/phpunit --configuration=phpunit.pgsql.xml --list-tests` — exit 0.
- Оба списка содержат одинаковые **171 тест**. Это подтверждает сбор тестов, а не их прохождение.
- Workflow YAML разобран; все `run`-блоки прошли `bash -n`; параметры service database/user/password совпадают с job env.
- `git diff --check` — PASS.

Временные материалы: `/tmp/family-education-a04-probe.php`, `/tmp/family-education-a04-before.json`, `/tmp/family-education-a04-after.json`, `/tmp/family-education-a04-sqlite-tests.txt`, `/tmp/family-education-a04-pgsql-tests.txt`. Существенные результаты сохранены в этом документе.

## Ограничения и продолжение

Laravel config cache имеет приоритет над чтением конфигурационных файлов. Проверки выполнены без config cache; workflow очищает его перед миграцией и suites. При прямом локальном запуске PHPUnit также сначала выполнить `php artisan config:clear`; для SQLite это уже входит в `composer test`. Тестовые XML не следует считать способом переопределить ранее закэшированную конфигурацию.

Сам workflow на GitHub Actions не запускался; actionlint здесь не применялся. Совместимость миграций с реальным PostgreSQL, PHPUnit, Pint и Larastan целиком не проверены — это A18/A19. Ранее отмеченное отсутствие pdo_pgsql/pgsql/redis в локальном PHP CLI остаётся условием подготовки PostgreSQL runtime; CI устанавливает эти расширения через setup-php.

**Следующий пункт — A05: frontend CI и конфигурация Playwright.**
