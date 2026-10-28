# A14 — frontend списка диалогов и общего счётчика

Дата: 27 октября 2026 года. **A14 и подпункт A14.1 завершены, PASS.** После фактического включения полного доступа установлен проектный Chromium; шесть браузерных сценариев выполнены успешно. Исправлена неверная подпись пагинации при ошибке загрузки второй страницы.

## Контракт и проверенное поведение

Просмотрены Conversations, ConversationListItem, Layout, Header, API client, polling и message-events. До изменения интерфейса прочитан `docs/design.md`; существующее оформление сохранено. Экран самого диалога не менялся.

- Conversations запрашивает `/api/conversations?page=N`, читает `conversations.data` и `conversations.meta.last_page` по контракту A10. При смене страницы keyed-компонент останавливает прежний poller и отменяет запрос; обработчики проверяют signal.aborted.
- Карточка использует last_message.message, при null — описание объявления. История messages не нужна. Маркер и число непрочитанных берутся из has_unread_messages/unread_count.
- Layout отдельно опрашивает `/api/conversations/unread-count` после authenticated=true. Это сумма сообщений по всем страницам, а не число диалогов. Header скрывает ноль, показывает 99+ выше 99 и сохраняет полное число в aria-label.
- MESSAGES_CHANGED вызывает повторный запрос общего count. Список обновляется собственным polling/focus. Доставка события после реального read receipt экрана диалога относится к A15; здесь событие вызвано непосредственно через модуль приложения.
- При ошибке обновления сохраняются последние карточки и badge. Ошибка списка показана явно; первичная ошибка не выдаётся за успешный пустой список. После восстановления запросов данные обновляются.

## A14.1 — неверное число страниц при ошибке

При первичной ошибке загрузки второй страницы новый ConversationPage устанавливал lastPage=1 и показывал **«2 из 1»**, хотя метаданные ещё не получены. Регрессионный браузерный прогон: 5 PASS, 1 FAIL именно на этой подписи.

В [Conversations.jsx](../../frontend/src/pages/Conversations.jsx) неизвестное число страниц теперь хранится как null. Пока meta не получена, показан только номер текущей страницы; «Назад» доступна, «Далее» отключена. Успешный ответ восстанавливает фактическое «2 из 2». Изменены три строки, логика запросов и оформления сохранена. Тест проверяет ошибку, возврат назад и последующий успешный переход на вторую страницу.

## Проверки — PASS

[conversation-list-render.test.js](../../frontend/tests/conversation-list-render.test.js) добавляет четыре SSR-проверки реальных ConversationListItem/Header через renderToStaticMarkup и MemoryRouter. JSX загружается проектным Vite в middleware mode с `ws:false`:

1. Поля API, ссылка, экранирование preview, unread/статус, отсутствие полной истории.
2. Описание вместо null preview, отсутствие unread-маркеров у прочитанной карточки, подписи sent/rejected.
3. Badge для 0/1/99/120: скрытие нуля, ограничение 99+, полный доступный счётчик.
4. У гостя и во время загрузки прежний badge скрыт.

`npm test`: **11 тестов PASS** — четыре новых и семь существующих conversation/polling. Отдельные запуски этих трёх файлов также прошли. SSR не выполняет useEffect; эффекты проверены следующими браузерными сценариями.

[conversation-list.spec.js](../../frontend/e2e/conversation-list.spec.js): **6 сценариев PASS, 5,2 секунды, 2 workers, без retries**:

| Сценарий | Результат |
| --- | --- |
| 20 записей → 21-я на второй странице → возврат, без смешивания; общий badge 120/99+ | PASS |
| Обновление preview/unread; учёт unread другой страницы; MESSAGES_CHANGED скрывает нулевой badge | PASS |
| Ошибки 503 списка/счётчика сохраняют данные; повтор восстанавливает состояние | PASS |
| Первичная ошибка и ошибка второй страницы без ложного пустого состояния/«2 из 1»; возврат и успешный переход | PASS |
| Успешный пустой список без пагинации и badge | PASS |
| Гость не запрашивает авторизованный unread endpoint | PASS |

