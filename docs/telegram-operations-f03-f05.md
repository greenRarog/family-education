# Telegram: API, запуск и эксплуатация

[Пользовательские правила](telegram-linking-f02.md) реализованы в backend и UI.
[Результаты и ограничения проверок](refactoring-plan-2026-10-26.md#current-validation)
ведутся в плане. Реальная доставка проверяется на VPS (V05); локальные
транспорты были подменены.

## Протокол

| Browser API | Действие |
| --- | --- |
| GET `/api/telegram-link` | `available`, `connected`, `needs_reconnect`, `binding_version`; без chat ID, кода и ссылки |
| POST `/api/telegram-link/attempts` | `attempt_id`, HTTPS-ссылка бота, `expires_at` |
| POST `/api/telegram-link/confirm` | JSON `attempt_id` UUID и `code` строка из 8 цифр |
| DELETE `/api/telegram-link` | Отвязать, отменить попытки, выключить Telegram; идемпотентно |

Auth + CSRF; для каждого изменяющего запроса обязательный
`X-Expected-Account-Id`. Несовпадение/отсутствие — 409 до limiter. Только family
и teacher, также заблокированные и без email verification; удалённые — 401.
Ответы `no-store, private`. Ошибки: `telegram_invalid`, `telegram_expired`,
`telegram_attempt_cancelled` (422), `telegram_conflict`,
`telegram_already_connected`, `account_changed` (409), `telegram_unavailable`
(503), `rate_limited` (429 + Retry-After). Чужие данные не возвращаются.

Сроки и лимиты — в [правилах привязки](telegram-linking-f02.md#сроки-и-ограничения).
Проверки/счётчики сериализуются блокировкой пользователя; нужен общий cache.

32 случайных байта nonce передаются только в deep link; БД хранит SHA-256.
Код независимый, HMAC с APP_KEY и ID попытки; исходная сессия также HMAC,
плюс `session_version`. Браузер никогда не выбирает chat ID. Первый допустимый
private `/start` погашает nonce. Повтор update/другой кандидат код не заменяет.
Обычный `/start` без ссылки выдаёт инструкцию: максимум 1 ответ/мин на чат,
30/мин на бота. Иные сообщения, группы, edited/channel updates, неверные
ID отправителя и неизвестные/старые nonce игнорируются, не импортируются.

Подтверждение под user lock и unique constraint записывает chat ID, bot ID,
время и новую версию. Повтор успешного запроса своей сессии в пределах срока
возвращает тот же результат; после unlink/новой привязки не восстанавливает её.
Telegram-доставка остаётся выключенной, email сохраняется. Произвольное
массовое заполнение `telegram_chat_id` удалено. Старые ID без подтверждения
не используются; UI предлагает подключиться заново. Привязку можно удалить
при выключенном Telegram/неработающем провайдере.

## Конфигурация и получение updates

В **закрытом backend env выбранного тестового окружения**, одинаковом у app,
queue и poller:

```dotenv
TELEGRAM_ENABLED=false
TELEGRAM_BOT_USERNAME=
TELEGRAM_BOT_TOKEN=
TELEGRAM_PROXY_URL=http://telegram-proxy:10808
```

Username без `@`. Token — секрет из BotFather, не передавать в чат/CLI arguments,
не коммитить; bot ID определяется из числового префикса token и сверяется с getMe.
`TELEGRAM_ENABLED=true` включать только для выбранного тестового бота после
подготовки окружения. Для прямого исходящего доступа proxy можно оставить пустым;
пример proxy требует действующего VLESS-конфига, его наличие не доказывает доступ.
Приложение, poller и queue должны видеть одну БД, общий cache и одинаковый APP_KEY.
Изменения env/config cache/перезапуск — отдельная эксплуатационная операция.

Команды для **явно выбранного тестового окружения**, не команда запуска stage:

```bash
php artisan telegram:poll --once
php artisan telegram:poll
php artisan telegram:prune
```

Poller требует PostgreSQL (SQLite допущен только внутри testing), берёт session
advisory lock на bot ID; второй процесс отклоняется. Дополнительно ограниченная
пачка защищена cache lock. getMe проверяет ID/username; getWebhookInfo должен
вернуть пустой URL. Существующий webhook не удаляется, updates не сбрасываются.
getUpdates: 25 s, до 10 updates; HTTP timeout 35 s, отправка кода 5 s, без
автоматического повтора. После 24 часов без обработанных updates poller читает с offset=0: старый cursor
не блокирует случайные ID после простоя, непрочитанные updates не сбрасываются.
Cursor сохраняется после обработки, dedup — в той же
транзакции, что claim. При сбое отправки кода nonce уже погашен: пользователь
создаёт новую попытку. Повтор не генерирует другой код. Сбой CLI даёт обобщённую
ошибку без token/URL/payload; оператор повторяет запуск вручную.

Telegram webhook не используется. Локальный foreground-запуск:
`./infrastructure/bin/telegram-poller` из корня workspace. Release tooling
создаёт `telegram-poller` из того же app image/env, запускает его при
`TELEGRAM_ENABLED=true` и останавливает вместе с queue/scheduler.
Работоспособность и надзор на VPS проверяются в V05/V06.
Не запускать разработку на токене действующего бота: getUpdates потребляет его updates.

## Очередь, хранение, удаление и восстановление

Все три обычных уведомления фиксируют подтверждённую версию/bot ID при создании.
Перед provider handoff проверяются текущая версия, bot ID, подтверждение,
настройка доставки, удаление и прежние правила модерации. Legacy job без версии
для Telegram запрещён. Unlink/relink того же чата не оживляет старые задания.
`AccountChannelManager` держит упорядоченные user locks до окончания отправки;
unlink/delete используют тот же порядок. Уже переданное Telegram не отзывается.
Повтор queue job после потери ответа может дать дубликат; exactly-once не обещается.

Текст передаётся без Telegram markup, длинные сообщения разбиваются на части;
ошибка провайдера не сохраняет URL с token в failed_jobs exception. Внешние
request tracing/APM должны исключать Bot API body/headers/URL и поля browser
`code`/`attempt_id`; приложение не пишет их в обычные logs и не flash-ит ввод.
Сама очередь обычных уведомлений содержит payload сообщения;
[очистка database/Redis и восстановление](account-recovery.md) реализованы,
сроки и работа scheduler ещё требуют проверки на VPS.

Миграция `2026_11_03_120000_add_confirmed_telegram_links.php` добавляет поля
users, `telegram_link_attempts`, `telegram_processed_updates`, `telegram_polling_cursors`.
Откат миграций не является процедурой восстановления данных. Сервис удаления
в T0 очищает привязку и все попытки вместе с остальными персональными данными.
`telegram:prune` зарегистрирован ежечасно: удаляет попытки после 10 минут и dedup
ID после 30 дней. Это технический ID без исходного update; cursor не содержит
пользовательских данных. Допуск физической очистки — до 24 часов; работа scheduler
на stage не подтверждена. Backup/log retention остаётся 30 дней без продления копией.

На **закрытой цели restore**, до HTTP/queue/poller/scheduler, с
`TELEGRAM_ENABLED=false`, выполнить:

```bash
php artisan telegram:invalidate-restored-links --force
```

Команда очищает все привязки/попытки, выключает Telegram-настройку и повышает
версии. Пользователям потребуется подтверждение заново. Это сознательная замена
отсутствующему независимому реестру unlink, **не замена независимому реестру
удаления аккаунтов**. Реестр, session/reset/queue reconciliation и приёмка VPS
обязательны до открытия сервиса. Команда требует закрытой цели; сама трафик не закрывает.
Удаление аккаунта остаётся выключенным.

## Живая проверка на VPS

V05 проверяет Start/код/confirm, отдельный opt-in, событие через настоящую очередь,
получение Telegram и email, unlink/запоздавшее задание и отказ провайдера.
Для этого нужны:

- отдельный бот, username и token в закрытом env;
- тестовый аккаунт сайта и согласованный получатель, который проходит Start/код/opt-in;
- SMTP и согласованный email-получатель;
- выбранное окружение и работающий прямой или proxy-доступ к провайдерам.

Токены в чат не передавать. Настройка процессов на VPS — V05; результаты живой
проверки фиксируются один раз в плане. [MAX](max-linking.md) принимает webhook; живая проверка также входит в V05.
