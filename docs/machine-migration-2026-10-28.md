# Перенос разработки Family Education на другую машину

Состояние на 28 октября 2026 года. Это документация проекта для продолжения работы; история чата и локальный `SESSION_CONTEXT.md` для неё не нужны и в Git не включаются.

## Получить исходники

Рабочий каталог содержит три независимых репозитория. Корень `family-education/` не является репозиторием. Для клонирования нужен доступ к приватным репозиториям GitHub и настроенная SSH-аутентификация.

```bash
mkdir family-education
cd family-education
git clone --branch main git@github.com:greenRarog/family-education.git backend
git clone --branch main git@github.com:greenRarog/family-education-frontend.git frontend
git clone --branch main git@github.com:greenRarog/family-education-infrastructure.git infrastructure
```

Используйте именно `--branch main`: по последней удалённой проверке 27 октября default branch infrastructure была `master`, хотя работа ведётся в `main`. Default branch при переносе не меняется. Backend и frontend содержат согласованные изменения JSON-контракта переписки: переносить нужно оба.

Публикация из текущей среды ограничена отсутствием DNS-доступа к `github.com`; наличие локального коммита не доказывает успешный push. Итоговые SHA и статус отправки сохраняются отдельно в `migration-2026-10-28/README.md` рядом с тремя репозиториями. Перед клонированием через GitHub убедитесь, что коммиты опубликованы; иначе используйте bundle ниже.

## Перенос без доступа к GitHub на старой машине

Скопируйте весь каталог `migration-2026-10-28/` на новую машину. Он находится вне Git и содержит три полных Git-bundle веток `main`, SHA256SUMS, точные SHA коммитов и скрипт публикации. Bundle сохраняет Git-историю и закоммиченные исходники; в него не входят игнорируемые env, база, файлы сессии или зависимости.

Проверьте файлы в каталоге переноса:

```bash
sha256sum -c SHA256SUMS
```

Создайте новый рабочий каталог рядом с каталогом переноса:

```bash
mkdir family-education
cd family-education
git clone --branch main ../migration-2026-10-28/backend.bundle backend
git clone --branch main ../migration-2026-10-28/frontend.bundle frontend
git clone --branch main ../migration-2026-10-28/infrastructure.bundle infrastructure
git -C backend remote set-url origin git@github.com:greenRarog/family-education.git
git -C frontend remote set-url origin git@github.com:greenRarog/family-education-frontend.git
git -C infrastructure remote set-url origin git@github.com:greenRarog/family-education-infrastructure.git
bash ../migration-2026-10-28/publish.sh "$PWD"
```

Скрипт использует обычный push `main:main`, без force, и сверяет удалённый SHA. При расхождении истории сначала исследовать изменения на GitHub, не перезаписывать их. Успех push и успех GitHub Actions — разные проверки.

## Что прочитать новому исполнителю

1. `backend/AGENTS.md`; перед изменениями интерфейса — `backend/docs/design.md`.
2. Эту инструкцию, правила и последние записи журнала в [плане из 80 задач](refactoring-plan-2026-10-26.md).
3. Строку выбранной задачи и связанные отчёты. Полную историю исследований перечитывать не требуется.
4. [Infrastructure README](../../infrastructure/README.md) для команд окружения. Полная актуализация трёх README остаётся A23; старые утверждения о teacher и legacy CI/deploy сверять с кодом и этой инструкцией.

Проект — Laravel API + React SPA, PostgreSQL, Redis, Docker Compose. Auth использует session cookie и CSRF, frontend/API доступны с одного origin. Доска объявлений связывает семьи и педагогов через отклики и личные диалоги. Teacher-модуль уже существует в коде, хотя старый backend README относит его к будущему. Локализации — RU/EN/ES.

Сохранённые изменения:

- PublicAdvertisementResource ограничивает публичный JSON; данные детей остаются в приватной форме владельца. Услуги педагога без границ возраста попадают в возрастной фильтр.
- Список диалогов имеет пагинацию, последнее сообщение и unread count. История загружается частями, новые сообщения — по `after_id`, подтверждение чтения — по `through_id`. Frontend обновляет данные при видимой вкладке, повторяет ошибки чтения и сохраняет положение длинной истории.
- После завершения отклика отправка сообщений запрещена обоим участникам, история доступна. Исправлены шаблон истечения объявления и конфликт `$message` в письме нового сообщения.
- Backend CI проверяет PHP отдельно, SQLite и PostgreSQL; legacy deploy job удалён. Frontend CI содержит unit/browser/lint/build и отдельный audit job. Infrastructure CI проверяет Compose и собирает stage images без запуска и деплоя.

## Подтверждённые результаты и открытые задачи

| Область | Последнее подтверждение до переноса |
| --- | --- |
| Backend A18 | Pint PASS, Larastan 0 ошибок, SQLite 247 тестов / 2045 утверждений PASS |
| PostgreSQL A19 | 19 миграций, 247 тестов / 2045 утверждений PASS на отдельной PostgreSQL 17.11 |
| Frontend A16/A17 | 15 browser-сценариев с подменённым API, 14 unit/SSR-проверок, lint и production build PASS |
| Infrastructure A20 | Bash 7/7, local/stage Compose, сборка пяти stage images PASS; stage не запускался |

Отчёты: [A18](backend-quality-suite-review-2026-10-27.md), [A19](postgresql-suite-review-2026-10-28.md), [A16](frontend-browser-suite-review-2026-10-27.md), [A17](frontend-quality-build-review-2026-10-27.md), [A20](stage-image-build-review-2026-10-28.md). Ссылки на `/tmp` в них исторические: артефакты и браузерный кэш не переносятся вместе с Git.

