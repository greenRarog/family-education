# A15 — обновление открытого диалога

Дата: 27 октября 2026 года. **A15 и подпункт A15.1 завершены, PASS.** Шесть целевых браузерных сценариев и 14 unit/SSR-тестов прошли. Новых дефектов приложения в проверенных сценариях не найдено; исправлен перехват API существующего browser-теста. Код приложения, зависимости и конфиги не менялись.

## Проверенная логика

Просмотрены ConversationShow, ConversationShowRoute в App, MessageList, conversation/polling/message-events, Layout и API client; ответы сопоставлены с ConversationController::show/messages и MessageController::read после A11/A12. Прочитаны AGENTS.md и design.md.

- Первичная загрузка получает последние 50 сообщений без after_id; последующие запросы используют latest_id последней полученной порции. Пустая порция не сбрасывает cursor. has_more запускает следующую порцию сразу; история загружается отдельно через before_id.
- mergeMessages объединяет сообщения по ID, сортирует по возрастанию и сохраняет подтверждённый read_at, если запоздавшая порция возвращает null. Функциональные setState сохраняют сообщения при пересечении polling, истории и отправки.
- read отправляет through_id полученной порции только в видимой вкладке и при cursor > readThrough. readThrough обновляется после успешного ответа; ошибка оставляет границу прежней, поэтому следующий polling повторяет read. markIncomingRead применяет серверную границу только к входящим сообщениям, сохраняя прежние даты. Успешный receipt вызывает MESSAGES_CHANGED, Layout перечитывает общий badge.
- read_by_other_through_id меняет индикатор собственных сообщений даже при пустой новой порции. Это подтверждено браузерным сценарием восстановления после ошибки polling.
- ConversationShowRoute использует key=conversationId: смена ID сбрасывает историю, черновик, загрузку, ошибки и локальные курсоры. Cleanup старого эффекта устанавливает cancelled и вызывает poller.stop(), который отменяет сигнал, таймер и обработчики событий.
- Polling сериален. Скрытие вкладки убирает таймер; уже начатый GET может завершиться и обновить историю, но новый read в скрытой вкладке не запускается. Возврат видимости возобновляет запросы.
- По чтению кода: mutationVersion защищает локально изменённый статус отклика от poll-ответа, начавшегося до изменения. Отдельного нового browser-теста этой гонки в A15 нет.
- По чтению кода: ручные запросы старой истории, отправки и смены статуса не используют AbortSignal. Их setState относится к прежнему keyed-компоненту; отправка может завершиться на сервере после ухода. A15 проверяет отмену именно polling/read и не утверждает отмену всех мутаций.

## Unit/SSR — PASS

Исходный целевой набор conversation/polling: 7 PASS. Добавлены три проверки:

1. Остановка во время незавершённого polling отменяет signal; позднее завершение с has_more и прежний pending refresh не создают таймеров или новых запросов.
2. Скрытие во время запроса подавляет следующий немедленный запрос; focus/online в скрытой вкладке не возобновляют работу, возврат видимости запускает обновление.
3. Повторный и пустой receipt сохраняют старые даты, не меняют собственные сообщения и сообщения выше границы; запоздавшая порция с null read_at не отменяет подтверждение.

Изменены [polling.test.js](../../frontend/tests/polling.test.js) и [conversation.test.js](../../frontend/tests/conversation.test.js). `npm test`: **14 PASS**, включая четыре SSR-проверки A14. Целевой ESLint ConversationShow, conversation/polling и четырёх тестовых файлов — PASS.

## Браузерные проверки — PASS

Новый [conversation-sync.spec.js](../../frontend/e2e/conversation-sync.spec.js): **5 PASS, 8,1 с, 2 workers, без retries**.

| Сценарий | Подтверждение |
| --- | --- |
| Ошибка read, повтор, сообщение после отправки read | Badge сохраняется до ответа; позднее сообщение не входит в старую границу; следующая порция подтверждается отдельно; пустой polling не повторяет успешный read |
| GET завершается в скрытой вкладке | История появляется, read отсутствует; focus/online и полный интервал 5,5 с не создают запросов; возврат видимости запускает read |
| Смена диалога при незавершённом show | Запрос отменён; новый экран проходит loading, старые сообщения/черновик/ошибка не переносятся; поздний ответ игнорируется |
| Смена диалога при незавершённом read | Запрос отменён; ответ старого диалога не меняет новый экран и не отправляет дополнительный MESSAGES_CHANGED |
| Ошибка polling и восстановление | История сохраняется; ошибка исчезает; серверный read_by_other_through_id переводит своё сообщение в «Прочитано» без повторного read |

