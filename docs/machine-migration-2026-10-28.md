# Перенос и продолжение разработки

Актуально: 29 октября 2026. Этап A закрыт по решению пользователя с исключением
неподтверждённого Actions checkout A06.1. [Итоги A](stages-0-1-acceptance-2026-10-29.md),
[план B–F](refactoring-plan-2026-10-26.md). Текущий следующий пункт и статус — в плане.

## Что переносить

Три самостоятельных репозитория — `backend`, `frontend`, `infrastructure`,
все на ветке main. Последние локальные коммиты:

| Репозиторий | HEAD |
| --- | --- |
| backend | `8eefc29805c3c54caff8ff07874f831cceb8af4e` |
| frontend | `abaad10b289acb96aeea9128c386891b6b5a9944` |
| infrastructure | `52cc5d7fb50e273bb77cf15063678b7ee0fbd982` |

Поверх них есть незакоммиченные E2E-инструменты, тесты и документация:
перенос только коммитов их потеряет. Сохранить текущие рабочие деревья вместе
с `.git` либо отдельно перенести tracked diff и untracked-файлы. Не заменять
их старыми bundle. Публикация HEAD на GitHub не подтверждена: прежний push
не прошёл из-за DNS; перед remote-клонированием проверить доступность нужных SHA.

`migration-2026-10-28/` содержит bundle только указанных HEAD. Для восстановления
этого снимка после проверки `sha256sum -c SHA256SUMS` в каталоге архива:

```bash
mkdir family-education
cd family-education
git clone --branch main ../migration-2026-10-28/backend.bundle backend
git clone --branch main ../migration-2026-10-28/frontend.bundle frontend
git clone --branch main ../migration-2026-10-28/infrastructure.bundle infrastructure
```

Затем перенести последующие рабочие изменения. Исходные origin:
`git@github.com:greenRarog/family-education.git`,
`git@github.com:greenRarog/family-education-frontend.git`,
`git@github.com:greenRarog/family-education-infrastructure.git`.

## Окружение и следующий шаг

Команды зависимостей и запуска — в README [backend](../README.md),
[frontend](../../frontend/README.md), [infrastructure](../../infrastructure/README.md).
Для повторения тестового сценария — [E2E](../../infrastructure/environments/e2e/README.md).
Стенд использует временную БД; start/reset удаляют его данные.

Git не переносит реальные env/секреты, БД/volumes, uploads, vendor/node_modules,
dist, браузеры и `/tmp`. Зависимости восстанавливать из lock. Нужные рабочие
данные переносить отдельно с прежним APP_KEY; bundle не является их backup.
SESSION_CONTEXT.md находится вне репозиториев; он необязателен для переноса.

Новый исполнитель читает backend/AGENTS.md и правила плана, сверяет status/HEAD
трёх репозиториев и сохраняет рабочие изменения. Следующий ID из плана начать
после подтверждения; после каждого ID снова спрашивать о следующем. Commit/push,
GitHub-доступы и VPS автоматически не выполнять.
