#!/bin/sh
set -eu

if [ "$1" = "php-fpm" ]; then
    mkdir -p /var/www/html/storage/logs /var/www/html/storage/sessions /var/www/html/public/uploads
    chown -R www-data:www-data /var/www/html/storage /var/www/html/public/uploads
fi

exec docker-php-entrypoint "$@"
