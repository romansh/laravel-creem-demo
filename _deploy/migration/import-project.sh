#!/bin/bash
set -e

# ---------------------------------------------------------
# 1. DIRECTORY AND PROJECT NAME ANALYSIS
# ---------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

BACKUP_ROOT="$(dirname "$(dirname "$(dirname "$SCRIPT_DIR")")")"
BACKUP_NAME="$(basename "$BACKUP_ROOT")"
BACKUP_PROJECT="${BACKUP_NAME%%-backup-*}"

SOURCE_FOLDER="project"

DEFAULT_TARGET_DIR="$(dirname "$BACKUP_ROOT")/$BACKUP_PROJECT"

if [ -n "$1" ]; then
    TARGET_DIR="$1"
else
    read -p "📁 Enter target directory [$DEFAULT_TARGET_DIR]: " TARGET_DIR
    TARGET_DIR="${TARGET_DIR:-$DEFAULT_TARGET_DIR}"
fi

PROJECT_NAME="$(basename "$TARGET_DIR")"

echo ""
echo "🚀 Starting project import"
echo "📦 Backup project: $BACKUP_PROJECT"
echo "📁 Target project: $PROJECT_NAME"
echo ""

# ---------------------------------------------------------
# 2. TARGET DIRECTORY CHECK
# ---------------------------------------------------------

SKIP_COPY="n"

if [ -d "$TARGET_DIR" ]; then
    echo "⚠️  Target directory exists: $TARGET_DIR"
    echo "Выберите действие:"
    echo "  1) Overwrite all files"
    echo "  2) Skip copying project files and dependencies (keep existing)"
    read -p "Введите 1 или 2 [1]: " choice
    choice="${choice:-1}"

    if [[ "$choice" == "1" ]]; then
        rm -rf "$TARGET_DIR"
        mkdir -p "$TARGET_DIR"
    else
        SKIP_COPY="y"
    fi
else
    mkdir -p "$TARGET_DIR"
fi

cd "$TARGET_DIR"

# ---------------------------------------------------------
# 3. FILE DEPLOYMENT
# ---------------------------------------------------------

if [[ "$SKIP_COPY" =~ ^[nN]$ ]]; then
    echo "📦 Copying project files..."
    cp -r "$BACKUP_ROOT/$SOURCE_FOLDER/"* "$TARGET_DIR/"
    cp -r "$BACKUP_ROOT/$SOURCE_FOLDER/".* "$TARGET_DIR/" 2>/dev/null || true

    echo "🔐 Setting permissions..."
    chmod -R 775 storage bootstrap/cache 2>/dev/null || true
    chown -R "$USER:www-data" storage bootstrap/cache 2>/dev/null || true

    if command -v composer >/dev/null 2>&1 && [ -f composer.json ]; then
        echo "📚 Installing PHP dependencies..."
        composer install --no-interaction --prefer-dist --optimize-autoloader
    fi

    if command -v npm >/dev/null 2>&1 && [ -f package.json ]; then
        echo "📦 Installing Node dependencies..."
        npm install
        npm run build || true
    fi
else
    echo "ℹ️  Skipping project files copy and dependencies"
fi

# ---------------------------------------------------------
# 4. STOP CONTAINERS BEFORE VOLUME RESTORE
# ---------------------------------------------------------
echo ""
echo "⏸️  Stopping containers for volume restore..."
docker compose stop || true

# ---------------------------------------------------------
# 5. VOLUME RESTORATION
# ---------------------------------------------------------

VOLUME_BACKUP_DIR="$BACKUP_ROOT/volumes"

if [ ! -d "$VOLUME_BACKUP_DIR" ]; then
    echo "ℹ️  No volume backups found, skipping volume restore"
else
    echo ""
    echo "💾 Restoring volumes from backup"

    CURRENT_VOLUMES=$(docker compose config --volumes)

    for VOLUME in $CURRENT_VOLUMES; do
        echo ""
        echo "🔍 Volume: $VOLUME"

        VOLUME_FULL_NAME="${PROJECT_NAME}_${VOLUME}"

        # Searching for archives that contain the volume name without the old project prefix
        MATCHING_BACKUPS=$(ls "$VOLUME_BACKUP_DIR" | grep "\.tar\.gz$" | grep -E "[^/]*${VOLUME}.*\.tar\.gz" || true)

        if [ -z "$MATCHING_BACKUPS" ]; then
            echo "  ⚠️  No backup archive found, skipping"
            continue
        fi

        if [ "$(echo "$MATCHING_BACKUPS" | wc -l)" -gt 1 ]; then
            echo "  ⚠️  Multiple backups found:"
            echo "$MATCHING_BACKUPS"
            read -p "  Import this volume? (y/n): " confirm
            [[ "$confirm" =~ ^[yY]$ ]] || continue
            ARCHIVE="$(echo "$MATCHING_BACKUPS" | head -1)"
        else
            ARCHIVE="$MATCHING_BACKUPS"
        fi

        echo "  ↳ Using archive: $ARCHIVE"

        docker run --rm \
            -v "${VOLUME_FULL_NAME}:/target" \
            -v "$VOLUME_BACKUP_DIR/$ARCHIVE:/backup.tar.gz" \
            busybox sh -c "rm -rf /target/* && tar xzf /backup.tar.gz -C /target"

        echo "  ✅ Restored"
    done
fi

# ---------------------------------------------------------
# 6. START CONTAINERS AFTER VOLUME RESTORE
# ---------------------------------------------------------
echo ""
echo "▶️  Starting containers..."
docker compose up -d

# ---------------------------------------------------------
# 7. FINALIZATION
# ---------------------------------------------------------
echo ""
echo "✨ Import completed successfully!"
echo "📂 Project location: $TARGET_DIR"
echo "🌐 Suggested next steps:"
echo "   docker compose exec app php artisan migrate"
echo ""

