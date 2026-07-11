FROM composer:2.10.1@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 AS composer

FROM node:24.16.0-alpine@sha256:21f403ab171f2dc89bad4dd69d7721bfd15f084ccb46cdd225f31f2bc59b5c9a AS assets

WORKDIR /workspace/backend

COPY backend/package.json backend/package-lock.json ./
RUN npm ci

COPY backend ./
RUN npm run build

FROM php:8.4.22-fpm-alpine@sha256:b56e1293e6b0b252f8442651a3c66c2794bc52a41c9259bfa7ee80fd1d270745 AS php-base

RUN apk add --no-cache \
    bash \
    ca-certificates \
    curl \
    icu-dev \
    libpq-dev \
    libzip-dev \
    nginx \
    oniguruma-dev \
    su-exec \
    unzip \
    zip \
  && docker-php-ext-install \
    intl \
    mbstring \
    pcntl \
    pdo_pgsql \
    zip \
  && mkdir -p /run/nginx /var/lib/nginx/tmp /var/log/nginx

COPY docker/devboard/php.ini /usr/local/etc/php/conf.d/devboard.ini

WORKDIR /workspace/backend

FROM php-base AS vendor

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache

COPY backend/composer.json backend/composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

COPY backend ./

RUN composer dump-autoload --no-dev --optimize \
  && php artisan package:discover --ansi

FROM php-base AS runtime

COPY docker/devboard/nginx.prod.conf /etc/nginx/http.d/default.conf
COPY --from=vendor --chown=www-data:www-data /workspace/backend /workspace/backend
COPY --from=assets --chown=www-data:www-data /workspace/backend/public/build /workspace/backend/public/build

RUN mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
  && ln -sf /workspace/backend/storage/app/public /workspace/backend/public/storage \
  && chown -R www-data:www-data storage bootstrap/cache public/storage

EXPOSE 8000

CMD ["sh", "-lc", "php-fpm -D && nginx -g 'daemon off;'"]
