#!/bin/bash
set -e

echo "Configuring Laravel application..."
composer install --no-dev --optimize-autoloader

if [ -z "${APP_KEY:-}" ]; then
	echo "APP_KEY is not set. Refusing to generate a new application key during deploy."
	exit 1
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "Configuration completed!"
