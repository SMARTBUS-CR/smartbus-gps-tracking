#!/bin/sh
set -e

# Usar puerto 10000 si Render no inyecta la variable PORT
export PORT="${PORT:-10000}"

echo "=== Preparando microservicio GPS Tracking ==="

# Optimización de cachés de Laravel
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "=== Iniciando Supervisor (API + Reverb + Queues) ==="
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf