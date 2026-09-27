#!/bin/sh
set -e

# app and worker start together on one vendor volume; the lock makes the
# second install wait for the first instead of writing the same tree.
mkdir -p vendor
flock vendor/.install.lock composer install --no-interaction --no-progress

# The file session store keeps its directory at 0700 and PHP-FPM runs as
# www-data, so create it for that user before anything running as root
# (the CLI, the test suite) can create it first.
mkdir -p /tmp/kinetis-sessions
chown www-data:www-data /tmp/kinetis-sessions
chmod 700 /tmp/kinetis-sessions

# Uploaded files (FILESYSTEM_ROOT): a private volume PHP-FPM writes and
# the worker reads, never served by nginx.
mkdir -p /var/lib/vector-storage
chown www-data:www-data /var/lib/vector-storage
chmod 750 /var/lib/vector-storage

exec "$@"
