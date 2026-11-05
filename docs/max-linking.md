# MAX: подключение и уведомления

Сценарий согласован 5 ноября 2026: как Telegram, для семьи и педагога.
У владельца проекта есть ИП; профиль партнёра и бот ещё не созданы.
Код проверяется локально с подменённым Bot API; настоящее подключение — V05.
Вход через MAX и мини-приложение в этот объём не входят.

## Пользовательский сценарий

1. В своём профиле нажать «Подключить MAX», открыть одноразовую ссылку бота.
2. Получить 8-значный код и ввести на сайте в той же сессии браузера.
3. Отдельно включить уведомления MAX и сохранить настройки.
4. Для отключения — «Отключить MAX» и подтверждение на сайте; уведомления
   выключаются, старые задания и попытки не оживают при повторной привязке.

Попытка живёт 10 минут. Ссылка содержит случайный nonce; в БД только его hash,
hash кода и привязка к сессии/её версии. 5 неверных кодов отменяют попытку.
Создание: 5/минуту и 20/сутки на аккаунт; confirm/unlink — по 5/минуту.
Один MAX user ID принадлежит максимум одному аккаунту сайта. Блокировка и
неподтверждённый email не запрещают управление своим каналом; admin его не подключает.
Смена пароля отменяет незавершённые попытки через session version.

## API и конфигурация

| Маршрут | Поведение |
| --- | --- |
| GET `/api/max-link` | `available`, `connected`, `needs_reconnect`, `binding_version` |
| POST `/api/max-link/attempts` | `attempt_id`, одноразовый `url`, `expires_at` |
| POST `/api/max-link/confirm` | `attempt_id`, `code`; подтверждение владения |
| DELETE `/api/max-link` | Отзыв привязки и opt-in |
| POST `/api/integrations/max/webhook` | Только события провайдера с секретным заголовком |

Первые четыре маршрута требуют session auth, изменения — CSRF. UI передаёт
`X-Expected-Account-Id`; чужая текущая сессия даёт 409. Ответы no-store/private.
Webhook не требует browser cookie/CSRF, проверяет `X-Max-Bot-Api-Secret`,
лимит тела 64 KiB и тип `bot_started`; неизвестные/повторные события игнорируются.

```dotenv
MAX_ENABLED=false
MAX_BOT_ID=
MAX_BOT_USERNAME=
MAX_BOT_TOKEN=
MAX_WEBHOOK_SECRET=
MAX_CA_BUNDLE=
```

Секрет webhook — минимум 32 случайных символа. Bot ID/username берутся из
`GET /me`; перед отправкой приложение сверяет их с конфигурацией. API:
`https://platform-api2.max.ru`, token только в заголовке `Authorization`.
Тексты отправляются как plain text, ссылки добавляются отдельно, длинный текст
разбивается. Ошибки не содержат token/headers/текст; APM/request tracing также
должны исключать эти данные и коды подтверждения.

На VPS создать/верифицировать профиль ИП, создать бота и настроить HTTPS webhook
через `POST /subscriptions` с `update_types=["bot_started"]` и тем же `secret`.
Секреты хранить в закрытом runtime env. Если окружению нужен дополнительный CA,
`MAX_CA_BUNDLE` указывает на проверенный PEM; TLS verification не отключается.
В release target optional `max_ca_bundle` — абсолютный host path, runtime путь
строго `/run/certs/max-ca.pem`. Файл монтируется read-only во все PHP-процессы.
MAX не требует отдельного poller; HTTP принимает webhook, queue отправляет уведомления.

Код отправляется один раз после погашения nonce. Если провайдер потерял ответ,
нужна новая попытка на сайте. Повтор webhook не генерирует новый код.
У обычных queue notifications возможен повтор доставки после потери ответа;
версия привязки, согласие и текущие права проверяются перед отправкой.

## Хранение и проверки

Удаление аккаунта очищает привязку и попытки; `max:prune` запускается ежечасно.
После restore все MAX-привязки отзываются в общей
[сверке восстановления](account-recovery.md). Отдельная команда
`max:invalidate-restored-links --force` требует выключенного MAX и закрытой цели,
сама HTTP/workers не останавливает и не заменяет общую сверку.

Локальные тесты: `MaxLinkTest`, `MaxConcurrencyTest` (PostgreSQL),
`MaxQueueDeliveryTest` (database/Redis), `e2e/max-link.spec.js` (RU/EN/ES/mobile),
`e2e-lifecycle/max-link.spec.js` (настоящий Laravel/PostgreSQL, тестовый провайдер).
На VPS дополнительно проверить реальную подписку, код, доставку через queue,
отказ провайдера и unlink; отсутствие бота не считать живой приёмкой.

Официальные источники, проверенные для реализации:
[создание бота](https://dev.max.ru/docs/chatbots/bots-create/create),
[deep links](https://dev.max.ru/docs/chatbots/bots-coding/prepare/),
[подписки webhook](https://dev.max.ru/docs-api/methods/POST/subscriptions),
[отправка сообщения](https://dev.max.ru/docs-api/methods/POST/messages).