Смена диалога выполняется внутри реального App/BrowserRouter через history.pushState + popstate без перезагрузки документа. Скрытая вкладка моделируется изменением document.visibilityState и событием visibilitychange; это проверка логики приложения, а не системного переключения окон. Задержанные ответы управляются тестом. Отмена клиентского read **не означает отмену записи на сервере**: фикстура специально выполняет её до задержки ответа, проверяя только отсутствие позднего обновления UI/event.

Существующий сценарий `history loads in pages and a failed read is retried without clearing unread count` из [messaging.spec.js](../../frontend/e2e/messaging.spec.js) после A15.1: **1 PASS, 13,4 с (сам сценарий 12 с), без retries**. Проверены загрузка истории из 120 сообщений порциями, повтор read после 503 с сохранением badge, получение ещё 61 сообщения несколькими порциями и отсутствие дублей на границах.

API во всех сценариях подменяется фикстурами. Интеграция с Laravel/PostgreSQL не выполнялась. Полный набор теперь содержит **15 сценариев в трёх файлах**, discovery PASS; целиком он ещё не запускался — это A16. Остальные три сценария messaging.spec.js в A15 не выполнялись.

## A15.1 — слишком широкий перехват API

Исходный history/read-сценарий падал до загрузки приложения. Trace подтвердил 404 для `/src/api/client.js`, auth.js, advertisements.js и admin.js: glob `**/api/**` в старом messaging.spec.js перехватывал также JavaScript-модули Vite. В A14 аналогичная ошибка была исправлена только в новом файле списка.

Одна строка заменена на предикат `url.pathname.startsWith('/api/')`. Целевой сценарий: FAIL до исправления → PASS после. Фикстуры и проверки сценария сохранены. Это дефект тестового окружения, не приложения.

## Команды и материалы

Среда: Node 24.15.0, Playwright 1.56.1, Chromium Headless Shell 141.0.7390.37/v1194 из `/tmp/family-education-playwright-browsers`. Системные настройки не менялись; для webServer использованы точные NO_PROXY/no_proxy из A05.

Из каталога frontend:

```bash
npm test
npx --no-install eslint src/pages/ConversationShow.jsx src/lib/conversation.js \
  src/lib/polling.js tests/conversation.test.js tests/polling.test.js \
  e2e/conversation-sync.spec.js e2e/messaging.spec.js

PLAYWRIGHT_BROWSERS_PATH=/tmp/family-education-playwright-browsers \
NO_PROXY=localhost,127.0.0.1,::1 no_proxy=localhost,127.0.0.1,::1 \
PLAYWRIGHT_HTML_OUTPUT_DIR=/tmp/family-education-a15-sync-report PLAYWRIGHT_HTML_OPEN=never \
npx --no-install playwright test e2e/conversation-sync.spec.js --reporter=list,html \
  --output=/tmp/family-education-a15-sync-results

PLAYWRIGHT_BROWSERS_PATH=/tmp/family-education-playwright-browsers \
NO_PROXY=localhost,127.0.0.1,::1 no_proxy=localhost,127.0.0.1,::1 \
PLAYWRIGHT_HTML_OUTPUT_DIR=/tmp/family-education-a15-history-report PLAYWRIGHT_HTML_OPEN=never \
npx --no-install playwright test e2e/messaging.spec.js -g 'history loads' --reporter=list,html \
  --output=/tmp/family-education-a15-history-results

npx --no-install playwright test --list
```

- Успешные HTML-отчёты: `/tmp/family-education-a15-sync-report/index.html` и `/tmp/family-education-a15-history-report/index.html`.
- `.last-run.json` в соответствующих каталогах `*-results`: status=passed, failedTests=[].
- FAIL до A15.1: `/tmp/family-education-a15-history-before-report/index.html`; trace в `/tmp/family-education-a15-history-before-results/`.
- Успешные trace не сохранялись согласно retain-on-failure; /tmp — временное хранилище.
- Первый ошибочный запуск npx из корня workspace использовал другую версию из npm cache и остановился на обнаружении тестов. Он не входит в результаты выше; следующие запуски выполнены проектной версией из frontend с --no-install. Manifests/lockfiles не изменены.

Snapshot `/tmp/family-education-a15-baseline.json` подтверждает сохранение HEAD и предыдущих изменений всех трёх репозиториев. Изменения A15: два unit-теста, одна строка messaging.spec.js, новый conversation-sync.spec.js, этот отчёт, журнал плана и корневая SESSION_CONTEXT.md. Приложение/конфиги/зависимости не менялись. `git diff --check`, пробелы новых файлов и локальные ссылки документации — PASS. Коммитов нет.

**Следующий пункт — A16: полный прогон 15 браузерных сценариев с фикстурами. Полные lint/build остаются A17.**