API перехватывается фикстурами формы A10/A12, исходная сумма unread — 120 на 21 диалог. **Это frontend-проверки с подменой API, не интеграция с Laravel.** Полный набор содержит десять сценариев; оставшиеся четыре здесь не запускались, полный прогон — A16.

Целевой ESLint компонентов списка/Layout/Header/message-events и двух новых тестовых файлов — PASS. `git diff --check` трёх репозиториев и проверка пробелов новых файлов — PASS. Полные lint/build остаются A17.

## Среда и история снятия блокера

Текущий успешный прогон: Node 24.15.0, Playwright 1.56.1, **Chromium Headless Shell 141.0.7390.37, build v1194**, установлен в `/tmp/family-education-playwright-browsers`. FFMPEG v1011 установлен там же. Локальный Chrome 156.0.8078.47 использован только для smoke доступности запуска; сценарии выполнены проектным Chromium. Node 22 в A14 не проверялся.

Ранее ограниченная среда запрещала подключение к proxy (`EPERM`), запуск Chrome (`setsockopt EPERM/SIGTRAP`) и создание snap runtime directory; запрос require_escalated отклоняла политика инструмента. Эти попытки сохранены в журнале как история. После применения danger-full-access обычный запуск браузера и установка прошли, **активного блокера A14 больше нет**.

Первый фактический browser-прогон дал 6 FAIL из-за ошибки нового теста: glob `**/api/**` перехватывал также Vite-модули `/src/api/*.js` и возвращал 404. Перехват ограничен pathname, начинающимся с `/api/`. После этого остался один воспроизводимый дефект приложения A14.1; после его исправления все шесть сценариев PASS.

## Команды и артефакты

Из frontend:

```bash
PLAYWRIGHT_BROWSERS_PATH=/tmp/family-education-playwright-browsers \
npx playwright install chromium --only-shell

npm test
npx eslint src/pages/Conversations.jsx src/components/ConversationListItem.jsx \
  src/layouts/Layout.jsx src/components/Header.jsx src/lib/message-events.js \
  tests/conversation-list-render.test.js e2e/conversation-list.spec.js

PLAYWRIGHT_BROWSERS_PATH=/tmp/family-education-playwright-browsers \
NO_PROXY=localhost,127.0.0.1,::1 no_proxy=localhost,127.0.0.1,::1 \
PLAYWRIGHT_HTML_OUTPUT_DIR=/tmp/family-education-a14-report PLAYWRIGHT_HTML_OPEN=never \
npx playwright test e2e/conversation-list.spec.js --reporter=list,html \
  --output=/tmp/family-education-a14-results
```

Точный 127.0.0.1 в proxy-исключениях требуется согласно A05; системные настройки не менялись. Для A16 использовать тот же PLAYWRIGHT_BROWSERS_PATH, если временный каталог сохранится, либо установить браузер заново.

- Итоговый HTML: `/tmp/family-education-a14-report/index.html`; результат: `/tmp/family-education-a14-results/.last-run.json`.
- Дефект A14.1 до исправления: `/tmp/family-education-a14-regression-report/index.html`, trace/error-context в `/tmp/family-education-a14-regression-results/`.
- Первый прогон с ошибкой перехвата: `/tmp/family-education-a14-initial-report/index.html`, шесть trace в `/tmp/family-education-a14-initial-results/`.
- Успешные trace не сохранялись: штатная настройка retain-on-failure. Артефакты в /tmp временные.

Snapshot `/tmp/family-education-a14-baseline.json` подтверждает: относительно начала A14 изменены только Conversations.jsx, два новых frontend-теста, этот отчёт, журнал плана и корневая SESSION_CONTEXT.md. HEAD всех трёх репозиториев прежние; зависимости, конфиги, lockfiles и предыдущие изменения сохранены. Коммитов нет.

**Следующий пункт — A15: обновление открытого диалога. Полные browser suite (A16), lint/build (A17) и интеграция с реальным backend не выполнены в A14.**
