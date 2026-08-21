# Family Education

Платформа для семейного образования.

Проект помогает семьям и педагогам находить друг друга для организации небольших учебных групп, индивидуального или
группового обучения.

На текущем этапе проект ориентирован на РФ.

---

# Roadmap

## Фаза 0. Foundation

Архитектурный и инфраструктурный фундамент проекта.

- [x] Laravel
- [x] PostgreSQL
- [x] Redis
- [x] Docker
- [x] Docker Compose
- [x] CI
- [x] Pest
- [x] Code style
- [x] Static analysis
- [x] Базовая структура проекта
- [x] Authentication
- [x] Local development environment
- [x] VPS deployment
- [x] HTTPS / Let's Encrypt
- [x] Автоматическое продление SSL-сертификата

**Результат:**

> Пустое приложение с настроенным окружением, CI, authentication и возможностью разворачивания локально и на VPS.

---

## Фаза 1. Identity + Family

Цель — пользователь должен иметь полноценную учётную запись и профиль семьи.

### Authentication

- [x] Регистрация
- [x] Login
- [x] Logout
- [x] Восстановление пароля
- [x] Тип пользователя
    - [x] Family
    - [x] Teacher

### Family profile

- [x] Создание профиля семьи
- [x] Редактирование профиля
- [x] Город
- [x] Добавление детей
- [x] Редактирование детей
- [x] Удаление детей
- [x] Возраст ребёнка
- [x] Возможность иметь несколько детей в одной семье

### Notifications settings

- [x] Настройки уведомлений
- [x] Email notifications
- [x] Подготовить возможность подключения Telegram в будущем

**Результат:**

> Зарегистрированная семья может управлять своим профилем и списком детей.

---

## Фаза 2. Geography + Catalog

Справочные данные, необходимые для объявлений и дальнейшего поиска.

### Geography

- [x] Города
- [x] Районы
- [x] Станции метро
- [x] Связь города и районов
- [x] Связь города и станций метро

На первом этапе не требуется импортировать всю Россию.

Можно начать с нескольких крупных городов и постепенно расширять справочник.

### Catalog

- [x] Предметы
- [x] Запрещённые слова

### Administration

- [x] Административное управление городами
- [x] Административное управление районами
- [x] Административное управление станциями метро
- [x] Административное управление предметами
- [x] Административное управление запрещёнными словами

**Результат:**

> В системе есть минимальный набор справочников, необходимых для создания объявлений.

---

## Фаза 3. Advertisement

Главный MVP-срез.

Объявления являются публичным интерфейсом взаимодействия пользователей.

### Advertisement types

- [x] `family → family`
- [x] `family → teacher`
- [ ] `teacher → service`

### Family → Family

Семья может создать объявление:

> Ищу участников в учебную группу.

- [x] Создание объявления
- [x] Проверка пользовательского текста на запрещённые слова
- [x] Выбор детей, участвующих в группе
- [x] Указание допустимого возраста участников
- [x] Город
- [x] Район
- [x] Станция метро
- [x] Описание
- [x] Редактирование
- [x] Публикация
- [x] Закрытие

### Family → Teacher

Семья может создать объявление:

> Ищу педагога.

- [x] Предметы
- [x] Дети
- [x] Возраст детей
- [x] Формат
- [x] Город
- [x] Район
- [x] Станция метро
- [x] Описание
- [x] Редактирование
- [x] Публикация
- [x] Закрытие

### Teacher → Service

Педагог может создать объявление об услуге.

- [ ] Предмет
- [ ] Формат
- [ ] Онлайн / локально
- [ ] Персонально / группа
- [ ] Город
- [ ] Район
- [ ] Станция метро
- [ ] Описание
- [ ] Редактирование
- [ ] Публикация
- [ ] Закрытие

### Общая функциональность

