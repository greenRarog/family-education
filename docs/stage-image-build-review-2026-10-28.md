# A20 — Bash, Compose и сборка stage-образов

Дата: 28 октября 2026 года. **A20 завершён, PASS.** Проверены семь Bash-скриптов, обе конфигурации Compose и сборка всех пяти stage-целей. Исправлений/подпунктов не потребовалось. **Stage не запускался; успешная сборка не означает успешную работу приложения.**

## Изоляция

Работа выполнена в `/tmp/family-education-a20/workspace` — копии текущих неигнорируемых файлов backend/frontend/infrastructure, включая незакоммиченные изменения. Реальные .env, vendor, node_modules, dist и VLESS-конфигурации не копировались.

Пять .env созданы из проектных .env.example. Для Compose использованы единые одноразовые значения DB_PASSWORD=a20-build-only и публичный тестовый VITE_TURNSTILE_SITE_KEY=1x00000000000000000000AA. Wrapper создал local/stage vless.json из example с mode 0600 только внутри копии.

Все команды Compose выполнены с отдельным project name **family-education-a20**. План build подтвердил отдельные image tags; теги образов рабочих сервисов не использовались. Команд up/run/start, миграций, bin/stage, публикации образов или деплоя не было. Список работающих контейнеров до/после совпал по ID, имени и image; контейнеров, сетей и volumes проекта A20 нет. Созданные образы сохранены локально.

## Проверки

| Проверка | Результат |
| --- | --- |
| bash -n: artisan, backup-database, compose, local, renew-certificate, restore-database, stage | 7 PASS |
| Compose local: разрешение env, путей, YAML и config JSON | PASS, 9 сервисов |
| Compose stage: разрешение env, путей, YAML и config JSON | PASS, 9 сервисов |
| Stage build --print: contexts/targets/image tags | PASS, 5 целей |
| Stage build: app, queue, scheduler, nginx, frontend | PASS, код 0 |
| Сохранность работающих контейнеров | PASS |

В local frontend target=dev, опубликованные порты — 8080, 8081, 15433. В stage frontend target=production, опубликованы только 80/443 у frontend-nginx, PostgreSQL использует stage_postgres_data. Все build contexts находятся внутри изолированной копии. Config-проверка не означает доступности TLS-сертификатов, работы proxy, healthchecks или корректности настроек будущего production.

Первый вспомогательный скрипт проверки ожидал поле image в config JSON, но Compose для сервисов с build его не выводит. Проверка перенесена на фактические tags из build --print. Это корректировка диагностического скрипта, не дефект конфигурации или падение сборки.

## Собранные образы

| Сервис | Локальный тег | Image ID (начало) | Размер, MB |
| --- | --- | --- | ---: |
| app | family-education-a20-app:latest | df3bf7ba4314 | 672.4 |
| queue | family-education-a20-queue:latest | 360790913443 | 672.4 |
| scheduler | family-education-a20-scheduler:latest | 017f65c265a3 | 672.4 |
| nginx | family-education-a20-nginx:latest | ea91ce2e2189 | 62.2 |
| frontend | family-education-a20-frontend:latest | 86eec7232be9 | 62.6 |

Полные ID/размеры/Created/User/Cmd сохранены в `images.json`. У PHP-образов User=1000. Queue/scheduler используют target app; их команды запуска переопределяются в Compose. Наличие образов не подтверждает запуск workers/scheduler.

Frontend собран по Dockerfile на **node:22-alpine**: npm ci установил 158 пакетов, production Vite build прошёл (67 модулей, 328 мс), статические файлы скопированы в nginx production image. Это подтверждает контейнерную сборку на Node 22; browser runtime этого образа не проверялся.

Backend собран по Dockerfile на **php:8.4.27-fpm**: установлены pdo_pgsql/pgsql/zip/redis, Composer поставил 98 production-пакетов из lock без dev, optimized autoload и Laravel package discovery прошли. Исходники и конфиги Dockerfile не менялись. Сборка использовала обычный BuildKit cache, принудительный --no-cache не задавался.

Среда: Docker Engine 29.9.2, Compose v5.6.1. В логе есть два предупреждения SecretsUsedInArgOrEnv для VITE_TURNSTILE_SITE_KEY; здесь передан публичный тестовый site key, не серверный secret key. Также были предупреждения отсутствующего terminal bold mode при компиляции PHP extensions и предложение обновить npm. Сборку они не остановили, версии зависимостей ради них не менялись.

## Воспроизведение и материалы

В подготовленной копии, из workspace:

```bash
for script in infrastructure/bin/*; do bash -n "$script"; done

env -i PATH="$PATH" HOME="$HOME" DB_PASSWORD=a20-build-only \
  VITE_TURNSTILE_SITE_KEY=1x00000000000000000000AA \
  ./infrastructure/bin/compose -p family-education-a20 config --format json

env -i PATH="$PATH" HOME="$HOME" DB_PASSWORD=a20-build-only \
  VITE_TURNSTILE_SITE_KEY=1x00000000000000000000AA \
  ./infrastructure/bin/compose stage -p family-education-a20 config --format json

env -i PATH="$PATH" HOME="$HOME" DB_PASSWORD=a20-build-only \
  VITE_TURNSTILE_SITE_KEY=1x00000000000000000000AA \
  ./infrastructure/bin/compose stage -p family-education-a20 build --print

env -i PATH="$PATH" HOME="$HOME" DB_PASSWORD=a20-build-only \
  VITE_TURNSTILE_SITE_KEY=1x00000000000000000000AA \
  ./infrastructure/bin/compose stage -p family-education-a20 --progress plain build
```

`/tmp/family-education-a20/artifacts/`:

- local-config.json / stage-config.json и stderr — разрешённые Compose-конфигурации;
- stage-build-plan.json и stderr — пять build targets и отдельные tags;
- build-initial.log / build-initial.exit — полный единственный build, PASS/0;
- images.json — идентификаторы итоговых образов;
- source-manifest.json — SHA256 файлов копии и исходные HEAD;
- checks.json — сводка результатов;
- containers-after.json — список работающих контейнеров после проверки.

Исходный список контейнеров: `/tmp/family-education-a20-containers-before.json`. Snapshot рабочих файлов: `/tmp/family-education-a20-baseline.json`. /tmp временный; Docker-образы остаются до их удаления пользователем/очистки Docker. Они не являются готовым deployment с production-секретами и TLS.

Snapshot подтвердил сохранение HEAD, прежних изменений, исходников, тестов, конфигов и lockfiles всех трёх репозиториев. Изменены только этот отчёт, журнал и корневая SESSION_CONTEXT.md. `git diff --check`, пробелы/ссылки документации — PASS. Коммитов/публикации нет. Полные unit/browser/backend suites повторно не запускались: код не менялся, их результаты сохранены в A16–A19.

**Следующий пункт — A21: минимальное изолированное окружение с настоящим backend для сквозного браузерного сценария A22. A06.1 остаётся отдельным неподтверждённым Actions checkout.**
