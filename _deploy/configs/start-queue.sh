#!/bin/bash
set -e

if [ "${QUEUE_WORKERS:-0}" -eq 0 ]; then
    echo "Queue workers disabled (QUEUE_WORKERS=0)"
    exit 0
fi

echo "Starting Laravel queue workers..."
php artisan queue:work --tries=3 --timeout=60