- [x] Список объявлений
- [x] Просмотр объявления
- [x] Базовые фильтры
- [x] Фильтр по типу
- [x] Фильтр по городу
- [x] Фильтр по возрасту
- [x] Фильтр по предмету
- [x] Фильтр по формату
- [x] Пагинация
- [x] Публичный URL объявления
- [ ] Expiration
- [ ] Автоматическое закрытие объявления через 1 год
- [ ] Уведомление владельцу об истечении срока

**Правило:**

> Профили пользователей не являются публичными. Публичными являются только объявления.

**Результат:**

> Пользователь может найти подходящее объявление или создать собственное.

---

## Фаза 4. Teacher

После реализации семейных объявлений добавляется полноценный профиль педагога.

### Teacher profile

- [ ] Регистрация педагога
- [ ] Редактирование профиля
- [ ] Предметы
- [ ] Образование
- [ ] Диплом
- [ ] Возможность выбирать, какие данные профиля отображаются в объявлении
- [ ] Город
- [ ] Районы
- [ ] Станции метро

### Teacher advertisements

- [ ] Создание объявления услуги
- [ ] Несколько объявлений у одного педагога
- [ ] Разные предметы
- [ ] Разные форматы
- [ ] Онлайн
- [ ] Локально
- [ ] Персонально
- [ ] Группа

**Результат:**

> Педагог может самостоятельно представить несколько различных услуг через отдельные объявления.

---

## Фаза 5. Response + Messaging

Основной механизм коммуникации.

```text
Advertisement
      ↓
   Response
      ↓
 Conversation
      ↓
    Message
```

### Response

- [x] Отклик на объявление
- [x] Отклик доступен только зарегистрированным пользователям
- [x] Один пользователь может сделать только один отклик на конкретное объявление
- [x] Один отклик = один диалог
- [x] Запрет повторного отклика
- [ ] Отклик содержит сообщение

### Conversation

- [x] Создание диалога после отклика
- [x] Список диалогов
- [x] Просмотр диалога
- [x] Доступ к объявлению из диалога
- [ ] Возможность открыть закрытое/истёкшее объявление из существующего диалога

### Messages

- [x] Отправка сообщений
- [x] Получение сообщений
- [x] Read/unread
- [ ] Счётчик непрочитанных сообщений
- [x] Дата/время сообщения

**Результат:**

> Пользователи могут связаться друг с другом только через отклик на объявление.

---

## Фаза 6. Notifications

### Сначала реализуется email.

- [ ] Events
- [ ] NewResponse
- [ ] NewMessage
- [ ] AdvertisementExpired
- [ ] Email
- [ ] Email при новом отклике
- [ ] Email при новом сообщении
- [ ] Email об истечении объявления
- [ ] Настройки email-уведомлений
- [ ] Telegram

### Подключается после email без изменения основной доменной логики.

- [ ] Telegram notification channel
- [ ] Подключение Telegram
- [ ] Настройки Telegram-уведомлений

**Результат:**

> Уведомления отделены от бизнес-логики и могут отправляться через разные каналы.



---

## Фаза 7. Moderation

### Минимальная административная панель.

- [ ] Administration
- [ ] Users
- [ ] Advertisements
- [ ] Reports
- [ ] Subjects
- [ ] Blocked terms
- [ ] Cities
- [ ] Districts
- [ ] Metro stations
- [ ] Actions
- [ ] Заблокировать пользователя
- [ ] Удалить пользователя
- [ ] Удалить объявление
- [ ] Закрыть объявление
- [ ] Обработать жалобу
- [ ] Управлять справочниками
- [ ] Reports
- [ ] Жалоба на объявление
- [ ] Причина жалобы
- [ ] Просмотр жалоб администратором
- [ ] Закрытие жалобы
- [ ] История действий администратора

**Результат:**

> Администратор может поддерживать платформу в рабочем состоянии без прямого доступа к базе данных.

---

## Фаза 8. MVP Hardening

### Подготовка проекта к реальным пользователям.

