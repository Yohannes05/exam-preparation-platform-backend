#!/bin/sh
set -e

# Render assigns the listening port via $PORT.
envsubst '${PORT}' < /etc/nginx/nginx-site.template > /etc/nginx/conf.d/default.conf

mkdir -p storage/framework/cache storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache

# Render cron services pass their one-off command as Docker arguments.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

# Render free web services do not support pre-deploy commands. Migrations are
# safe to rerun and run before serving traffic on each container start.
php artisan migrate --force
php artisan storage:link --force

exec /usr/bin/supervisord -c /etc/supervisord.conf
