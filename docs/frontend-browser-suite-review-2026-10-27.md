# A16 — полный браузерный набор Playwright

Дата: 27 октября 2026 года. **A16 завершён, PASS: 15 из 15 сценариев**, без пропусков, повторов и flaky-результатов. Полный прогон занял 31,6 секунды (31 636 мс), 2 workers. Исправлений и дополнительных подпунктов не потребовалось.

## Среда и границы

Node 24.15.0, проектный Playwright 1.56.1, Chromium Headless Shell 141.0.7390.37 (build v1194). Браузер и FFMPEG были установлены в A14 в `/tmp/family-education-playwright-browsers`; в A16 использована эта установка, версия исполняемого файла проверена. Повторная установка не требовалась.

Тестовый Vite запущен конфигурацией Playwright в mode=test на 127.0.0.1:4173. Применены NO_PROXY/no_proxy с точным 127.0.0.1 из A05. Штатные настройки: fullyParallel=true, retries=0, trace=retain-on-failure. Конфиги и системные настройки не менялись.

**API подменяется фикстурами.** Результат подтверждает browser-поведение frontend в этих сценариях, а не сквозную интеграцию с Laravel, PostgreSQL или реальным Cloudflare. Скрытие вкладки в conversation-sync моделируется через visibilityState; двухсторонний обмен — двумя browser contexts. Production build и GitHub Actions здесь не выполнялись; Node 22 в A16 не проверялся.

## Результат каждого сценария

Имена приведены без перевода, чтобы их можно было найти в исходниках, JSON и HTML. Время — длительность отдельного сценария; из-за параллельного запуска сумма больше времени всего прогона.

| Файл | Сценарий | Результат | Секунды |
| --- | --- | --- | ---: |
| conversation-list.spec.js | list pagination reaches the 21st conversation and returns without mixing pages | PASS | 1.495 |
| conversation-list.spec.js | refresh updates row previews and unread counts while the global badge counts all pages | PASS | 1.304 |
| conversation-list.spec.js | transient refresh errors preserve the last list and badge and recover on retry | PASS | 1.188 |
| conversation-list.spec.js | initial and second-page errors can recover without presenting an empty inbox as success | PASS | 6.441 |
| conversation-list.spec.js | an authenticated empty inbox has no pagination or unread badge | PASS | 4.967 |
| conversation-list.spec.js | a guest does not poll the authenticated unread endpoint | PASS | 0.940 |
| conversation-sync.spec.js | read retries preserve unread until acknowledgement and never acknowledge later arrivals | PASS | 1.035 |
| conversation-sync.spec.js | a response finishing in a hidden tab is not marked read until visibility returns | PASS | 6.588 |
| conversation-sync.spec.js | switching conversations cancels a pending show and ignores its late response | PASS | 0.982 |
| conversation-sync.spec.js | switching conversations cancels a pending read and ignores its late response | PASS | 0.948 |
| conversation-sync.spec.js | polling errors retain history and recovery applies the other participant read cursor | PASS | 0.893 |
| messaging.spec.js | two participants accept, exchange messages and terminate a conversation without reloading | PASS | 18.288 |
| messaging.spec.js | conversation list exposes the second page | PASS | 0.893 |
| messaging.spec.js | history loads in pages and a failed read is retried without clearing unread count | PASS | 11.859 |
| messaging.spec.js | advertisement explains unrestricted age and accepts an initial response | PASS | 0.720 |

Покрыты пагинация/пустое состояние/ошибки списка, общий unread, обновление диалога, retry read и граница подтверждения, скрытая вкладка, отмена запросов при смене ID, read_by_other, обмен двух участников и завершение диалога, порционная история и новые сообщения, подпись «Любой возраст» и начальный отклик.

## Воспроизведение и артефакты

Команда выполнена из frontend:

```bash
PLAYWRIGHT_BROWSERS_PATH=/tmp/family-education-playwright-browsers \
NO_PROXY=localhost,127.0.0.1,::1 no_proxy=localhost,127.0.0.1,::1 \
PLAYWRIGHT_HTML_OUTPUT_DIR=/tmp/family-education-a16-report \
PLAYWRIGHT_HTML_OPEN=never \
PLAYWRIGHT_JSON_OUTPUT_FILE=/tmp/family-education-a16-results.json \
npx --no-install playwright test --reporter=list,html,json \
  --output=/tmp/family-education-a16-results
```

- HTML: `/tmp/family-education-a16-report/index.html`.
- JSON с результатом каждого сценария, временем и параметрами запуска: `/tmp/family-education-a16-results.json`.
- Сводка `/tmp/family-education-a16-results/.last-run.json`: status=passed, failedTests=[].
- Trace не сохранены: сбоев не было, а штатная настройка retain-on-failure сохраняет их только при падении. Это не проверка создания нового trace; FAIL/trace A14/A15 остаются отдельными историческими артефактами.
- Артефакты в /tmp временные. Если каталог браузеров будет очищен, перед повтором установить Chromium через проектный `npx --no-install playwright install chromium --only-shell` с тем же PLAYWRIGHT_BROWSERS_PATH.

В выводе были только предупреждения Node о приоритете FORCE_COLOR над NO_COLOR; на результат они не повлияли. Ошибок Playwright runner нет.

Snapshot `/tmp/family-education-a16-baseline.json` подтвердил: приложение, тесты, зависимости, конфиги, lockfiles и HEAD всех трёх репозиториев сохранены. Изменены только этот отчёт, журнал плана и корневая SESSION_CONTEXT.md. `git diff --check`, пробелы новых файлов и локальные ссылки документации — PASS. Коммитов нет.

**Следующий пункт — A17: полный frontend lint, unit и production build.**
