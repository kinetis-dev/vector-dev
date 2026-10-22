# The demo's PHP images: one recipe, two runtimes. `fpm` serves app and
# worker (PHP-FPM behind nginx, and `kinetis queue:work`); `frankenphp`
# serves the frankenphp service in worker mode. Both run the same
# php-extensions.sh, kinetis.ini and entrypoint, and docker-compose.yml
# builds both from this file, so one `docker compose build` can never
# leave one runtime on the previous extensions.
#
# The PHP release, the FrankenPHP release and the PIE release are set
# here and nowhere else. PHP is pinned to a patch release, never a
# floating minor tag, so a build cannot fall back to an older patch:
# at least 8.4.27 on 8.4 and 8.5.12 on 8.5 (the CI matrix builds both).
ARG PHP_VERSION=8.4.27
ARG FRANKENPHP_VERSION=1.13.1
ARG PIE_VERSION=1.5.2

# PIE's binary-only image (ghcr.io/php/pie:<version>-bin), pinned rather
# than the floating `bin` tag. php-extensions.sh runs with it bind-mounted
# and with the optional github_token secret mounted, so neither PIE nor
# the token ends up in a layer.
FROM ghcr.io/php/pie:${PIE_VERSION}-bin AS pie

FROM php:${PHP_VERSION}-fpm-alpine AS fpm-base

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-alpine AS frankenphp-base

# --- PHP-FPM: app and worker ---------------------------------------------
FROM fpm-base AS fpm

COPY docker/php-extensions.sh /usr/local/bin/php-extensions.sh
RUN --mount=type=bind,from=pie,source=/pie,target=/usr/local/bin/pie \
    --mount=type=secret,id=github_token,required=false \
    php-extensions.sh

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/kinetis.ini /usr/local/etc/php/conf.d/zz-kinetis.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

WORKDIR /workspace/demo/backend
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]

# --- FrankenPHP worker mode ----------------------------------------------
FROM frankenphp-base AS frankenphp

COPY docker/php-extensions.sh /usr/local/bin/php-extensions.sh
RUN --mount=type=bind,from=pie,source=/pie,target=/usr/local/bin/pie \
    --mount=type=secret,id=github_token,required=false \
    php-extensions.sh

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/kinetis.ini /usr/local/etc/php/conf.d/zz-kinetis.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

# FrankenPHP runs as www-data, like PHP-FPM's workers, so the session
# directory and the storage volume belong to the same user under both
# runtimes. Caddy keeps its state under XDG_DATA_HOME and
# XDG_CONFIG_HOME.
RUN chown -R www-data:www-data /data /config

WORKDIR /workspace/demo/backend
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["su-exec", "www-data", "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
