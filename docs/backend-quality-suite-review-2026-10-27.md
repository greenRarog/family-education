# A18 — Pint, Larastan и полный PHPUnit SQLite

Дата: 27 октября 2026 года. **A18 и подпункты A18.1–A18.3 завершены, итог PASS.** После первоначального аудита с FAIL пользователь поручил продолжить работу; исправлены login-тесты, три замечания Larastan и девять PSR-4 несоответствий.

## Итоговые проверки

| Проверка | Результат | Код выхода | Артефакт в /tmp |
| --- | --- | ---: | --- |
| Полный Pint `--test` | PASS | 0 | family-education-a18-fixes-pint.log |
| Полный Larastan, проектный phpstan.neon, level 5, app | PASS, 0 ошибок | 0 | family-education-a18-fixes-larastan.log |
| Полный PHPUnit SQLite | **247 PASS, 2045 утверждений**, errors/failures/skipped=0 | 0 | family-education-a18-fixes-phpunit.log и .xml |
| AuthenticationTest отдельно | 13 PASS, 71 утверждение | 0 | family-education-a18-fixes-auth.log и .xml |
| Composer optimized autoload, strict PSR | PASS, без PSR-4 предупреждений | 0 | family-education-a18-fixes-autoload.log |

Полный PHPUnit: 9,761 с по runner, 9,725 с по JUnit. Сопоставление JUnit до/после с учётом изменённых namespace подтвердило сохранение всех прежних 245 случаев; добавлены только две проверки Turnstile. Никакие тесты не исключены. Pint проверял форматирование без массовой перезаписи файлов; уровень и настройки Larastan не менялись, подавлений/baseline не добавлено.

Среда: PHP 8.4.27, Pint 1.30.5, PHPStan 2.2.8, Larastan 3.10.0, PHPUnit 12.5.33. Использованы установленные зависимости; Composer install/update не выполнялись.

## A18.1 — login-тесты, закрыто

В [AuthenticationTest](../tests/Feature/AuthenticationTest.php) успешный вход и отказ по неверному паролю теперь передают токен и подменяют успешный TurnstileService::verify с проверкой аргумента и единственного вызова. Отрицательный тест проверяет ошибку email/credentials и отсутствие ошибки cf-turnstile-response: он больше не может пройти только потому, что запрос раньше отклонил Turnstile.

Добавлены два сценария с корректными credentials:

- токен отсутствует: 422 по cf-turnstile-response, verify не вызывается, пользователь остаётся гостем;
- verify возвращает false: 422 по cf-turnstile-response, пользователь остаётся гостем.

Middleware и login pipeline приложения не менялись. Проверка Cloudflare подменяется сервисным mock; реальное внешнее API не вызывалось. Это проверка маршрута/сессии и разделения причин отказа, не работоспособности Cloudflare.

## A18.2 — статическая типизация, закрыто

В [Conversation](../app/Models/Conversation.php) для messages() добавлен корректный `@return HasMany<Message, $this>`. Анализатор теперь сохраняет конкретный тип Message при first()/last(); обращения к id в ConversationController больше не дают property.notFound. Runtime связи не менялся.

В [PublicAdvertisementResource](../app/Http/Resources/Api/PublicAdvertisementResource.php) для города объявления убран избыточный nullsafe: `city->only(...)`. Проверены основание и область применения:

- city_id объявления — NOT NULL с внешним ключом и restrictOnDelete;
- City не использует SoftDeletes или global scopes;
- публичные endpoint сериализуют сохранённые объявления и загружают city;
- PHPDoc Advertisement объявляет City как не-null.

Nullable district/metroStation и city публичного профиля педагога сохранены. Контракт публичного JSON не изменён для корректных записей БД; соответствующие существующие тесты входят в полный успешный suite. Приведения типов и игнорирование ошибок не использовались.

## A18.3 — PSR-4, закрыто

Namespace приведены к существующим путям, без перемещения файлов и изменения тел тестов:

