FROM php:8.4-fpm-alpine

# unzip is needed by Composer to extract downloaded packages. libuv backs
# ext-uv below; libzip backs ext-zip, which openspout/openspout writes
# XLSX exports through.
RUN apk add --no-cache unzip libpq libuv libzip

# pdo_mysql and pdo_pgsql serve PHP-FPM, the CLI and migrations; mysqli,
# and pgsql with sockets, are the
# non-blocking native driver DB_DRIVER=auto selects under a persistent
# worker.
# pcntl lets the queue worker stop gracefully on SIGTERM. pcov is the
# coverage driver mutation testing needs; it costs nothing when the
# collection is off, which is the default. uv is kinetis/storage's
# OS-native file driver: without it every filesystem call is a round
# trip to a pool of helper processes.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev linux-headers libuv-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mysqli pdo_pgsql pgsql sockets pcntl zip \
    && pecl install pcov \
    && printf '\n' | pecl install uv-beta \
    && docker-php-ext-enable pcov uv \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /workspace/demo/backend

COPY docker/kinetis.ini /usr/local/etc/php/conf.d/zz-kinetis.ini

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