Перед коммитами 28 октября повторно прошли SQLite 247/247 (2045 утверждений), Pint, Larastan (0 ошибок), frontend lint, `npm test` (runner отчитался о трёх успешных тестовых файлах), production build, Bash syntax и разбор трёх CI YAML. Larastan запускался с `--debug` без параллельных worker: обычный запуск ограничен запретом локальных сокетов текущей среды. PostgreSQL, браузеры и Docker build повторно не запускались; для них выше приведены сохранённые результаты A16/A19/A20.

**Следующий основной пункт — A21:** изолированное окружение с отдельной БД и двумя тестовыми аккаунтами для настоящего API. Затем A22 — сквозной браузерный сценарий; A23 — README; A24 — приёмка. Сохранение коммитов перед переездом не закрывает эти задачи.

**A06.1 открыт:** требуется доказать доступ infrastructure Actions к соседним репозиториям. Frontend checkout поддерживает secret `CI_FRONTEND_DEPLOY_KEY`; альтернативно нужен `CI_REPOSITORIES_TOKEN` с чтением соседних репозиториев. Обычного `github.token` недостаточно для приватного frontend. Старый диагностический скрипт и JSON результата в `/tmp/family-education-a06-*` на момент переноса отсутствуют; не рассчитывать на них. Проверять фактическую настройку и логи Actions после публикации. Не объявлять CI зелёным по локальным тестам.

A17.1, A18.1–A18.3, A19.1 закрыты. Для тестового PHP при необходимости задавать `memory_limit=512M`. Telegram/MAX, реальная доставка внешних уведомлений, stage runtime и production deployment этими проверками не подтверждены. На VPS ничего запускать для переноса не требуется.

## Восстановить окружение

Для Docker-пути нужны Git, Docker Engine и Compose plugin. Команды из корня workspace, на новой машине с новой локальной БД:

```bash
test -f backend/.env || cp backend/.env.example backend/.env
test -f frontend/.env || cp frontend/.env.example frontend/.env
test -f infrastructure/.env || cp infrastructure/.env.example infrastructure/.env
test -f infrastructure/environments/local/.env || cp infrastructure/environments/local/.env.example infrastructure/environments/local/.env
```

Проверить `APP_URL=http://localhost:8081`, `SESSION_SECURE_COOKIE=false` и совпадение `DB_PASSWORD` в backend/local env, затем:

```bash
./infrastructure/bin/local
./infrastructure/bin/compose exec app composer install --no-interaction
./infrastructure/bin/artisan key:generate
./infrastructure/bin/artisan migrate
```

Приложение — `http://localhost:8081`. Это обычное local-окружение; изоляцию для A21 ещё надо подготовить. Не использовать рабочую/stage БД для тестов.

Для команд непосредственно на хосте установить PHP 8.4 с расширениями проекта, Composer и Node.js 22.23.4 (проверялся при установке зависимостей; frontend CI использует Node 22). Затем `composer install` в backend и `npm ci` в frontend. Не выполнять update зависимостей ради переноса. Браузер поставить заново из frontend: `npx --no-install playwright install --with-deps chromium`. Для запуска browser suite: `NO_PROXY=localhost,127.0.0.1,::1 no_proxy=localhost,127.0.0.1,::1 npm run test:e2e`. Старый `PLAYWRIGHT_BROWSERS_PATH=/tmp/...` на новой машине не использовать.

## Что Git не переносит

- Реальные `.env`, VLESS-конфиги, секреты SMTP/Turnstile/Telegram, SSH-доступы. Переносить отдельно защищённым способом, если они нужны; шаблонов достаточно для нового локального окружения.
- PostgreSQL/Docker volumes и пользовательские файлы в `backend/storage/app/`. Если нужна старая локальная БД, до отключения старой машины выполнить `./infrastructure/bin/backup-database`, перенести полученный dump отдельно и сохранить соответствующий `APP_KEY` и загруженные файлы. Для восстановленных данных новый APP_KEY не генерировать. `restore-database` заменяет объекты целевой БД — запускать только для выбранной новой локальной базы.
- `vendor`, `node_modules`, `dist`, браузеры Playwright и временные логи — устанавливаются/собираются заново.
- `SESSION_CONTEXT.md`, настройки редактора и история чата — для продолжения по этой инструкции не нужны. Корневой файл сессии остаётся вне репозиториев; одноимённый файл дополнительно игнорируется в каждом из трёх репозиториев.

Дамп БД и архив секретов при подготовке коммитов не создавались; Git-bundle не является резервной копией локальных данных приложения.

## Готовое первое сообщение новому исполнителю

> Работаем в family-education, где backend, frontend и infrastructure — три отдельных Git-репозитория. Прочитай backend/AGENTS.md, backend/docs/machine-migration-2026-10-28.md, правила и последние записи журнала backend/docs/refactoring-plan-2026-10-26.md. Проверь ветки, status и SHA всех трёх репозиториев. A01–A20 завершены, отдельно открыт A06.1; следующий основной пункт A21. Сначала кратко изложи актуальный контекст, затем выполни только A21 с отдельной тестовой БД, без VPS и рабочих данных. Сохраняй существующие изменения, обнови журнал и не переходи автоматически к A22. SESSION_CONTEXT.md и история старого чата не требуются.
