# A01–A03: реестр изменений и готовность зависимостей

Дата: 26 октября 2026 года. Задание: выполнить A01, A02 и A03 из [плана](refactoring-plan-2026-10-26.md). Этот документ фиксирует состояние до дальнейших исправлений приложения.

## Итог

| Задача | Статус | Результат |
| --- | --- | --- |
| A01 — реестр | Сделано | Учтены все 38 изменённых/untracked-файлов на начало работы, текущие ветки/HEAD, назначение изменений и необходимые проверки. |
| A02 — backend-зависимости | Сделано | После расширения доступа Composer install завершён; все 147 версий и dist references совпадают с lock. Autoload, PHPUnit/Pint/PHPStan доступны. |
| A03 — frontend-зависимости | Сделано | Четыре integrity восстановлены из npm registry для точных версий, `npm ci` завершён, npm ls и CLI checks проходят. Версии зависимостей сохранены. |

Код приложения, package manifests и Composer lock сохранены. В npm lock добавлены только четыре поля integrity; версии и все остальные поля сохранены. Коммиты, миграции и deployment не выполнялись.

## Повтор A02/A03 после расширения доступа — завершён

Пользователь изменил права окружения и поручил повторить установку. Registry npm и GitHub API ответили HTTP 200 через прежний proxy; его настройки не менялись. Новый исходный snapshot: `/tmp/family-education-a02-a03-retry-baseline.json`.

| Проверка / действие | Результат |
| --- | --- |
| `composer install --no-interaction --prefer-dist --no-progress` (с внешним timeout 300s) | PASS, exit 0; autoload сгенерирован, `artisan package:discover` выполнен. |
| `composer validate --strict --no-interaction` | PASS. |
| `composer check-platform-reqs` для установленного vendor | PASS. |
| Сверка installed.json с Composer lock | PASS: все 147 пакетов, версии и dist references совпадают. |
| `php vendor/bin/phpunit --version` | PHPUnit 12.5.33. |
| `php vendor/bin/pint --version` | Pint 1.30.5. |
| `php vendor/bin/phpstan --version` | PHPStan 2.2.8. |
| Загрузка `vendor/autoload.php` и Composer InstalledVersions | PASS; Laravel 13.25.0, Larastan 3.10.0. |
| `npm view <package>@<version> dist --json` для четырёх точных версий | Получены SHA-512 integrity; tarball URL сверены с lock перед добавлением полей. |
| Сравнение npm lock до/после | Только четыре добавленных integrity; версии, URL, зависимости и остальные поля идентичны. Все HTTPS package entries теперь имеют integrity. |
| `npm ci --no-audit --no-fund --progress=false` (с внешним timeout 300s) | PASS, exit 0; установлено 158 пакетов. |
| `npm ls --depth=0` и сверка версий установленных package.json с lock | PASS. |
| `./node_modules/.bin/vite --version` | Vite 8.3.5. |
| `./node_modules/.bin/eslint --version` | ESLint 10.9.1. |
| `./node_modules/.bin/playwright --version` | Playwright 1.56.1. |
| `npm test` | PASS, 7 тестов, 0 ошибок. |
| `git diff --check` в backend/frontend | PASS. |

Integrity добавлены для `@playwright/test@1.56.1`, `playwright@1.56.1`, `playwright-core@1.56.1`, nested `fsevents@2.3.2`. Метаданные получены через npm CLI, поля добавлены адресно без пересчёта версий. **A03.1 завершён.** Новый SHA-256 `frontend/package-lock.json`: `2dac890ff51834d854f664544f0d654344ee8984a8259c5a50753d11012b2ea4`.

Composer install сообщил о девяти существующих несоответствиях PSR-4 путей/namespace тестов: три класса `Feature\\Listeners\\Send*NotificationTest`, `Feature\\UserTest`, `Feature\\Notifications\\NotificationQueueTest`, а также `Tests\\Feature\\Admin\\{BlockedTerm,Subject,MetroStation,District}ControllerTest`. Это предупреждения генерации autoload, а не результат запуска PHPUnit. Разобрать при A18; тестовые файлы в рамках установки не менялись.

В PHP CLI всё ещё отсутствуют pdo_pgsql/pgsql/redis; доступны PDO mysql/sqlite. Это ограничение будущих runtime-проверок A19, не блокер установки зависимостей A02. Node 24.15.0 отличается от Node 22 в CI — проверка CI остаётся A05. Полные PHPUnit/Pint/Larastan, lint/build, браузерные сценарии, установка Chromium и аудит зависимостей не выполнялись.

