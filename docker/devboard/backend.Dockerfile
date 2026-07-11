FROM composer:2.10.1@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 AS composer

FROM php:8.4.22-cli-alpine@sha256:708490001d69a35b977e0ad19557b3528556a1497189f2969142ef302ad2c20d

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

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/devboard/php.ini /usr/local/etc/php/conf.d/devboard.ini

ENV COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /workspace/backend