- [ ] Security
- [ ] Rate limiting
- [ ] Защита от массовых откликов
- [ ] Защита от спама
- [ ] Проверка пользовательского текста
- [ ] Privacy policy
- [ ] Удаление пользовательских данных
- [ ] Обработка удаления пользователя
- [ ] Audit
- [ ] Audit log
- [ ] Логирование административных действий
- [ ] Логирование критических событий
- [ ] Testing
- [ ] Authentication tests
- [ ] Family tests
- [ ] Teacher tests
- [ ] Advertisement tests
- [ ] Expiration tests
- [ ] Response tests
- [ ] Messaging tests
- [ ] Notification tests
- [ ] Moderation tests
- [ ] Production
- [ ] SEO
- [ ] Analytics
- [ ] Error tracking
- [ ] Database backup
- [ ] Проверка восстановления backup
- [ ] Мониторинг приложения
- [ ] Мониторинг дискового пространства
- [ ] Мониторинг Docker
- [ ] Проверка SSL renewal

**Результат:**

> Проект готов для первых реальных семей и педагогов.

---

# Development

## Requirements

Для локальной разработки необходимы:

- Windows / Linux / macOS
- WSL2 (для Windows)
- Ubuntu или другой Linux-дистрибутив в WSL
- Docker Desktop
- Git

Внутри Docker используются:

- PHP 8.4
- Laravel
- PostgreSQL 17
- Redis 8
- Nginx
- Node.js 22

PHP, Composer, PostgreSQL, Redis, Nginx и Node.js не требуется устанавливать непосредственно в операционную систему.

---

# Local setup

## 1. Clone repository

```bash
git clone <repository-url>
cd family-education
```

## 2. Configure environment

```bash   
cp .env.example .env
```

При необходимости изменить значения в .env.

Для Docker-пользователя должны быть указаны:

```dotenv
WWWUSER=1000
WWWGROUP=1000
```

Проверить UID/GID текущего пользователя:

```bash
id -u
id -g
```

### 3. Start containers

```bash
   docker compose up -d --build
```

Проверить состояние:

```bash
docker compose ps
```

Ожидаемые сервисы:

```
app
nginx
node
postgres
redis
```

### 4. Install PHP dependencies

```bash
   docker compose exec app composer install
```

### 5. Generate application key

```bash   
docker compose exec app php artisan key:generate
```

### 6. Run migrations

```bash
   docker compose exec app php artisan migrate
```

### 7. Install frontend dependencies

```bash
   docker compose exec node npm install
```

### 8. Start frontend development server

```bash
   docker compose up -d node
```

## Application

Local application:

```
http://localhost:8080
```

Vite development server:

```
http://localhost:5173
```

## Useful local commands

### Start

```bash
docker compose up -d
```

### Stop

```bash
docker compose down
```

### Rebuild

```bash
docker compose up -d --build
```

### Laravel Tinker

```bash
docker compose exec app php artisan tinker
```

### Tests

```bash
docker compose exec app composer test
```

### Code style / static analysis

```bash
docker compose exec app composer check
```

### Artisan

```bash
docker compose exec app php artisan <command>
```

### Composer

```bash
docker compose exec app composer <command>
```

### NPM

```bash
docker compose exec node npm <command>
```

### View logs

```bash
docker compose logs -f
```

Logs конкретного сервиса:

```bash
docker compose logs -f app
docker compose logs -f nginx
docker compose logs -f postgres
docker compose logs -f redis
```

---

# Production deployment

Production deployment рассчитан на VPS с Linux и Docker.

## Requirements

На VPS необходимо:

- Linux
- Docker
- Docker Compose
- Git
- открытые порты 80 и 443
- DNS-запись домена, указывающая на VPS

Пример:

```
example.com      A    <VPS_IP>
www.example.com  A    <VPS_IP>
```

### 1. Clone repository

```bash
   git clone <repository-url> /var/www/family-education
   cd /var/www/family-education
```

### 2. Create environment

```bash
   cp .env.example .env
```

Заполнить production values.

Особенно важно:

APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com

Database credentials должны быть заданы через environment.