Логи успешных установок: `/tmp/family-education-a02-composer-retry.log`, `/tmp/family-education-a03-npm-retry.log`. **Следующая задача — A04.**

Ниже сохранён исходный реестр A01 и история первой попытки A02/A03. Упоминания блокеров, отсутствующих инструментов и старые SHA-256 в этих исторических разделах описывают состояние до успешного повтора; актуальный статус приведён выше.

## Исходное состояние Git

| Репозиторий | Ветка | HEAD | Изменённых / удалённых / новых файлов |
| --- | --- | --- | --- |
| backend | `main` | `e5e1e584514f173c444f5d5ea3540220a4ec982d` | 10 / 1 / 8 = 19 |
| frontend | `main` | `eeb38f3cbf08a0ad52f6ee50189a87f0433999da` | 11 / 0 / 7 = 18 |
| infrastructure | `main` | `932d4eba853306f7826d92a3440576c3e7464460` | 0 / 0 / 1 = 1 |

Staged-изменений нет. Корневая рабочая директория не является четвёртым Git-репозиторием. `SESSION_CONTEXT.md` находится вне трёх репозиториев и не входит в число 38. Сам этот отчёт создан после снятия исходного состояния и также не входит в него.

Обозначения таблиц: **M** — изменённый tracked-файл, **D** — удалённый tracked-файл, **??** — новый untracked-файл. Пути приведены относительно указанного репозитория. «Прочитано» означает проверку содержимого/diff, а не подтверждение функциональности.

## Backend — все 19 файлов

| Статус | Файл | Что изменено / назначение | Проверка по плану |
| --- | --- | --- | --- |
| M | `.github/workflows/ci.yml` | Убраны frontend build и legacy deploy; добавлены style/static analysis и отдельные SQLite/PostgreSQL прогоны. | A04, A18, A19 |
| M | `app/Http/Controllers/v1/Api/AdvertisementController.php` | Публичный Resource для feed/show; исключена загрузка children в публичных ответах; услуги без возрастного диапазона включены в фильтр. | A07, A08 |
| M | `app/Http/Controllers/v1/Api/ConversationController.php` | Явная пагинация списка, последнее сообщение/unread; ограниченная история и курсоры до/после ID. | A10, A11, A12 |
| M | `app/Http/Controllers/v1/Api/MessageController.php` | Ограничение read по `through_id`, подтверждённые курсор и время в ответе. | A12 |
| M | `app/Http/Resources/Api/ConversationResource.php` | Добавлены `last_message` и `unread_count`. | A10, A14 |
| M | `app/Models/Conversation.php` | Отношение `lastMessage` через `latestOfMany`. | A10 |
| M | `app/Notifications/NewMessageNotification.php` | Параметр mail view переименован из `message` в `chatMessage`. | A09 |
| D | `resources/views/emails/notifications/dvertisement-expired.blade.php` | Старое имя шаблона с опечаткой удалено; замена указана ниже. | A09 |
| M | `resources/views/emails/notifications/new-message.blade.php` | Все обращения к модели сообщения используют `chatMessage`. | A09 |
| M | `routes/api.php` | Добавлен GET истории `/conversations/{conversation}/messages`. | A11 |
| M | `tests/Feature/ConversationTest.php` | Существующий тест списка читает `conversations.data` вместо старого контракта. | A10, A18 |
| ?? | `app/Http/Resources/Api/PublicAdvertisementResource.php` | Разрешённый список публичных полей объявления и публичных сведений педагога. | A07 |
| ?? | `docs/project-audit-2026-10-25.md` | Исходный аудит, крупные этапы и список продуктовых решений. | Исторический источник; функциональность не подтверждает |
| ?? | `docs/refactoring-plan-2026-10-26.md` | План из 80 задач, правила размера сессии и журнал продолжения. | Организация работы; журнал обновлён в текущей сессии |
| ?? | `phpunit.pgsql.xml` | Отдельный test config с PostgreSQL, тестовой БД и сбросом DB_URL. | A04, A19 |
| ?? | `resources/views/emails/notifications/advertisement-expired.blade.php` | Правильное имя шаблона истечения; байты совпадают с удалённым файлом из HEAD. | Совпадение проверено; рендеринг — A09 |
| ?? | `tests/Feature/ConversationPaginationTest.php` | Пять тестов: 21 диалог, страницы истории, права/курсор, read snapshot, запрет отправки после завершения. | A10–A13 |
| ?? | `tests/Feature/Notifications/NotificationRenderingTest.php` | Один тест рендерит три уведомления на трёх языках через sync queue/array mail и проверяет экранирование. | A09; не SMTP-доставка |
| ?? | `tests/Feature/PublicAdvertisementTest.php` | Два теста: публичная приватность/приватный edit и возрастные границы. | A07, A08 |

