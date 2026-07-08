FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    bash \
    git \
    icu-dev \
    libpq-dev \
    libzip-dev \
    oniguruma-dev \
    unzip \
    zip \
  && docker-php-ext-install \
    intl \
    mbstring \
    pcntl \
    pdo_pgsql \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/devboard/php.ini /usr/local/etc/php/conf.d/devboard.ini

ENV COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /workspace/backend
