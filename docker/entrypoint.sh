#!/bin/sh
set -e

# Use port 10000 if Render does not inject the PORT variable
export PORT="${PORT:-10000}"

echo "=== Preparando microservicio GPS Tracking ==="

# Generate the Nginx configuration replacing $PORT dynamically
envsubst '$PORT' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Build the Laravel caches
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "=== Iniciando Supervisor (Nginx + API + Reverb + Queues) ==="
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf