# Family Education — backend

Laravel API платформы семейного образования: семьи и педагоги публикуют
объявления, отвечают на них и общаются в личных диалогах. Frontend — отдельная
React SPA. Языки интерфейса и уведомлений: RU/EN/ES.

Состояние документации: 31 октября 2026. [План и журнал работ](docs/refactoring-plan-2026-10-26.md),
[перенос на другую машину](docs/machine-migration-2026-10-28.md),
[инфраструктура и запуск](../infrastructure/README.md).

## Что реализовано

| Область | Текущее поведение |
| --- | --- |
| Аккаунты | Регистрация семьи/педагога, вход/выход, восстановление пароля, подтверждение email; session cookies и CSRF; Turnstile при регистрации и входе |
| Семьи | Приватный профиль, дети (будущая дата рождения запрещена), город/район/метро, настройки уведомлений |
| Педагоги | Профиль с предметами, географией, текстовыми сведениями об образовании и о себе; запрос публичности и одобрение/отклонение администратором |
| Объявления | `family_to_family`, `family_to_teacher`, `teacher_to_service`; черновик, редактирование, публикация, закрытие, фильтры и пагинация |
| Переписка | Один отклик на объявление от пользователя, один диалог на отклик, принятие/завершение, порционная история, unread/read receipts |
| Справочники и администрирование | Города, районы, метро, предметы, запрещённые слова; рассмотрение публичности профиля педагога |
| Уведомления | События нового отклика/сообщения/истечения объявления, email-шаблоны; код Telegram-канала и настройки пользователя |

