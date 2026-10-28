# A06 — infrastructure CI

Обновление 28 октября 2026 года: **локальные проверки A06 PASS; подтверждение Actions checkout выделено в A06.1 и остаётся открытым.** Через GitHub API 27 октября проверено: infrastructure приватный, default branch master, опубликованных workflow/Actions secrets/variables нет; backend публичный, frontend приватный (оба default branch main). Локальный gh имеет доступ, но это не подтверждает доступ github.token из runner. Дополнительный запрос SHA remote refs прервался на connection reset от proxy; SHA удалённых main в этом продолжении не подтверждены.

В локальном infrastructure/.github/workflows/ci.yml frontend checkout теперь поддерживает `ssh-key: ${{ secrets.CI_FRONTEND_DEPLOY_KEY }}`; прежний token fallback сохранён. Изменение ещё не опубликовано. Пользователь предпочёл выполнить GitHub-действия самостоятельно, поэтому подготовлен скрипт:

```bash
bash /tmp/family-education-a06-access/run.sh
```

Скрипт проверен через bash -n, workflow — через YAML parser. Он создаёт read-only deploy key только frontend и сохраняет приватную часть в secret infrastructure, если такого secret ещё нет; существующий secret не перезаписывает. Приватный ключ не выводится и локальный файл удаляется после загрузки secret. Далее GitHub Git API создаёт один коммит с checkout-only workflow на базе удалённой default branch и отдельную ветку `ci/repository-access-check-*`; локальные рабочие деревья не читаются и их изменения не публикуются. Workflow выполняет только checkout трёх репозиториев и печатает SHA, без сборки/деплоя. Скрипт ждёт результат и сохраняет `/tmp/family-education-a06-access-result.json`, логи — в `/tmp/family-education-a06-run-*`.

Агент этот скрипт **не выполнял**, ключи/секреты/ветки в GitHub не создавал. Успех можно объявить только после проверки результата Actions. Ключ, secret и diagnostic branch сохраняются после запуска; merge в основную ветку не производится. Подготовленные файлы находятся в /tmp, который может быть очищен. Текущий workflow инфраструктуры с остальными незакоммиченными изменениями должен публиковаться отдельно в рамках принятого пользователем процесса.

## История проверки A06

Дата: 27 октября 2026 года. **A06 выполнен: локальные конфигурационные проверки PASS.** Доступ GitHub Actions к соседним репозиториям не подтверждён. Сборка образов остаётся A20 из [плана](refactoring-plan-2026-10-26.md).

## Что изменено

В [infrastructure workflow](../../infrastructure/.github/workflows/ci.yml):

