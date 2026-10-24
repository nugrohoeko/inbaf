#!/bin/sh
set -eu
: "${APP_KEY:?Set a persistent Laravel APP_KEY in Render Environment}"
: "${DB_URL:?Set Render PostgreSQL internal connection URL as DB_URL}"
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache
php artisan config:cache
php artisan view:cache
exec apache2-foreground
