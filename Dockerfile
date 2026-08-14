# ------------------------------------------------------------
# Frontend build
# ------------------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /var/www/html

COPY package.json package-lock.json ./

RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
COPY .env.example ./

RUN npm run build


# ------------------------------------------------------------
# PHP application
# ------------------------------------------------------------
FROM php:8.4-fpm AS app

ARG WWWUSER=1000
ARG WWWGROUP=1000

RUN groupadd --gid ${WWWGROUP} app \
    && useradd \
        --uid ${WWWUSER} \
        --gid ${WWWGROUP} \
        --create-home \
        app

RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

COPY --from=frontend /var/www/html/public/build ./public/build

RUN composer dump-autoload \
    --no-dev \
    --optimize

RUN chown -R ${WWWUSER}:${WWWGROUP} \
    storage \
    bootstrap/cache

EXPOSE 9000

USER ${WWWUSER}

CMD ["php-fpm"]


# ------------------------------------------------------------
# Nginx
# ------------------------------------------------------------
FROM nginx:1.29-alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

COPY public /var/www/html/public

COPY --from=frontend /var/www/html/public/build /var/www/html/public/build

EXPOSE 80
