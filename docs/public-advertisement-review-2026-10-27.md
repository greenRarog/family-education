# A07 — публичный JSON объявлений и приватный edit

Дата: 27 октября 2026 года. **A07 выполнен, PASS: 8 тестов, 290 утверждений.** Проверка выполнена настоящим проектным PHPUnit на SQLite `:memory:`. Следующий пункт [плана](refactoring-plan-2026-10-26.md) — A08, возрастной фильтр.

## Результат

Существующий `PublicAdvertisementResource` ограничивает набор публичных полей. Feed/show используют этот Resource; сведения детей и связи pivot не входят в ответ. В приватном edit сохраняется загрузка детей, но маршрут требует авторизацию, а контроллер проверяет владельца.

Исходный privacy-тест прошёл без исправлений приложения: 1 тест, 57 утверждений. Его покрытие расширено в [PublicAdvertisementTest](../tests/Feature/PublicAdvertisementTest.php):

- Оба типа семейных объявлений: `family_to_family` и `family_to_teacher`.
- Feed/show для гостя, постороннего пользователя и владельца: ожидаемый публичный набор ключей, отсутствие `children`, `child_ids`, `child_id`, `user_id`, `family_id`, `birth_date`, `sex`, `pivot` во всём JSON; имя и дата рождения тестового ребёнка также отсутствуют.
- Заполненные city/district/metro_station и subjects содержат только `id`/`name`, без служебных полей и pivot. Для семейного объявления `public_teacher=null`.
- Show корректно различает гостя, постороннего и владельца через `viewer.authenticated`/`viewer.is_owner`.
- Edit для обоих типов и статусов draft/published: гость получает 401, посторонний — 404, оба без объекта advertisement. Владелец получает 200 и ровно выбранного ребёнка с id, именем и датой рождения; второй ребёнок той же семьи, не привязанный к объявлению, не включается.

Два существующих теста из `AdvertisementTest` дополнительно подтверждают сохранение изменений владельцем (включая смену выбранного ребёнка и предмета в БД) и отказ постороннему в PUT с 403. GET edit и PUT используют разные ожидаемые коды отказа: 404 и 403 соответственно; поведение не менялось.

Контроллер, Resource, маршруты, модели и фабрики в A07 не изменялись. Тест возрастного фильтра в том же файле сохранён без изменений и в этот прогон не включён.

## Команды и результаты

Из каталога backend:

```bash
php artisan config:clear
vendor/bin/phpunit \
  --filter 'test_public_feed_and_detail_never_expose_children_even_to_the_owner|test_only_the_owner_can_load_children_for_editing|test_family_can_update_own_draft_advertisement|test_user_cannot_edit_another_users_advertisement' \
  tests/Feature/PublicAdvertisementTest.php tests/Feature/AdvertisementTest.php \
  --log-junit /tmp/family-education-a07-phpunit.xml
vendor/bin/pint --test tests/Feature/PublicAdvertisementTest.php
```

| Проверка | Результат |
| --- | --- |
| Очистка config cache | PASS. |
| Privacy feed/show: два типа × три зрителя × два endpoint | PASS; два параметризованных тестовых случая. |
| Доступ к edit: два типа × два статуса × три зрителя | PASS; четыре параметризованных тестовых случая. |
| Сохранение владельцем и запрет чужого PUT | PASS; два существующих теста. |
| Итоговый PHPUnit | PASS: 8 тестов, 290 утверждений. |
| Проектный Pint для изменённого PHP-файла | PASS. |
| `git diff --check` трёх репозиториев, пробелы новых/изменённых файлов | PASS. |

PHP CLI: 8.4.27. Конфигурация `phpunit.xml` принудительно задаёт testing, SQLite `:memory:`, пустой DB_URL и array/sync-сервисы. RefreshDatabase выполняет тестовые миграции в памяти. Рабочая БД и PostgreSQL для этих проверок не используются.

JUnit сохранён в `/tmp/family-education-a07-phpunit.xml`, исходные HEAD/хеши — `/tmp/family-education-a07-baseline.json`. Сравнение с snapshot подтверждает сохранение прежних изменений приложения. Коммиты не создавались.

## Границы результата

Подтверждено отсутствие автоматической сериализации персональных полей детей в проверенных публичных API. Пользовательский текст description остаётся публичным: тест не является проверкой обнаружения персональных данных, вручную введённых в описание. Публичный профиль педагога, прочие API, браузерный интерфейс и полный backend suite в эту задачу не входят. Общие проверки SQLite/PostgreSQL остаются A18/A19.

**Следующий пункт — A08: семантика возрастного фильтра и подпись «Любой возраст».**
