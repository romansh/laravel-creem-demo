#!/bin/bash

# Export script for Laravel Sail project with all volumes
# Usage: ./export-project.sh

set -e

# ---------------------------------------------------------
# 1. PATH RESOLUTION
# ---------------------------------------------------------

# Directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Project root (for example: $HOME/php/run/forit2)
PROJECT_DIR="$(dirname "$(dirname "$SCRIPT_DIR")")"

# Base directory (for example: $HOME/php/run)
BASE_DIR="$(dirname "$PROJECT_DIR")"

PROJECT_NAME="$(basename "$PROJECT_DIR")"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"

BACKUP_DIR="$BASE_DIR/${PROJECT_NAME}-backup-$TIMESTAMP"
ARCHIVE_NAME="${PROJECT_NAME}-full-backup-$TIMESTAMP.tar.gz"
ARCHIVE_PATH="$BASE_DIR/$ARCHIVE_NAME"

echo "🚀 Starting project export: $PROJECT_NAME"
echo "📁 Backup directory: $BACKUP_DIR"

# ---------------------------------------------------------
# 2. PREPARE DIRECTORIES
# ---------------------------------------------------------

mkdir -p "$BACKUP_DIR/project"
mkdir -p "$BACKUP_DIR/volumes"

# ---------------------------------------------------------
# 3. COPY PROJECT FILES
# ---------------------------------------------------------

echo "📦 Copying project files..."

rsync -a \
  --exclude='vendor' \
  --exclude='node_modules' \
  --exclude='.git' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  "$PROJECT_DIR/" "$BACKUP_DIR/project/" 2>/dev/null || true

# ---------------------------------------------------------
# 4. EXPORT DOCKER VOLUMES
# ---------------------------------------------------------

echo "🔍 Searching for Docker volumes..."

VOLUMES="$(docker volume ls --format "{{.Name}}" | grep "^${PROJECT_NAME}_" || true)"

if [ -n "$VOLUMES" ]; then
  echo "📊 Found volumes:"
  echo "$VOLUMES"

  echo "💾 Exporting volumes..."
  for VOLUME in $VOLUMES; do
    SHORT_NAME="${VOLUME#${PROJECT_NAME}_}"
    echo "  - $VOLUME → $SHORT_NAME.tar.gz"

    docker run --rm \
      -v "$VOLUME:/source" \
      -v "$BACKUP_DIR/volumes:/backup" \
      ubuntu \
      tar czf "/backup/$SHORT_NAME.tar.gz" -C /source . \
      || echo "    ⚠️  Failed to export $VOLUME"
  done
else
  echo "⚠️  No Docker volumes found"
fi

# Save volume list
echo "$VOLUMES" > "$BACKUP_DIR/volumes.list"

# ---------------------------------------------------------
# 5. ARCHIVE BACKUP
# ---------------------------------------------------------

echo "🗜️  Creating final archive..."

cd "$BASE_DIR"
tar czf "$ARCHIVE_PATH" "$(basename "$BACKUP_DIR")"

# ---------------------------------------------------------
# 6. FINAL OUTPUT
# ---------------------------------------------------------

echo ""
echo "✅ Export completed!"
echo "📦 Archive: $ARCHIVE_PATH"
echo "📊 Size: $(du -h "$ARCHIVE_PATH" | cut -f1)"
echo ""
echo "📋 To import on another machine:"
echo "   tar xzf $ARCHIVE_NAME"
echo "   cd ${PROJECT_NAME}-backup-$TIMESTAMP"
echo "   ./deploy/migration/import-project.sh"
echo ""

# ---------------------------------------------------------
# 7. CLEANUP
# ---------------------------------------------------------

rm -rf "$BACKUP_DIR"