## Frontend — все 18 файлов

| Статус | Файл | Что изменено / назначение | Проверка по плану |
| --- | --- | --- | --- |
| M | `.github/workflows/ci.yml` | Unit tests, установка Chromium, браузерные сценарии и upload traces перед production build. | A05, A16, A17 |
| M | `.gitignore` | Исключены `playwright-report/` и `test-results/`. | Прочитано |
| M | `eslint.config.js` | Исключены browser artifacts; Node globals доступны в тестах и Playwright config. | A05, A17 |
| M | `package-lock.json` | Четыре записи для Playwright 1.56.1 и optional fsevents 2.3.2; у всех четырёх нет integrity. | A03; требуется A03.1 |
| M | `package.json` | Добавлены `test`, `test:e2e`, devDependency `@playwright/test` 1.56.1. | A03 |
| M | `src/components/ConversationListItem.jsx` | Последнее сообщение, unread count и название услуги педагога в карточке. | A14 |
| M | `src/i18n.js` | Шесть строк RU/EN/ES для возраста, пагинации и ошибок синхронизации. | A08, A14–A16 |
| M | `src/layouts/Layout.jsx` | Периодическое обновление общего unread и обновление по событию сообщений. | A14, A15 |
| M | `src/pages/AdvertisementShow.jsx` | Подпись «Любой возраст» при пустом диапазоне. | A08, A16 |
| M | `src/pages/ConversationShow.jsx` | Polling с курсором, подтверждение read, старая история/прокрутка, обновления статуса, разрешение отзыва принятого отклика. | A13, A15, A16, A22 |
| M | `src/pages/Conversations.jsx` | Страницы списка, polling и обработка нового API-контракта. | A14, A16 |
| ?? | `e2e/messaging.spec.js` | Четыре браузерных сценария: участники/завершение, список, длинная история/read retry, возраст/первичный отклик. API подменяется. | A16; не заменяет A22 |
| ?? | `playwright.config.js` | Vite webServer на 4173, RU locale, retry в CI и traces. | A05, A16 |
| ?? | `src/lib/conversation.js` | Допустимые переходы/отправка, слияние истории и применение read receipt. | Unit PASS; интеграция — A13, A15 |
| ?? | `src/lib/message-events.js` | Событие обновления сообщений для общего счётчика. | A14, A15 |
| ?? | `src/lib/polling.js` | Последовательные запросы, видимость вкладки, повторное обновление и отмена. | Unit PASS; браузер — A15, A16 |
| ?? | `tests/conversation.test.js` | Четыре unit-теста переходов, отправки, слияния сообщений и receipts. | 4/4 PASS прямым запуском Node |
| ?? | `tests/polling.test.js` | Три unit-теста последовательности/cleanup, скрытой вкладки и догрузки. | 3/3 PASS прямым запуском Node |

## Infrastructure — единственный новый файл

| Статус | Файл | Что изменено / назначение | Проверка по плану |
| --- | --- | --- | --- |
| ?? | `.github/workflows/ci.yml` | Checkout трёх репозиториев, временные env, Bash/Compose checks и stage image build без запуска stage. | A06, A20; доступ CI token к соседним репозиториям не подтверждён |

## Статус сценариев на момент A01

| Сценарий | Что подтверждено сейчас | Что ещё требуется |
| --- | --- | --- |
| Публичная приватность и возраст | Реализация и соответствующие тесты найдены; PHP синтаксически корректен. | Запуск PHPUnit A07/A08; проверка потребителей публичного ответа. |
| Email | Имя шаблона исправлено без изменения его содержимого; `chatMessage` согласован в notification/view. | Реальный рендеринг A09; очередь/доставка отдельно. |
| Список/история/read API | Контракты, новый маршрут и тесты присутствуют; PHP syntax PASS. | A10–A12 на установленном backend. |
| Отзыв принятого отклика | Frontend helper и regression test присутствуют; helper unit PASS. Backend переход уже поддерживался до текущего diff. | API и браузер A13/A16. |
| Polling/слияние/receipts | Семь unit-тестов проходят прямым запуском. | React lifecycle, scroll, реальные ответы API и браузер A14–A16/A22. |
| CI | Три workflow и test config прочитаны; проверки в Actions не запускались. | A04–A06 и фактические прогоны. |
| Интеграция frontend/backend | В новом браузерном наборе API заменяется фикстурами. | A21/A22 с настоящим Laravel API. |

