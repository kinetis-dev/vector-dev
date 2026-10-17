#!/bin/sh
set -e

# app, worker and frankenphp start together on one vendor volume; the
# lock makes each later install wait for the first instead of writing
# the same tree.
mkdir -p vendor
flock vendor/.install.lock composer install --no-interaction --no-progress

# The file session store keeps its directory at 0700 and both PHP-FPM
# and FrankenPHP serve requests as www-data, so create it for that user
# before anything running as root (the CLI, the test suite) can create
# it first. It is a volume the two share, so one browser session works
# on both ports.
mkdir -p /tmp/kinetis-sessions
chown www-data:www-data /tmp/kinetis-sessions
chmod 700 /tmp/kinetis-sessions

# Uploaded files (FILESYSTEM_ROOT): a private volume PHP-FPM and
# FrankenPHP write and the worker reads, never served by nginx or Caddy.
mkdir -p /var/lib/vector-storage
chown www-data:www-data /var/lib/vector-storage
chmod 750 /var/lib/vector-storage

exec "$@"
