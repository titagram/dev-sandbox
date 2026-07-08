FROM php:8.4-fpm-alpine AS php-base

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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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
