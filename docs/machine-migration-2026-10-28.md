# Перенос и продолжение разработки

Актуально: 5 ноября 2026. [Статус и план](refactoring-plan-2026-10-26.md).
Три самостоятельных репозитория — backend, frontend, infrastructure, ветка main.

## Что переносить

Сначала получить фактические HEAD и состояние каждого рабочего дерева:

```bash
git -C backend rev-parse HEAD
git -C backend status --short
git -C frontend rev-parse HEAD
git -C frontend status --short
git -C infrastructure rev-parse HEAD
git -C infrastructure status --short
```

Локальный этап сохранён в ветках `main` всех трёх репозиториев. На новой машине
клонировать их рядом, сохранив имена каталогов:

```bash
mkdir -p family-education
cd family-education
git clone --branch main https://github.com/greenRarog/family-education.git backend
git clone --branch main https://github.com/greenRarog/family-education-frontend.git frontend
git clone --branch main https://github.com/greenRarog/family-education-infrastructure.git infrastructure
```

Если репозитории уже есть, сначала проверить их status, затем обновить каждый
через `git pull --ff-only`. Новые незакоммиченные изменения, появившиеся после
этого сохранения, Git сам не переносит: их отдельно сохранить до смены машины.

Все необходимые решения, результаты и оставшиеся задачи находятся в
[плане](refactoring-plan-2026-10-26.md). Корневой `SESSION_CONTEXT.md` и
локальные `.local-stage-test`/исторические архивы не нужны для продолжения;
при желании их можно перенести отдельно. Это перенос рабочей машины разработчика,
а не перенос пользовательских данных на VPS.

## Окружение

Команды зависимостей и запуска — в README [backend](../README.md),
[frontend](../../frontend/README.md), [infrastructure](../../infrastructure/README.md).
Git не переносит реальные env/секреты, БД/volumes, uploads, vendor/node_modules,
dist, браузеры и `/tmp`. Зависимости восстанавливать из lock. По состоянию на
5 ноября пользовательской БД нет; локальные данные воспроизводятся сидерами.
Первый запуск VPS использует новую БД и отдельно подготовленные справочники/admin.
DatabaseSeeder заполняет только справочники; admin:create создаёт администратора
со скрытым вводом пароля. DemoSeeder для рабочего запуска не применяется.

Для тестов создавать новый изолированный стенд по соответствующему README.
Не использовать исторические `/tmp` как готовое окружение. Восстановление
рабочих данных должно учитывать [политику хранения и restore](account-lifecycle.md#требования-перед-включением-удаления).

Новый исполнитель читает backend/AGENTS.md, план и относящиеся к задаче README,
сверяет status/HEAD и сохраняет изменения. Выполнять только порученную задачу
или заранее разрешённую последовательность. Commit/push и VPS автоматически
не выполнять.
