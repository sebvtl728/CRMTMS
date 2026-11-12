#!/bin/sh
# Production entrypoint: prepare writable directories, warm the Laravel caches
# with the environment of this installation, then run the requested command.
set -eu

cd /var/www/html

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions \
    storage/framework/views storage/logs

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required" >&2
    exit 1
fi

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan event:cache --no-interaction

if [ "${1:-}" = "php-fpm" ]; then
    php artisan view:cache --no-interaction

    # The web server serves the static assets from a volume shared with this container.
    if [ -n "${TMS_PUBLIC_SYNC:-}" ] && [ -d "$TMS_PUBLIC_SYNC" ]; then
        find "$TMS_PUBLIC_SYNC" -mindepth 1 -delete
        cp -R public/. "$TMS_PUBLIC_SYNC"/
    fi
fi

exec docker-php-entrypoint "$@"
