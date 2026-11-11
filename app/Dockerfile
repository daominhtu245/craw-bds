# Laravel app image (php-fpm + nginx in one container), reused by the
# queue (Horizon) and scheduler services. Docs: serversideup.net/open-source/docker-php
FROM serversideup/php:8.4-fpm-nginx AS base

# pcntl, redis, pdo_mysql, zip, opcache ship with the image; intl does not.
USER root
RUN install-php-extensions intl
USER www-data

WORKDIR /var/www/html

# Dependencies first for better layer caching. Dev dependencies are kept so
# `php artisan test` works inside the container; pass COMPOSER_NO_DEV=1 for production.
ARG COMPOSER_NO_DEV=0
ENV COMPOSER_NO_DEV=${COMPOSER_NO_DEV}
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

# ------------------------------------------------------------------ assets
# The public site (App #2) uses Vite + Tailwind; public/build is not in git
# and is excluded from the build context, so it is built here.
FROM node:22-alpine AS assets
WORKDIR /var/www/html
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
# Tailwind scans Laravel's pagination views (@source in resources/css/app.css).
COPY --from=base /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# ------------------------------------------------------------------- final
FROM base

COPY --chown=www-data:www-data . .
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build ./public/build
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
        storage/app/public/listings
