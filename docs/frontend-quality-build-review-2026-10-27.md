# A17 — frontend lint, unit и production build

Дата обновления: 27 октября 2026 года. **A17 и A17.1 закрыты, PASS.** В продолжении по запросу пользователя исправлены права frontend/dist и выполнен обычный `npm run build` без альтернативного outDir: 67 модулей, 270 мс, код 0. Полные lint и unit/SSR 14/14 PASS остаются результатом первоначального A17; исходники с тех пор не менялись.

Исправление выполнено через доступный Docker: одноразовый busybox-контейнер с `--network none --read-only --cap-drop ALL --cap-add CHOWN`, bind mount только frontend/dist, `chown -R 1000:1000 /artifacts`. Перед запуском проверено отсутствие symlink внутри dist. Изменены только владельцы артефактов; содержимое до сборки сверено по SHA256. Владение green:green подтверждено для каталога и всех файлов; системные права за пределами dist не менялись.

Лог успешного обычного build: `/tmp/family-education-a17-permissions-build.log`. Manifest до исправления прав: `/tmp/family-education-a17-permissions-before.json`. Резервная копия прежнего dist из первоначального A17 остаётся в `/tmp/family-education-a17-dist-before`. Новые JS/CSS совпадают по именам и размерам с прежней успешной сборкой в отдельный каталог. Исходники/конфиги/зависимости не менялись, публикации/деплоя нет.

## История первоначального прогона

Ниже сохранена диагностика до исправления прав; указанное в ней ограничение dist теперь снято.

Дата: 27 октября 2026 года. **Проверки A17 выполнены:** полный lint PASS, unit/SSR 14/14 PASS, production build PASS с отдельным outDir. **Обычный `npm run build` в существующий root-owned dist остаётся FAIL/EACCES.** В A17.1 применён обход локального ограничения; права исходного dist не исправлены.

## Результаты

| Проверка | Результат | Материалы |
| --- | --- | --- |
| `npm run lint` (`eslint .`) | PASS, код 0, без замечаний | `/tmp/family-education-a17-lint-initial.log` |
| `npm test` | PASS, 14 тестов, без skipped/cancelled | `/tmp/family-education-a17-unit-initial.log` |
| `npm run build` | FAIL/EACCES при очистке dist/assets | `/tmp/family-education-a17-build-initial.log` |
| Повтор обычного build после неудавшегося переноса dist | Та же ошибка, перенос не выполнен | `/tmp/family-education-a17-build-final.log` (несмотря на имя, это FAIL) |
| `npm run build -- --outDir /tmp/family-education-a17-build` | PASS, 67 модулей, 232 мс | `/tmp/family-education-a17-build-isolated.log` |

Среда: Node 24.15.0, ESLint 10.9.1, Vite 8.3.5; используются установленные проектные зависимости. Node 22, Docker build и GitHub Actions в A17 не запускались.

Unit/SSR-набор: четыре проверки карточки/шапки, пять проверок прав/слияния/receipt и пять проверок polling. Исходники не менялись, поэтому успешные lint/unit повторно не запускались. Полный browser suite из A16 — 15/15 PASS; в A17 повтор не требовался.

## A17.1 — права локальных артефактов

Существующие `frontend/dist` и `frontend/dist/assets` принадлежат root:root, права 0755; процесс работает как green (uid=1000). Vite не может удалить прежние assets перед сборкой. Ошибка относится к файловой системе, а не компиляции приложения.

Попытка перенести старый dist в /tmp получила PermissionError/EACCES; `sudo -n` требует пароль. Вместо изменения системных прав старый dist скопирован в `/tmp/family-education-a17-dist-before`, хеши пяти файлов проверены по `/tmp/family-education-a17-dist-before.json`. Оригинал сохранён без изменений.

Production build выполнен через штатный CLI-параметр `--outDir` в заранее отсутствовавший каталог `/tmp/family-education-a17-build`. Конфигурация, зависимости, lockfiles и исходники не менялись. Vite предупредил, что внешний outDir не будет автоматически очищен; каталог был новым, поэтому старых файлов в нём не было. Дополнительная очистка не требовалась.

**Ограничение остаётся:** обычная сборка в текущий frontend/dist не пройдёт, пока его права/владелец не будут исправлены. Для проверки в этой среде можно использовать отдельный доступный outDir. A17.1 завершает проверку production-сборки этим обходом и не заявляет исправление прав root-owned каталога.

## Созданные артефакты

В `/tmp/family-education-a17-build`:

- `index.html` — 0,59 kB, gzip 0,38 kB;
- `assets/index-Vm_L16Z4.js` — 432,29 kB, gzip 114,13 kB;
- `assets/index-juxO7DIB.css` — 26,23 kB, gzip 5,78 kB;
- статические favicon.svg и icons.svg.

Проверены существование и ненулевой размер всех локальных ресурсов, указанных в index.html. SHA256 артефактов сохранены в `/tmp/family-education-a17-build-manifest.json`. Это проверка компиляции и целостности файлов; production-сайт не публиковался, runtime production bundle и реальный Cloudflare здесь не тестировались. Каталоги /tmp временные.

## Воспроизведение

Из frontend:

```bash
npm run lint
npm test
npm run build -- --outDir /tmp/family-education-a17-build
```

При повторном использовании внешнего outDir Vite не очищает его автоматически. Для независимого воспроизведения выбрать новый пустой каталог.

Snapshot `/tmp/family-education-a17-baseline.json` подтверждает сохранение HEAD и предыдущих изменений всех трёх репозиториев. Изменены только этот отчёт, журнал плана и корневая SESSION_CONTEXT.md; frontend/infrastructure без новых изменений. Старый dist и его резервная копия сверены по хешам. `git diff --check`, пробелы документации и локальные ссылки — PASS. Коммитов/деплоя нет.

**Следующий пункт — A18: backend Pint, Larastan и полный PHPUnit SQLite.**
