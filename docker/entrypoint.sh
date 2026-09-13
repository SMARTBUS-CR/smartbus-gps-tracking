#!/bin/sh
set -e

# Usar puerto 10000 si Render no inyecta la variable PORT
export PORT="${PORT:-10000}"

echo "=== Preparando microservicio GPS Tracking ==="

# Generar la configuración de Nginx reemplazando $PORT dinámicamente
envsubst '$PORT' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Optimización de cachés de Laravel
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "=== Iniciando Supervisor (Nginx + API + Reverb + Queues) ==="
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf