# Targets:
#   dev  - development image, the source is bind-mounted at /var/www/html (compose.yaml)
#   prod - self-contained production image (compose.prod.yaml), no dev tooling, non-root

# ---------------------------------------------------------------------------
# base: PHP 8.4-fpm with the extensions the application needs
# ---------------------------------------------------------------------------
FROM php:8.4-fpm AS base

COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql redis intl zip gd bcmath pcntl opcache

RUN { echo 'expose_php=Off'; echo 'date.timezone=Europe/Paris'; echo 'upload_max_filesize=25M'; echo 'post_max_size=30M'; } > /usr/local/etc/php/conf.d/zz-tms.ini

# ---------------------------------------------------------------------------
# dev: the application source is bind-mounted at /var/www/html
# ---------------------------------------------------------------------------
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git postgresql-client \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data \
    && chown -R www-data:www-data /var/www

USER www-data

# ---------------------------------------------------------------------------
# vendor: production PHP dependencies only
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
# Scripts need the application code (package:discover runs in the prod stage).
RUN composer install --no-dev --no-scripts --no-interaction --no-progress \
    --prefer-dist --optimize-autoloader

# ---------------------------------------------------------------------------
# assets: Vite build (Node 22). ziggy-js is resolved from the PHP vendor tree.
# ---------------------------------------------------------------------------
FROM node:22 AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=vendor /app/vendor/tightenco ./vendor/tightenco
COPY vite.config.js tsconfig.json components.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------------------------------------------------------------------------
# prod
# ---------------------------------------------------------------------------
FROM base AS prod

ARG APP_VERSION=dev

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_VERSION=${APP_VERSION} \
    SESSION_SECURE_COOKIE=true \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info

COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zz-tms-prod.ini
COPY docker/entrypoint.sh /usr/local/bin/tms-entrypoint

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# Writable runtime directories (the storage volume is mounted over storage/).
# Configuration, routes and views are cached by the entrypoint at start: the
# environment differs per installation.
RUN chmod +x /usr/local/bin/tms-entrypoint \
    && mkdir -p storage/app/public storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && rm -f bootstrap/cache/*.php \
    && mkdir -p /srv/public && chown www-data:www-data /srv/public \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi

USER www-data

ENTRYPOINT ["tms-entrypoint"]
CMD ["php-fpm"]
