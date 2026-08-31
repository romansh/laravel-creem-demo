#!/bin/bash
set -e

echo "Configuring log rotation..."
sudo tee /etc/logrotate.d/laravel > /dev/null <<EOL
/var/www/html/storage/logs/*.log {
    daily
    missingok
    rotate 7
    compress
    delaycompress
    notifempty
    create 640 deploy deploy
    sharedscripts
    postrotate
        docker compose -f /var/www/html/docker-compose.yml exec -T app php artisan cache:clear >/dev/null 2>&1 || true
    endscript
}
EOL

sudo logrotate -f /etc/logrotate.d/laravel
echo "Log rotation configured successfully!"