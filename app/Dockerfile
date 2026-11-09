# Laravel app image (php-fpm + nginx in one container), reused by the
# queue (Horizon) and scheduler services. Docs: serversideup.net/open-source/docker-php
FROM serversideup/php:8.4-fpm-nginx

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

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