## A02: backend runtime и зависимости

Проверено в CLI: PHP **8.4.27**, Composer **2.9.7**. Есть `pdo_sqlite`, `sqlite3`, `curl`, `dom`, `xml`, `mbstring`, `openssl`, `zip` и остальные расширения, требуемые lock-файлом. `composer check-platform-reqs --lock` проходит. При этом **`pdo_pgsql`, `pgsql` и `redis` в текущем PHP CLI отсутствуют**: Composer-проверка не доказывает готовность PostgreSQL/Redis runtime. Backend CI отдельно запрашивает эти расширения.

Зафиксированные версии: Laravel **13.25.0**, Pint **1.30.5**, Larastan **3.10.0**, PHPStan **2.2.8**, PHPUnit **12.5.33**. Версии из кэша вместо них не подставлялись.

| Команда / действие | Результат |
| --- | --- |
| `composer validate --strict --no-interaction` в backend | PASS, manifest и lock согласованы. |
| `composer check-platform-reqs --lock` в backend | PASS, включая dev-зависимости. |
| `timeout 60s composer --no-cache install --no-interaction --prefer-dist --no-progress` | BLOCKED: загрузки с GitHub завершаются curl error 7, подключение к `127.0.0.1:2080` не устанавливается. Попытка ограничена 60 секундами, итоговый exit 124. |
| Копия существующего Composer cache в `/tmp/family-education-a02-composer-cache` | Создана для попытки установки без записи в домашний cache. |
| `COMPOSER_DISABLE_NETWORK=1 COMPOSER_CACHE_DIR=/tmp/family-education-a02-composer-cache timeout 45s composer install --no-interaction --prefer-dist --no-progress` | BLOCKED, exit 100: в кэше недостаточно точных архивов; среди недоступных — PHPStan 2.2.8 по lock reference. |
| Наличие `vendor/autoload.php`, `vendor/bin/phpunit`, `vendor/bin/pint`, `vendor/bin/phpstan` после попыток | Все отсутствуют; инструменты проекта не готовы. |

Сетевые HTTP(S)-proxy переменные указывают на `http://127.0.0.1:2080`; ALL_PROXY — на SOCKS того же адреса. Системные proxy-настройки не менялись. Отсутствие PostgreSQL-расширения — отдельное условие будущего A19, а не причина сбоя загрузки Composer.

**Условие продолжения A02:** окружение с разрешённым доступом к источникам из lock или полный кэш точных архивов. Повторить install, проверить загрузку autoload и версии PHPUnit/Pint/PHPStan. Полные lint/static/tests относятся к A18, миграции — к A19.

Логи попыток: `/tmp/family-education-a02-composer-install.log`, `/tmp/family-education-a02-composer-offline.log`. Существенные результаты сохранены здесь; продолжение не зависит от сохранности `/tmp`.

## A03: frontend runtime и lock-файл

Проверено: Node **24.15.0**, npm **11.12.1**. CI задаёт Node **22**; совпадение среды с CI пока не проверено. У всех 201 записей пакетов в lock с объявленным `engines.node` текущая версия Node проходит указанный диапазон.

- Lockfile format: **3**, 201 package entry плюс корневая запись.
- `dependencies` и `devDependencies` корня совпадают с manifest; версии прямых пакетов удовлетворяют указанным диапазонам.
- Playwright/test → Playwright → Playwright-core закреплены на **1.56.1**; optional fsevents — **2.3.2**.
- Проверка виртуального дерева npm Arborist рассмотрела 202 узла и 292 зависимости. Первичный отчёт показал шесть MISSING-ссылок у optional `@tailwindcss/oxide-wasm32-wasi`; все шесть объявлены как `bundleDependencies`, сама запись идентична HEAD. Это отдельно рассмотренный bundled/platform-specific случай, не новый дефект от добавления Playwright. Остальные обязательные связи согласованы.
- Без `integrity` остались `node_modules/@playwright/test`, `node_modules/playwright`, `node_modules/playwright-core`, `node_modules/playwright/node_modules/fsevents`. Надёжно завершить восстановление всех четырёх сумм из имеющегося кэша нельзя: метаданные точных версий test/playwright отсутствуют. Суммы не выдумывались и lock вручную частично не исправлялся.

