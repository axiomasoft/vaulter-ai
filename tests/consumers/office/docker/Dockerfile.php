# Multi-stage PHP 8.4 consumer stand image (composer artifact build -> clean runtime).
FROM composer:2 AS composer_bin

FROM php:8.4-cli AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libsodium-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        unzip \
        git \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        pcntl \
        sodium \
        zip \
        intl \
        mbstring \
        bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

FROM base AS builder

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock /app/
COPY artifacts /app/artifacts
COPY vendor-mirror /app/vendor-mirror

RUN composer validate --no-check-publish --no-check-version --strict --no-interaction \
    && composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-progress \
    && composer check-platform-reqs --lock --no-dev --no-interaction

COPY . /app

RUN mkdir -p /app/bootstrap/cache /app/storage/app /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs \
    && php artisan package:discover --no-interaction

FROM base AS runtime

WORKDIR /app

COPY --from=builder /app/app /app/app
COPY --from=builder /app/bootstrap /app/bootstrap
COPY --from=builder /app/config /app/config
COPY --from=builder /app/database /app/database
COPY --from=builder /app/public /app/public
COPY --from=builder /app/routes /app/routes
COPY --from=builder /app/artisan /app/artisan
COPY --from=builder /app/vendor /app/vendor
COPY --from=builder /app/composer.json /app/composer.json
COPY --from=builder /app/composer.lock /app/composer.lock

RUN mkdir -p /app/storage/app /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache || true

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
