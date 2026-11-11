FROM php:8.4-fpm

COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip postgresql-client \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql redis intl zip gd bcmath pcntl opcache

RUN { echo 'expose_php=Off'; echo 'date.timezone=Europe/Paris'; } > /usr/local/etc/php/conf.d/zz-tms.ini

# Development image: the application source is bind-mounted at /var/www/html.
WORKDIR /var/www/html

ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data \
    && chown -R www-data:www-data /var/www

USER www-data