| Команда / действие | Результат |
| --- | --- |
| `timeout 60s npm ci --cache /tmp/family-education-a03-npm-cache --prefer-offline --fetch-retries=0 --fetch-timeout=15000 --no-audit --no-fund --progress=false` | BLOCKED, exit 1: `connect EPERM 127.0.0.1:2080`; ошибка загрузки `zod-validation-error@4.0.2`. |
| Чтение существующего npm cache | Найдены cache index entries для 92 из 201 tarball URL; это не гарантия полной offline-установки. Метаданные playwright-core/fsevents доступны, test/playwright — нет. |
| Копия `_cacache` в `/tmp/family-education-a03-npm-cache` | Позволяет использовать существующие данные, не меняя домашний cache. |
| `timeout 45s npm ci --offline --cache /tmp/family-education-a03-npm-cache --no-audit --no-fund --progress=false` | BLOCKED, exit 1: `ENOTCACHED` для `zod@4.6.7`. |
| Наличие `node_modules/.bin/vite` и `node_modules/.bin/playwright` после попыток | Оба отсутствуют. Установка и build/browser readiness не подтверждены. |

`--no-audit --no-fund` ограничивали установку пакетов; аудит уязвимостей не выполнялся. Chromium не устанавливался: это A16.

**A03.1 — небольшой подпункт продолжения:** в окружении с доступными метаданными registry восстановить четыре integrity для точных текущих версий средствами npm, проверить diff manifest/lock и выполнить чистый `npm ci`. Если исправление lock потребует иной самостоятельной работы, сначала уточнить её границы. Не выполнять общий `npm update` и не заменять Playwright версией из кэша. Затем проверить `npm ls --depth=0` и версии доступных CLI; lint/build — A17.

Логи: `/tmp/family-education-a03-npm-ci.log`, `/tmp/family-education-a03-npm-offline.log`. Структурный отчёт: `/tmp/family-education-a03-lock-check.json`, отчёт по cache — `/tmp/family-education-a03-cache-check.json`.

## Выполненные проверки и сохранность изменений

- `node --test --test-reporter=tap tests/*.test.js` в frontend — PASS, runner отчитался о двух файлах.
- `node tests/conversation.test.js` — **4/4 PASS**; `node tests/polling.test.js` — **3/3 PASS**. Эти прямые запуски подтверждают выполнение семи отдельных unit-тестов.
- `php -l` для всех **12 существующих изменённых/новых обычных PHP-файлов** из исходного реестра — PASS. Blade сюда не включался; синтаксическая проверка не заменяет PHPUnit.
- `git diff --check` во всех трёх репозиториях — PASS для tracked diff.
- Перед работой снят SHA-256 snapshot всех существующих файлов из реестра. Итоговое сравнение — PASS: среди 38 исходных записей изменился только журнал плана; остальные файлы сохранены, удалённый шаблон не восстановлен. Все четыре SHA-256 manifests/lock совпадают с исходными. HEAD и Git index трёх репозиториев не изменились. Новый отчёт и корневая записка учитываются отдельно.

Исходные SHA-256 файлов зависимостей для проверки неизменности:

| Файл | SHA-256 |
| --- | --- |
| `backend/composer.json` | `ce689a663c9cbce95f203bd5a6e9b96082e1ba26242a6e69a8badc8403508796` |
| `backend/composer.lock` | `c84836fb6d8341101784a626d095d919996d1a383163726f0fd1fbe4ab92bb83` |
| `frontend/package.json` | `3f88055c3afd54ec5d3da992d139af4d4ada07340bac64de65ed8ae94ac4ff96` |
| `frontend/package-lock.json` | `2e75332608cde0ad4c1a004a9d1ff13d42309c9b9d43f02af4ed810ebf1f54fe` |

На момент первой попытки следующим шагом было завершить A02/A03.1. После успешного повтора они завершены; следующий пункт — **A04: проверка backend CI и конфигураций тестовой БД**. Установка зависимостей сама по себе не подтверждает функциональные тесты приложения.
