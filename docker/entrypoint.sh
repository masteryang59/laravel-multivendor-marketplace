#!/bin/sh
# Prepares the app, then runs the container's command (web, worker or scheduler).
set -e

cd /var/www/html

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    # Runs as root in the web container; artisan runs as www-data so nothing
    # it writes (logs, images, caches) ends up unwritable for Apache.
    chown -R www-data:www-data storage bootstrap/cache
    as_www() { su -s /bin/sh www-data -c "$*"; }

    # compose's healthcheck covers most of the MySQL start-up; this covers the rest.
    for i in $(seq 1 30); do
        as_www php artisan migrate --force && break
        echo "database not ready, retrying ($i)"; sleep 2
    done
    if [ "${SEED_DEMO:-false}" = "true" ]; then
        as_www php artisan marketplace:seed-demo
    fi
    as_www php artisan config:cache
    as_www php artisan route:cache
    as_www php artisan view:cache
fi

exec "$@"
