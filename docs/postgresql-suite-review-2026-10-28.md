# A19 — миграции и полный PHPUnit PostgreSQL

Прогон: 27 октября 2026 года; отчёт завершён 28 октября. **A19 и A19.1 закрыты, PASS: 19 миграций и 247/247 тестов, 2045 утверждений.** Errors/failures/skipped=0. Время полного PHPUnit — 19,425 с, память — 191 МБ. Набор случаев совпадает с успешным SQLite-прогоном A18; тесты не исключались.

## Изоляция и подтверждение драйвера

Создан новый контейнер `family-education-a19-postgres` из локального postgres:17-alpine, фактическая версия сервера **PostgreSQL 17.11**. Данные — в tmpfs `/var/lib/postgresql/data`, порты не публиковались. Использована отдельная внутренняя сеть `family-education-a19-test`, без подключения к сетям рабочих сервисов.

Тестовый PHP запускался отдельными одноразовыми контейнерами из существующего infrastructure-app: PHP 8.4.27, PDO pgsql. Это устранило ограничение host PHP, в котором pdo_pgsql отсутствует, без установки системных пакетов. Локальный образ использован как PHP runtime, а не как доказательство новой сборки образов проекта (это A20).

Код backend скопирован в `/tmp/family-education-a19/backend` из текущих неигнорируемых файлов. Реальная .env не копировалась; vendor подключён read-only. PHP запускался как uid/gid 1000, storage/cache принадлежат изолированной копии. Созданы случайные временные тестовые credentials и APP_KEY, env-файлы с mode 0600. Значения рабочих секретов не использовались.

Bootstrap подтвердил: environment=testing, config_cached=false, connection=pgsql, **PDO driver=pgsql**, host=postgres, database=family_education_test, username=a19_test. До миграций таблицы migrations не было, после — 19 записей. Настройки PHPUnit взяты из проектного phpunit.pgsql.xml, который принудительно выбирает pgsql и отдельное имя БД. Cache/mail=array, queue=sync, DB_URL пустой.

## A19.1 — память тестового процесса

Первый полный прогон завершился аварийно на NotificationRenderingTest/Faker Text из-за стандартного лимита PHP 128 МБ. Это не SQL-ошибка; исходный лог сохранён как `initial-phpunit.log`.

Повтор выполнен с **`php -d memory_limit=512M` только для процесса PHPUnit**. Все 247 тестов прошли; пиковая память 191 МБ объясняет недостаточность 128 МБ. Production php.ini, Dockerfile, конфигурации тестов и исходники не менялись. Для воспроизведения в этом образе использовать указанный лимит.

## Команды и артефакты

Временный wrapper `/tmp/family-education-a19/run-php` запускает PHP в изолированном контейнере с указанными сетью, копией исходников, vendor и env. Во время существования временной БД были выполнены:

```bash
/tmp/family-education-a19/run-php /runtime-probe.php
/tmp/family-education-a19/run-php artisan migrate --database=pgsql --force --no-interaction
/tmp/family-education-a19/run-php -d memory_limit=512M vendor/bin/phpunit \
  --configuration=phpunit.pgsql.xml --colors=never --do-not-cache-result \
  --log-junit=/artifacts/phpunit.xml
```

В `/tmp/family-education-a19/artifacts` сохранены:

- `migrate.log`, `migrate.exit`, `migration-count.txt` — 19 миграций PASS;
- `phpunit.log`, `phpunit.xml`, `phpunit.exit` — полный PASS, код 0;
- `initial-phpunit.log`, `.exit` и `.xml` — исходный сбой памяти (XML может быть незавершённым);
- `runtime-before.json`, `runtime-after.json` — фактическая БД и драйвер;
- `network.txt`, `postgres-isolation.txt`, `images.txt` — изоляция и идентификаторы образов.

Образы: PHP `sha256:2f77d5da56bfaab605c7e3150afa4c6894195b9d39818f3993db6d3b84529ea5`; PostgreSQL image `sha256:ab20e934b27ba3ff927c1b4c9ff8cf6c32f315f743efe68dd3441182a87fb085`, digest `postgres@sha256:eee6c6e1b0894fa4fe0075eb054af9fd1cf39a35321c2d4110626cfe506411fb`.

После проверки удалены **только созданные A19 контейнер и сеть**; tmpfs-БД уничтожена. Отсутствие ресурсов с label family-education.task=A19 проверено. Рабочие/stage контейнеры не останавливались и их БД не использовались. Для повторного запуска нужно заново создать отдельную сеть/БД, wrapper после cleanup сам этого не делает. /tmp — временное хранилище.

## Старые замечания

- **A17.1 закрыт:** исправлен владелец только frontend/dist; обычный npm run build теперь PASS без --outDir. [Отчёт](frontend-quality-build-review-2026-10-27.md).
- **A18.1–A18.3 закрыты ранее:** [отчёт A18](backend-quality-suite-review-2026-10-27.md).
- **A06.1 остаётся открытым:** реальный checkout из Actions ещё не выполнен. На 27 октября у infrastructure нет опубликованных workflow, Actions secrets и variables; frontend приватный. Для пользователя подготовлен `/tmp/family-education-a06-access/run.sh`: отдельный read-only deploy key frontend, Actions secret CI_FRONTEND_DEPLOY_KEY и diagnostic workflow в новой ветке. Скрипт не выполнен агентом; локальные рабочие изменения он не публикует. Подробности в [отчёте A06](infrastructure-ci-review-2026-10-27.md).

Snapshot `/tmp/family-education-a19-baseline.json` подтверждает сохранение HEAD и предыдущих изменений. Исходники/backend-тесты не менялись. Из рабочих файлов добавлена поддержка CI_FRONTEND_DEPLOY_KEY в frontend checkout infrastructure CI и обновлена документация/SESSION_CONTEXT.md. Вне Git изменены права и артефакты dist. `git diff --check`, ссылки и пробелы документации — PASS. Агент коммитов, публикации и деплоя не выполнял.

**Следующий основной пункт — A20. Из старых обязательств остаётся A06.1 до успешного Actions checkout.**
