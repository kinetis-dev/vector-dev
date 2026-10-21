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
# round trip to a pool of helper processes). uv has only 0.x releases,
# so it is pinned to an exact one (below); event is the stable driver
# Kinetis's runtime guide requires, and the one Revolt falls back to
# without uv.
apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev linux-headers libtool libuv-dev libevent-dev openssl-dev libzip-dev icu-dev
docker-php-ext-install pdo_mysql mysqli pdo_pgsql pgsql sockets pcntl zip intl

# The three extensions outside PHP's source tree are installed with PIE
# (php/pie, the PHP Foundation's successor to PECL), each pinned to the
# Packagist php-ext package and version below. PIE is not part of the
# image: the Dockerfile bind-mounts its binary for this one RUN.
#
# PIE downloads each release through the GitHub API, which allows an
# anonymous client 60 requests an hour per IP — little on a shared CI
# runner. The Dockerfile mounts an optional BuildKit secret, github_token,
# that CI fills from the workflow's GITHUB_TOKEN; it is exported for PIE
# only when present and non-empty, so a local `docker compose up --build`
# runs anonymously. A secret mount never reaches a layer.
#
# uv is 0.3.1, not the 0.3.0 PECL served and Kinetis's runtime guide
# names: amphp/uv publishes no other release on Packagist. event keeps
# the configuration PECL's prompts defaulted to: sockets, OpenSSL and the
# extra (HTTP, DNS, RPC) support on, debug and pthreads off. uv and pcov
# need no options.
token_file=/run/secrets/github_token
if [ -s "$token_file" ]; then
    GITHUB_TOKEN=$(cat "$token_file")
    export GITHUB_TOKEN
fi

pie install --no-interaction pecl/pcov:1.0.12
pie install --no-interaction amphp/uv:0.3.1
# PIE would enable event through docker-php-ext-enable as
# docker-php-ext-event.ini, which loads before docker-php-ext-sockets.ini;
# event's sockets support needs ext-sockets loaded first, and conf.d
# loads in name order, so event is enabled by a file that sorts after it.
pie install --no-interaction --skip-enable-extension osmanov/pecl-event:3.1.6 \
    --enable-event-sockets --with-event-openssl --with-event-extra=yes
echo 'extension=event' > "$PHP_INI_DIR/conf.d/docker-php-ext-zz-event.ini"
apk del .build-deps
rm -rf /root/.pie /root/.cache /root/.composer /root/.config/composer