Педагогический модуль уже есть в коде и покрыт
[TeacherWorkflowTest](tests/Feature/TeacherWorkflowTest.php). Модерация и жалобы реализованы; [правила и API](#модерация).

Публичные feed/show объявлений используют
[PublicAdvertisementResource](app/Http/Resources/Api/PublicAdvertisementResource.php):
персональные данные детей не включаются. Редактирование владельца получает
приватные поля отдельным запросом. Профиль семьи приватный; публичный профиль
педагога доступен постороннему после запроса владельца и одобрения администратором.

После принятия отклика переписываться могут оба участника. Завершение отклика
(`accepted → rejected`) запрещает новые сообщения обоим, сохраняя историю.
Само объявление при этом остаётся опубликованным. Ежедневная команда
`advertisements:close-expired` закрывает истёкшие объявления через scheduler.

Email-шаблоны и обработчики проверены тестами, но реальная доставка через SMTP
не подтверждена A18–A22. Шаблон env использует `MAIL_MAILER=log`. Для Telegram
нужны bot token, рабочий proxy, chat ID и разрешение пользователя; live-доставка
также не подтверждена. Интеграция MAX не реализована.

## Репозитории и запуск

```text
family-education/
├── backend/
├── frontend/
└── infrastructure/
```

Это три Git-репозитория; корень workspace не является репозиторием. Рабочая
ветка — `main`. Правила env, volumes и обычной local-среды находятся в
[infrastructure README](../infrastructure/README.md). Local доступен через
`http://localhost:8081`; backend/auth должны обслуживаться с того же origin,
что и frontend.

Проверенный отдельный стенд A21 для настоящего API не требует реальных `.env`.
Из корня workspace:

```bash
./infrastructure/bin/e2e build
./infrastructure/bin/e2e start
./infrastructure/bin/e2e check
```

Адрес — `http://127.0.0.1:18081`. `check` требует Python 3; остальное использует
Docker. Команды `start` и `reset` восстанавливают тестовые фикстуры, `stop`
уничтожает временную БД. [Аккаунты и границы стенда](../infrastructure/environments/e2e/README.md).
Не использовать его для хранения рабочих данных.

Для обычной local-среды после создания env и установки зависимостей команды
Artisan выполняются через `./infrastructure/bin/artisan`. `key:generate`
нужен только новой установке без APP_KEY; восстановленным данным требуется
прежний ключ. [Общий DatabaseSeeder](database/seeders/DatabaseSeeder.php)
создаёт демонстрационные данные и администратора с известным паролем; он не
является способом настройки рабочего администратора. E2E использует свой seed.

## Проверки backend

Команды ниже выполняются из `backend/` в среде разработки с PHP 8.4,
Composer 2 и расширениями для выбранной БД (`pdo_sqlite`/`pdo_pgsql`).
Docker/CI проекта используют PHP 8.4; необходимые инструменты определены
[composer.json](composer.json) и lock-файлом.

```bash
composer install --no-interaction --prefer-dist
composer lint
composer analyse
composer test
```

`composer lint` — Pint без изменения файлов; `composer analyse` — Larastan;
`composer test` очищает config cache и запускает тесты с SQLite `:memory:`.
`composer check` последовательно выполняет все три проверки. `composer lint:fix`
изменяет форматирование. Для запуска отдельных тестов, например:

```bash
php artisan test --filter=TeacherWorkflowTest
```

Полный набор в свежей тестовой БД:

```bash
php -d memory_limit=512M vendor/bin/phpunit
php -d memory_limit=512M vendor/bin/phpunit -c phpunit.pgsql.xml
```

PostgreSQL — отдельная `family_education_test`; настройки в
[CI](.github/workflows/ci.yml). SQLite пропускает PostgreSQL-проверки гонок/TRUNCATE.

## Результаты проверок

**Этап B реализован, локальная приёмка PASS.** [Краткий состав работ и последние
результаты backend/frontend](docs/refactoring-plan-2026-10-26.md#b-защита-действий-и-небольшой-рефакторинг--реализован).
Действующие роли, численные лимиты и email-контракты — в
[правилах действий](docs/action-rules.md). Backend/frontend выпускать согласованно.

[Этап A закрыт](docs/stages-0-1-acceptance-2026-10-29.md) по решению пользователя;
A06.1 исключён из приёмки без подтверждённого Actions checkout.
Локальные результаты не подтверждают GitHub CI, SMTP-доставку или deployment.
Материалы изолированных прогонов — `/tmp/family-education-b*/`; перед повтором
сверять копию с текущими файлами и создавать свежую тестовую БД.

## CI и deployment

[Backend CI](.github/workflows/ci.yml) запускается на push, pull request и
вручную: Composer install, Pint/Larastan, SQLite, миграции и PostgreSQL suite.
Используются PHP 8.4, PostgreSQL 17 и Redis 8. **Deploy job в текущем workflow нет.**
Старые [scripts/deploy.sh](scripts/deploy.sh) и
[docker-compose.stage.yml](docker-compose.stage.yml) остаются legacy-файлами;
они не управляют актуальным стеком трёх репозиториев и не вызываются CI.

Актуальный механизм stage — ручной `infrastructure/bin/stage`: сборка/запуск,
миграции с `--force` и `artisan optimize`. [Порядок подготовки](../infrastructure/README.md)
включает env, сертификаты и проверку данных. A20 подтвердил сборку stage images,
но не запуск на VPS, TLS, backup/restore или откат. Автоматический deployment
и проверка эксплуатационных процедур остаются отдельными задачами.

## Дальнейшая работа

Источник текущих задач — [план A–F](docs/refactoring-plan-2026-10-26.md),
а не прежние отметки roadmap. B/C завершены; следующий шаг D01 — карта
зависимостей аккаунта. Далее — политика удаления, доставка уведомлений,
backup/restore, релиз/откат и мониторинг; каждый шаг требует поручения.

Приложение развивается как монолит с областями Identity, Family, Teacher,
Geography, Catalog, Advertisement, Response, Messaging, Notification и Moderation.
Платежи, подписки, групповые чаты, расписания, рейтинги и автоматический подбор
не входят в текущую реализацию. Перед изменением UI читать [правила дизайна](docs/design.md).

## Модерация

C завершён: [действующие правила](docs/moderation-rules-c01.md),
[итоговая приёмка и архив](docs/refactoring-plan-2026-10-26.md#stage-c).
Блокировки и скрытия независимы; сохраняются сессии, история и прежние статусы.
Решение жалобы, санкции и журнал атомарны. Пересмотр добавляет новую запись;
старые решения неизменяемы. Приватные профили/дети/чужие сообщения не открываются.

### API

Session/CSRF по общим правилам. Admin GET разрешён без verification,
изменения требуют verified admin; гость — 401, другая роль — 403.

| Маршрут | Назначение |
| --- | --- |
| POST `/api/reports` | Подача жалобы активной verified семьёй/педагогом |
| GET `/api/admin/users`, `/{id}`, `/{id}/history` | Список, карточка и история; фильтр `state=all\|active\|blocked` |
| POST `/api/admin/users/{id}/block`, `/unblock`, `/appeal-rejected` | Блокировка, отмена, результат ручного пересмотра |
| GET `/api/admin/advertisements`, `/{id}`, `/{id}/history` | Фильтры `state=all\|hidden\|visible`, `status=all\|draft\|published\|closed` |
| POST `/api/admin/advertisements/{id}/hide`, `/restore`, `/appeal-rejected` | Независимое скрытие/восстановление/пересмотр |
| GET `/api/admin/reports`, `/{id}`, `/{id}/history` | Фильтры `status=all\|open\|resolved\|dismissed`, `target_type=all\|advertisement\|user`; по умолчанию open/all |
| POST `/api/admin/reports/{id}/resolve` | Решение с санкциями или ссылками на действующие меры |
| GET `/api/admin/dashboard` | `blocked_users`, `hidden_advertisements`, `open_reports`; текущие целые числа без прикладного кеша |

Списки/история: `page`, 20 записей, новые ID первыми. История находится в карточках.
`visible` означает отсутствие явного скрытия, а не гарантию публичности.

Жалоба: `target_type=advertisement|user`,
`context_type=advertisement|teacher_profile|conversation`, `context_id`,
`reason`, необязательный `comment` до 2000 символов (other — от 10 после trim).
Цель/снимок определяет сервер. Лимит 3/60 + 10/86400; успех 201 `report.id/status=open`,
открытый дубль — 422 `report_already_open`, недоступная цель — 404,
неверные поля/своя цель — 422; 429 с `Retry-After/retry_after`.

Санкция: `reason_code` из правил и `reason` 10–1000 символов после trim.
Отмена: непустой `reason` до 1000, `reason_code=restriction_removed|review`.
Необязательный `decision_id` защищает от устаревшей карточки (409).
Ответ: объект, `changed`, `action_id`; повтор достигнутого состояния не меняет
причину/журнал. Unblock также возвращает `expired_advertisements_count`.
Appeal-rejected требует действующий `decision_id` и непустой `reason`;
повтор исполнителя/решения/пояснения не дублируется, устаревшее решение — 409.

Решение жалобы: `status=resolved|dismissed`, непустой `resolution_note`.
Для resolved: `actions` (`block_user` и/или `hide_advertisement`) с отдельными
`sanction_reason_code/sanction_reason`, либо `existing_action_ids` действующих мер.
Скрытие доступно только для жалобы на объявление; dismissed не принимает меры.
200 содержит актуальный `report`; закрытая жалоба/снятая связанная мера — 409.
Внутреннее пояснение не подставляется в публичную причину санкции.

### Хранение и дальнейшие изменения

`users.block_action_id` и `advertisements.hide_action_id` ссылаются на текущие
неизменяемые решения. `reports` сохраняет контекст/снимок после исчезновения
цели; частичный уникальный индекс защищает один открытый дубль.
`report_resolution_actions` связывает решение с мерами. FK RESTRICT защищают
исполнителей/заявителей/решения; удаление и анонимизация требуют политики D.
`AdminActionLogger::record()` вызывается внутри той же транзакции, что переход;
ошибка откатывает всё. Записи сериализованы блокировками user → advertisement,
решение жалобы сначала блокирует report. Новые защищаемые бизнес-маршруты
должны явно применять актуальную модерацию. События/уведомления — после commit;
очередь повторно проверяет санкции, отменённое после восстановления не досылается.
Откаты миграций допустимы только с планом сохранения данных и учётом FK;
не использовать их как рабочую операцию отмены санкций.

Целевой сквозной тест: `php -d memory_limit=512M vendor/bin/phpunit --filter=ModerationLifecycleTest`;
для PostgreSQL добавить `-c phpunit.pgsql.xml`. Очередь:
`--filter='ModerationDelivery|HiddenAdvertisementDelivery'`.
