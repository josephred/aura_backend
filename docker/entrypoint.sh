#!/bin/sh
set -e

# Asegurar archivo .env base si no existe
[ -f .env ] || (cp .env.example .env && sed -i '/^DB_CONNECTION=/d' .env)

# Preparación de Laravel
php artisan storage:link || true
php artisan key:generate --force
php artisan config:clear
php artisan view:clear
php artisan migrate --force
php artisan db:seed --force

# Arrancar supervisor con Web, Colas y Scheduler
exec supervisord -c /var/www/html/docker/supervisord.conf
