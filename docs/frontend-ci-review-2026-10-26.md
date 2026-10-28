# A05 — frontend CI и Playwright

Дата: 26 октября 2026 года. **A05 выполнен.** Конфигурация проверена на Node 22.23.4; полный браузерный набор и production build остаются A16/A17 из [плана](refactoring-plan-2026-10-26.md).

## Найдено и исправлено

1. `npm audit` находился перед lint/unit/browser/build в одном job. Любой отказ audit, включая сетевую ошибку, не позволял получить результаты остальных проверок. Аудит вынесен в независимый `dependency-audit` job, который выполняет `npm audit --package-lock-only --audit-level=low`. Порог сохранён, `continue-on-error` не добавлен: провал аудита по-прежнему делает workflow неуспешным. Основной job устанавливает зависимости через `npm ci --no-audit`.
2. Playwright сохранял trace при падении, но HTML reporter не был настроен. В CI теперь включены GitHub annotations и HTML report с `open: never`; outputDir явно задан как `test-results`. Upload-artifact сохраняет `playwright-report/` и `test-results/` на 7 дней после успешного или неуспешного browser step, если workflow не отменён. При пропуске browser step upload не выполняется.
3. Vite наследовал контейнерный HMR `localhost:8081`, хотя тестовый сервер слушал `127.0.0.1:4173`. Это подтверждено через `resolveConfig`. Playwright теперь запускает Vite с `--mode test`, где HMR отключён. В обычном development-режиме прежняя HMR-конфигурация сохранена.
4. В CI включён `forbidOnly`, чтобы случайный `test.only` не оставлял часть набора без проверки. Существующий retry=1 в CI сохранён; локально retries=0.
5. В webServer env явно задан публичный тестовый Turnstile site key, уже используемый workflow для build. Это уменьшает зависимость тестового запуска от локального `.env`.

Изменены только три конфигурации frontend: [.github/workflows/ci.yml](../../frontend/.github/workflows/ci.yml), [playwright.config.js](../../frontend/playwright.config.js), [vite.config.js](../../frontend/vite.config.js). Manifest, lockfile, код приложения и существующие e2e/unit-тесты не менялись.

## Runtime и команды

- CI и frontend Dockerfile выбирают Node major 22. Для локальной проверки Node **22.23.4** установлен отдельно в `/tmp/family-education-a05-node`; основной системный Node 24.15.0 не менялся.
- Проверены `engines.node` всех 201 package entry из lock: конфликтов с Node 22.23.4 нет. Например, Vite 8.3.5 требует Node `^20.19.0 || >=22.12.0`, ESLint 10.9.1 — `^20.19.0 || ^22.13.0 || >=24`. Произвольный старый Node 22 ниже этих границ не подходит; проверенная версия подходит.
- `npm ci --no-audit`, `npm run lint`, `npm test`, `npm run test:e2e`, `npm run build` согласованы с manifest; cache setup-node использует существующий package-lock.json.
- Playwright 1.56.1 закреплён в lock. CI устанавливает Chromium командой `npx playwright install --with-deps chromium`; тестовый runner по умолчанию использует Chromium, дополнительных browser projects нет.
- webServer command: `npm run dev -- --mode test --host 127.0.0.1 --port 4173 --strictPort`. URL готовности совпадает с baseURL: `http://127.0.0.1:4173`. CI не переиспользует чужой сервер; локально разрешено переиспользование существующего сервера.
- Existing e2e suite подменяет API. Ему не нужен запущенный Laravel; это не проверка полной интеграции frontend/backend, которая остаётся A21/A22.

## Выполненные проверки

| Проверка | Результат |
| --- | --- |
| Workflow YAML через PyYAML BaseLoader | PASS; оба job независимы, порог audit сохранён. |
| `bash -n` для всех run-блоков | PASS. |
| Node engines по lock на Node 22.23.4 | PASS, 0 несовместимых записей. |
| `npm run test:e2e -- --list` на Node 22, локально и с CI=true | PASS; одинаковые 4 теста в messaging.spec.js, исполнения сценариев не было. |
| ESLint для playwright.config.js и vite.config.js на Node 22 | PASS; полный lint приложения не запускался. |
| Загрузка Playwright config в local/CI | PASS: baseURL/url, retries, reuseExistingServer, forbidOnly, trace и reporter согласованы. |
| Эффективный Vite config | PASS: в test HMR=false, в development по-прежнему clientPort=8081. |
| Временный HTTP smoke через настоящий Playwright webServer на Node 22 | PASS: сервер запущен; `/conversations/50` возвращает SPA с root, `/src/main.jsx` — HTTP 200; после теста сервер остановлен. |
| Сохранение отчётов | PASS: HTML создан для успеха и специально вызванной ошибки; при ошибке создан один корректный ZIP trace, при успехе trace не сохраняется. |
| `git diff --check` | PASS. |

HTTP smoke не создаёт browser/page: используется Playwright request fixture. Он запускается из временного config, который наследует проектные настройки, меняет testDir/output paths на `/tmp`, задаёт cwd frontend и retries=0. Диагностическая ошибка была вызвана намеренно для проверки артефактов; это не падение существующего сценария приложения. Реальная загрузка артефактов в GitHub Actions не выполнялась.

Материалы проверки: `/tmp/family-education-a05-smoke/`, включая success/failure logs, HTML reports и failure trace. Исходный snapshot файлов: `/tmp/family-education-a05-baseline.json`. Smoke-файлы не добавлены в репозиторий.

## Локальный proxy: учесть перед A16

Первый smoke остановился на ожидании webServer, хотя прямой HTTP-запрос к нему возвращал 200. В окружении заданы HTTP(S) proxy и `NO_PROXY=localhost,127.0.0.0/8,::1`; текущий proxy resolver Playwright не исключает `127.0.0.1` по CIDR-записи. Это подтверждено исходником установленного network helper и успешным повтором с точным адресом.

Для локального запуска в таком окружении задать исключение **для процесса тестов**, не меняя системный proxy:

```bash
NO_PROXY=localhost,127.0.0.1,::1 no_proxy=localhost,127.0.0.1,::1 npm run test:e2e
```

Если нужны другие существующие proxy-исключения, сохранить их и добавить точный `127.0.0.1`. В ходе A05 системные proxy-настройки не менялись. Это условие среды, а не доказанный дефект hosted GitHub runner.

## Что ещё не проверено

GitHub Actions workflow и audit-запрос не запускались; отсутствие уязвимостей не утверждается. Chromium не устанавливался, четыре браузерных сценария не выполнялись — A16. Полные lint/build/unit в этой задаче не повторялись — A17. Доставка trace/HTML через upload-artifact требует реального Actions run. Независимый audit job должен учитываться отдельно в настройках required checks репозитория, если они используются; настройки GitHub не менялись.

**Следующий пункт — A06: infrastructure CI.**