| Файл под tests/Feature | Исправление namespace |
| --- | --- |
| BlockedTermControllerTest.php | Tests\Feature\Admin → Tests\Feature |
| DistrictControllerTest.php | Tests\Feature\Admin → Tests\Feature |
| MetroStationControllerTest.php | Tests\Feature\Admin → Tests\Feature |
| SubjectControllerTest.php | Tests\Feature\Admin → Tests\Feature |
| Listeners/SendAdvertisementExpiredNotificationTest.php | Feature\Listeners → Tests\Feature\Listeners |
| Listeners/SendNewMessageNotificationTest.php | Feature\Listeners → Tests\Feature\Listeners |
| Listeners/SendNewResponseNotificationTest.php | Feature\Listeners → Tests\Feature\Listeners |
| Notifications/NotificationQueueTest.php | Feature\Notifications → Tests\Feature\Notifications |
| UserTest.php | Feature → Tests\Feature |

`composer dump-autoload --optimize --strict-psr --no-scripts --no-interaction` прошёл без предупреждений, создан classmap из 8993 классов. Проверено соответствие namespace/path всех классов tests; прежние 33 случая этих девяти классов сохранились и проходят в полном suite. Composer hooks не запускались, manifests/lock не изменены. Карта исправлений: `/tmp/family-education-a18-fixes-psr4.json`.

## Первоначальный FAIL и диагностика

Исторические результаты первого A18 сохранены:

- Pint PASS: `/tmp/family-education-a18-pint.log`.
- Larastan FAIL: `/tmp/family-education-a18-larastan.log` — ConversationController:84/85 property.notFound (Model::$id), PublicAdvertisementResource:32 nullsafe.neverNull (City).
- PHPUnit: 244 PASS/1 FAIL, 245 тестов, 2025 утверждений; `/tmp/family-education-a18-phpunit.log` и `.xml`. test_user_can_login не передавал токен, поэтому middleware отклонял запрос до проверки пароля. Изолированный FAIL: `/tmp/family-education-a18-login.log` и `.xml`.
- Девять PSR-4 несоответствий: `/tmp/family-education-a18-psr4.json`; все девять классов уже обнаруживались directory discovery и давали 33 PASS. Исправлено соответствие autoload, а не восстановлены пропущенные тесты.
- Ошибочный первоначальный CLI-вызов с неподдерживаемым --cache-result-file не выполнял тесты. Он сохранён в `/tmp/family-education-a18-phpunit-cli-error.log`; корректные запуски используют --do-not-cache-result.

## SQLite и воспроизведение

Проектный phpunit.xml принудительно задаёт APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, пустой DB_URL, cache/mail=array, queue=sync. APP_CONFIG_CACHE указывает на отсутствующий временный путь, чтобы исключить старый config cache без изменения проекта.

Bootstrap с параметрами phpunit.xml ранее подтвердил environment=testing, config_cached=false, **PDO driver=sqlite**, database=:memory:; `/tmp/family-education-a18-runtime.json`. Настройки с тех пор не менялись. Рабочая/stage БД не использовалась. pdo_pgsql/pgsql/redis в текущем PHP CLI отсутствуют — учесть для A19.

Команды из backend:

```bash
composer dump-autoload --optimize --strict-psr --no-scripts --no-interaction
php vendor/bin/pint --test --format=json

APP_ENV=testing APP_CONFIG_CACHE=/tmp/family-education-a18-no-config-cache.php \
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
MAIL_MAILER=array BROADCAST_CONNECTION=null \
php vendor/bin/phpstan analyse --memory-limit=512M --no-progress --error-format=json

APP_CONFIG_CACHE=/tmp/family-education-a18-no-config-cache.php \
php vendor/bin/phpunit --configuration=phpunit.xml --colors=never \
  --log-junit=/tmp/family-education-a18-fixes-phpunit.xml --do-not-cache-result
```

Артефакты /tmp временные; результаты сохранены здесь и в журнале. PostgreSQL, реальный Cloudflare, внешняя доставка SMTP/Telegram, GitHub Actions и деплой в этом повторе не проверялись.

Snapshot `/tmp/family-education-a18-fixes-baseline.json` подтверждает сохранение HEAD и прежних изменений. В backend изменены два файла приложения (по одной строке), AuthenticationTest, namespace девяти тестов, этот отчёт и журнал; обновлён SESSION_CONTEXT.md. Frontend/infrastructure не менялись, зависимости и конфиги сохранены. `git diff --check`, пробелы и ссылки документации — PASS. Коммитов нет.

**Следующий пункт — A19. A18.1–A18.3 закрыты; A17.1 с правами frontend/dist остаётся открытым.**
