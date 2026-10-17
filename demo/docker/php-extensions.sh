#!/bin/sh
# The PHP extensions every PHP image of the demo carries, installed the
# same way on each base: PHP-FPM's (non-thread-safe) and FrankenPHP's
# (thread-safe). A compiled extension cannot move between the two
# builds, so what the images share is this one recipe; the Dockerfile
# runs it in both targets, and bin/check-frankenphp compares the loaded
# extensions of the running containers.
set -eu

# unzip is needed by Composer to extract downloaded packages. libuv backs
# ext-uv below; libevent backs ext-event; libzip backs ext-zip, which
# openspout/openspout writes XLSX exports through; icu-libs backs
# ext-intl, whose Unicode collation the test suite holds Vector's
# language list to. su-exec drops from root to www-data after the
# entrypoint's setup.
apk add --no-cache unzip su-exec libpq libuv libevent libzip icu-libs

# pdo_mysql and pdo_pgsql serve PHP-FPM, the CLI and migrations; mysqli,
# and pgsql with sockets, are the non-blocking native drivers
# DB_DRIVER=auto selects under a persistent worker (FrankenPHP).
# pcntl lets the queue worker stop gracefully on SIGTERM. pcov is the
# coverage driver mutation testing needs; it costs nothing when the
# collection is off, which is the default.
#
# uv and event are Revolt event-loop drivers. Without one, Revolt falls
# back to select(), which cannot watch a descriptor numbered above 1024 —
# and under FrankenPHP the Go server's client sockets share the process's
# descriptor table with the Postgres and Redis clients' watchers, so a
# loaded worker passes 1024. Revolt prefers uv when both are loaded, and
# uv is also kinetis/storage's OS-native file driver (amphp/file uses it
# only when the loop runs on uv; otherwise every filesystem call is a
# round trip to a pool of helper processes). uv has only beta releases,
# so it is pinned to the one Kinetis's runtime guide names; event is the
# stable driver that guide requires, and the one Revolt falls back to
# without uv.
apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev linux-headers libuv-dev libevent-dev openssl-dev libzip-dev icu-dev
docker-php-ext-install pdo_mysql mysqli pdo_pgsql pgsql sockets pcntl zip intl
pecl install pcov
printf '\n' | pecl install uv-0.3.0
# event's configure questions keep their defaults (sockets support on).
yes '' | pecl install event-3.1.6
docker-php-ext-enable pcov uv
# event's sockets support needs ext-sockets loaded first: conf.d loads in
# name order, and docker-php-ext-sockets.ini sorts before this file.
echo 'extension=event' > "$PHP_INI_DIR/conf.d/docker-php-ext-zz-event.ini"
apk del .build-deps
rm -rf /tmp/pear