1. `DB_PASSWORD=ci-build-only` и публичный тестовый `VITE_TURNSTILE_SITE_KEY` перенесены с build step на уровень job. До изменения `stage config` использовал `replace-with-a-strong-database-password` и `replace-with-the-production-turnstile-site-key`, а build — другие значения. Различие воспроизведено на исходном workflow. Теперь проверка конфигурации и сборка получают одинаковые значения.
2. Добавлен вывод фактических SHA всех трёх checkout и версии Compose. Это позволяет установить, какую комбинацию коммитов проверял конкретный запуск с изменяемыми refs.
3. Для трёх checkout добавлен `persist-credentials: false`: последующим шагам не требуется авторизация Git. По умолчанию checkout v4 сохраняет токен в Git config до очистки job. [Документация checkout v4](https://github.com/actions/checkout/blob/v4/README.md).

Другие конфигурации, Dockerfile, env-шаблоны и код приложения не менялись. Коммиты и публикация не выполнялись.

## Checkout, refs и доступ

| Компонент | Repository / ref | Каталог |
| --- | --- | --- |
| Infrastructure | Репозиторий события, стандартный ref/SHA checkout | `$GITHUB_WORKSPACE/infrastructure` |
| Backend | `greenRarog/family-education`, `vars.BACKEND_REF` или `main` | `$GITHUB_WORKSPACE/backend` |
| Frontend | `greenRarog/family-education-frontend`, `vars.FRONTEND_REF` или `main` | `$GITHUB_WORKSPACE/frontend` |

Имена соседних репозиториев совпадают с локальными origin. В обоих checkout явный fallback `main`; одноимённая ветка infrastructure PR автоматически не выбирается. Для повторения определённой комбинации можно задать в variables точные commit SHA. Infrastructure PR использует стандартный merge ref checkout; пуш — коммит события. [Поведение ref и соседних checkout](https://github.com/actions/checkout/blob/v4/README.md).

Локально все три рабочие ветки — `main`. Исходные HEAD:

- backend: `e5e1e584514f173c444f5d5ea3540220a4ec982d`;
- frontend: `eeb38f3cbf08a0ad52f6ee50189a87f0433999da`;
- infrastructure: `932d4eba853306f7826d92a3440576c3e7464460`.

Это локальные HEAD с незакоммиченными изменениями, а не доказательство текущего состояния удалённых веток. CI получит опубликованные коммиты выбранных refs; текущие локальные изменения A01–A06 туда автоматически не попадут. Workflow запускается событиями infrastructure (`push`, `pull_request`, `workflow_dispatch`); изменения соседних репозиториев сами его не запускают.

Для соседних checkout сохранён `secrets.CI_REPOSITORIES_TOKEN || github.token`. Для приватных соседних репозиториев нужен отдельный токен с доступом к обоим; обычный `github.token` ограничен текущим репозиторием. Для fine-grained PAT нужен доступ к содержимому обоих репозиториев на чтение. Наличие `permissions: contents: read` не расширяет область обычного токена на соседние private repositories. [Документация private checkout](https://github.com/actions/checkout/blob/v4/README.md#checkout-multiple-repos-private).

В обычном PR из fork пользовательские Actions secrets не передаются; Dependabot также имеет ограничения secrets. Если соседи приватные и подходящий токен отсутствует, fallback не обеспечит checkout. [Ограничения PR workflows](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#pull_request).

**Не проверены:** visibility репозиториев, фактические значения GitHub variables, наличие/срок/права `CI_REPOSITORIES_TOKEN`, доступ к refs из runner. Условие подтверждения — настоящий Actions run, успешно выполняющий оба checkout. Секреты и настройки GitHub не читались и не изменялись.

## Env и команды Compose

Пять `.env.example` существуют и копируются в ожидаемые пути. Wrapper вычисляет общий workspace из своего расположения и передаёт env-файлы в порядке: backend → frontend → infrastructure → окружение local/stage. Последний файл переопределяет предыдущие, а переменные процесса имеют приоритет над env-файлами. Это проверено фактическим выводом Compose; порядок также описан в [документации Docker](https://docs.docker.com/compose/how-tos/environment-variables/variable-interpolation/).

Относительные build contexts из базового Compose разрешаются в соседние backend/frontend. Проверены существование Dockerfile и targets: `app` для app/queue/scheduler, `nginx` для backend Nginx, `dev` для local frontend, `production` для stage frontend. Build-план содержит пять целей: app, queue, scheduler, nginx, frontend.

Wrapper создаёт отдельные local/stage `vless.json` из `proxy/config.example.json` с mode `0600`; в изолированной копии это подтверждено. Все требуемые файлы bind mounts найдены. Каталоги stage-сертификатов `letsencrypt` и `certbot/www` отсутствуют в чистой копии, но для `config` и плана сборки не нужны. Настройка TLS, прокси и запуск сервисов в A06 не проверяются.

CI выполняет только syntax/config/build. В workflow нет `up`, миграций, `bin/stage`, публикации images или deployment. Env значения предназначены для проверки сборки: переменные Compose не переписывают backend `.env`, который монтируется при запуске. Подготовленные файлы не являются готовым stage-окружением.

## Выполненные проверки

Проверки выполнялись на Docker Compose **v5.6.1**, в `/tmp/family-education-a06-t3yze1a_`, из копий неигнорируемых файлов. Реальные `.env`, VLESS-конфигурации, Git credentials, vendor/node_modules не копировались. Для процессов Compose использовано очищенное окружение с PATH, временным HOME и двумя значениями из job env.

| Проверка | Результат |
| --- | --- |
| YAML через PyYAML BaseLoader, структура triggers/permissions/checkouts/refs/env | PASS; это не полноценная проверка схемы GitHub Actions, actionlint отсутствует. |
| `bash -n` всех четырёх run-блоков | PASS. |
| Команды prepare из workflow | PASS; пять env-файлов созданы из шаблонов. |
| Цикл `bash -n` из workflow для семи infrastructure scripts | PASS; `bin/compose` имеет executable bit в Git. |
| `./infrastructure/bin/compose config --quiet` | PASS. |
| `./infrastructure/bin/compose stage config --quiet` | PASS. |
| Обе конфигурации через `config --format json`, также с cwd вне workspace | PASS; по девять сервисов, ожидаемые contexts/targets/bind paths и значения env, предупреждений нет. |
| `./infrastructure/bin/compose stage build --print` | PASS; пять целей, frontend production и тестовый site key. Только печать плана, сборка не запускалась. |
| Новый шаг вывода SHA и Compose version | PASS на исходном локальном workspace. |
| `git diff --check` трёх репозиториев и отдельная проверка пробелов изменённых/new файлов | PASS. |

Материалы в указанном `/tmp`-каталоге: `before-local.json`, `before-stage.json`, `after-local.json`, `after-stage.json`, `stage-build-print.json`, `stage-build-print.stderr`, `step-3.log`–`step-5.log`. Snapshot исходных HEAD/веток/хешей: `/tmp/family-education-a06-baseline.json`. Временные материалы могут быть удалены системой; основные результаты сохранены здесь.

Настоящий GitHub Actions run, remote checkout, сборка/загрузка образов, доступ к Docker daemon и запуск контейнеров не выполнялись. Успех `config` и `build --print` их не подтверждает. Старый блокер Docker socket в этой задаче повторно не проверялся.

**Следующий пункт — A07: публичный JSON объявлений и приватный edit. A20 остаётся фактической проверкой сборки stage images.**
