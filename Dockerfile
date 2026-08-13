FROM php:8.4-fpm

ARG WWWUSER=1000
ARG WWWGROUP=1000

RUN groupadd --gid ${WWWGROUP} green \
    && useradd --uid ${WWWUSER} --gid ${WWWGROUP} --create-home green

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

COPY . .

RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

RUN chown -R ${WWWUSER}:${WWWGROUP} \
    storage \
    bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
