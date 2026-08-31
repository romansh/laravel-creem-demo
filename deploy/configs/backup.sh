#!/bin/bash
set -e

BACKUP_DIR="/home/deploy/backups"
BACKUP_FILE="$BACKUP_DIR/backup-$(date +%Y%m%d%H%M%S).tar.gz"
POSTGRES_BACKUP="$BACKUP_DIR/pg-dump-$(date +%Y%m%d%H%M%S).sql"

mkdir -p "$BACKUP_DIR"
docker compose exec -T postgres pg_dump -U "${DB_USERNAME}" "${DB_DATABASE}" > "$POSTGRES_BACKUP"
tar -czf "$BACKUP_FILE" -C /var/www/html . -C /var/seaweedfs/data .
find "$BACKUP_DIR" -name "backup-*.tar.gz" -mtime +7 -delete
find "$BACKUP_DIR" -name "pg-dump-*.sql" -mtime +7 -delete

echo "Backup completed: $BACKUP_FILE"
echo "Database backup completed: $POSTGRES_BACKUP"
