FROM php:8.5-fpm-bookworm AS app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && composer check-platform-reqs --no-dev \
    && mkdir -p storage/logs storage/sessions public/uploads \
    && chown -R www-data:www-data storage public/uploads

COPY docker/php.ini /usr/local/etc/php/conf.d/salsa.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-salsa.conf
COPY docker/entrypoint.sh /usr/local/bin/salsa-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/salsa-entrypoint \
    && chmod +x /usr/local/bin/salsa-entrypoint
ENTRYPOINT ["salsa-entrypoint"]
CMD ["php-fpm"]

FROM nginx:stable-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