### 3. Build and start application

Production использует отдельный Compose-файл:

```bash
docker compose -f docker-compose.stage.yml up -d --build
```

Проверить:

```bash
docker compose -f docker-compose.stage.yml ps
```

### 4. Run migrations

   ```bash
docker compose -f docker-compose.stage.yml exec app php artisan migrate --force
```

### 5. Check application

```bash
   curl -I http://example.com
```

На этом этапе приложение должно быть доступно по HTTP.

## HTTPS

SSL-сертификат выпускается через Let's Encrypt и Certbot.

Challenge-файлы находятся в:

```bash
certbot/www/
```

Сертификаты:

```bash
letsencrypt/
```

Получение сертификата:

```bash
docker run --rm \
-v "$(pwd)/certbot/www:/var/www/certbot" \
-v "$(pwd)/letsencrypt:/etc/letsencrypt" \
certbot/certbot certonly \
--webroot \
-w /var/www/certbot \
-d example.com \
-d www.example.com \
--email <email> \
--agree-tos \
--no-eff-email
```

После получения сертификата необходимо перезапустить/reload Nginx:

```bash
docker compose -f docker-compose.stage.yml restart nginx
```

## SSL renewal

Проверить возможность автоматического продления:

```bash
./scripts/renew-certificate.sh
```

Тестовое продление:

```bash
docker run --rm \
-v "$(pwd)/certbot/www:/var/www/certbot" \
-v "$(pwd)/letsencrypt:/etc/letsencrypt" \
certbot/certbot renew \
--webroot \
-w /var/www/certbot \
--dry-run \
--no-random-sleep-on-renew
```

Автоматическое продление выполняется через cron.

Пример:

```bash
0 3 * * * /var/www/family-education/scripts/renew-certificate.sh >> /var/log/family-education-certbot.log 2>&1
```

Certbot самостоятельно определяет, требуется ли продление сертификата.

## Deployment script

Production deployment выполняется через:

```bash
./scripts/deploy.sh
```

Типовой процесс:

```
GitHub
↓
CI
↓
SSH
↓
VPS
↓
deploy.sh
↓
Docker Compose
↓
Application
```

## CI

CI запускается автоматически при:

- push
- pull request

CI проверяет:

- установку зависимостей;
- запуск Laravel;
- миграции;
- подключение PostgreSQL;
- подключение Redis;
- code style;
- static analysis;
- тесты.

Deployment в production выполняется после успешного CI для main.

## Architecture principles

Проект развивается как модульный Laravel application.

Бизнес-логика должна быть отделена от инфраструктуры и HTTP-слоя.

Основные доменные области:

```
Identity
Family
Teacher
Geography
Catalog
Advertisement
Response
Messaging
Notification
Moderation
```

На текущем этапе это модульный монолит, а не набор микросервисов.

Границы доменных областей должны сохраняться таким образом, чтобы при необходимости отдельный домен можно было в будущем
вынести в самостоятельный сервис.

## MVP scope

В MVP сознательно отсутствуют:

- мобильные приложения;
- платежи;
- подписки;
- сложные чаты;
- групповые чаты;
- расписания;
- документооборот;
- образовательные программы;
- автоматический matching;
- интеграции с внешними образовательными системами;
- подтверждение личности;
- рейтинги;
- отзывы;
- сложная система модерации.

Основная концепция MVP:
> Доска объявлений для семейного образования с возможностью связаться через отклик и личные сообщения.

## Future

Возможные направления развития после MVP:

- автоматический matching;
- полноценное управление учебными группами;
- расписание;
- выбор образовательной программы;
- учёт уроков;
- документы;
- договоры;
- образовательные материалы;
- расширенная модерация;
- рейтинги и отзывы;
- Telegram;
- монетизация;
- международная версия;
- выделение отдельных доменов в микросервисы.

---

# Проверки перед push в стейдж

```bash
docker compose exec app vendor/bin/pint
docker compose exec node npm run build
docker compose exec app composer check
```
