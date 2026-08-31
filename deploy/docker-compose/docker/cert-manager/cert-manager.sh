#!/bin/sh
set -e

# Define paths and configuration
ACME_JSON="/letsencrypt/acme.json"
CERT_DIR="/certs"
TMP_DIR="/tmp/certs"
CHECK_INTERVAL="${CHECK_INTERVAL:-300}"
DEBOUNCE_DELAY="${DEBOUNCE_DELAY:-5}"
LOG_LEVEL="${LOG_LEVEL:-normal}"  # silent, normal, verbose
LOG_FILE="/var/log/cert_manager.log"

# Redirect output to log file if not silent
[ "$LOG_LEVEL" != "silent" ] && exec >> "$LOG_FILE" 2>&1

# Check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Log function
log() {
    [ "$LOG_LEVEL" = "silent" ] && return
    [ "$LOG_LEVEL" = "verbose" ] || [ "$2" = "error" ] || [ "$2" = "normal" ] && echo "$(date '+%Y-%m-%d %H:%M:%S') - $1"
}

# Check dependencies
for cmd in jq base64; do
    if ! command_exists "$cmd"; then
        log "Error: $cmd is not installed" error
        exit 1
    fi
done

# Ensure group exists
if command_exists groupadd && ! getent group sslcerts >/dev/null; then
    groupadd sslcerts
fi

# Function to determine jq path for Certificates
get_certificates_path() {
    if jq -e '.letsencrypt.Certificates' "$ACME_JSON" >/dev/null 2>&1; then
        echo ".letsencrypt.Certificates"
    elif jq -e '.Certificates' "$ACME_JSON" >/dev/null 2>&1; then
        echo ".Certificates"
    else
        log "Error: No valid Certificates found in acme.json" error
        return 1
    fi
}

# Function to generate certificates
generate_certificates() {
    if [ ! -f "$ACME_JSON" ] || [ ! -r "$ACME_JSON" ]; then
        log "Error: acme.json not found or not readable" error
        return 1
    fi

    # sleep "$DEBOUNCE_DELAY"

    if ! jq empty "$ACME_JSON" 2>/dev/null; then
        log "Error: acme.json contains invalid JSON" error
        return 1
    fi

    CERT_PATH=$(get_certificates_path) || return 1
    CERT_COUNT=$(jq -r "$CERT_PATH | length" "$ACME_JSON" 2>/dev/null)
    if [ "$CERT_COUNT" = "0" ] || [ "$CERT_COUNT" = "null" ]; then
        log "Error: No certificates found in acme.json" error
        return 1
    fi

    [ "$LOG_LEVEL" = "verbose" ] && log "Found $CERT_COUNT certificate(s) in acme.json"

    mkdir -p "$CERT_DIR" "$TMP_DIR"
    chown root:sslcerts "$CERT_DIR"
    chmod 750 "$CERT_DIR"

    DOMAINS_FILE=$(mktemp)
    jq -r "$CERT_PATH[].domain.main" "$ACME_JSON" > "$DOMAINS_FILE"

    UPDATED_COUNT=0
    while IFS= read -r DOMAIN; do
        [ -z "$DOMAIN" ] && continue

        CERT_FILE="$CERT_DIR/$DOMAIN.pem"
        TMP_FILE="$TMP_DIR/$DOMAIN.pem"

        CERT_BLOCK=$(jq -r --arg domain "$DOMAIN" "$CERT_PATH[] | select(.domain.main == \$domain) | [.certificate, .key] | @tsv" "$ACME_JSON")
        CERT=$(echo "$CERT_BLOCK" | cut -f1 | base64 -d 2>/dev/null)
        KEY=$(echo "$CERT_BLOCK" | cut -f2 | base64 -d 2>/dev/null)

        if [ -z "$CERT" ] || [ -z "$KEY" ]; then
            log "Certificate or key not found for $DOMAIN" error
            continue
        fi

        if [ ! -f "$CERT_FILE" ]; then
            if ! echo "$CERT" | openssl x509 -noout -text >/dev/null 2>&1; then
                log "Invalid certificate format for $DOMAIN" error
                continue
            fi
            if ! echo "$KEY" | openssl rsa -noout >/dev/null 2>&1 && \
               ! echo "$KEY" | openssl ec -noout >/dev/null 2>&1; then
                log "Invalid private key format for $DOMAIN" error
                continue
            fi
        fi

        { echo "$CERT"; echo "$KEY"; } > "$TMP_FILE"

        if ! cmp -s "$TMP_FILE" "$CERT_FILE" 2>/dev/null; then
            [ "$LOG_LEVEL" = "verbose" ] && log "Updating cert for $DOMAIN"
            if mv "$TMP_FILE" "$CERT_FILE"; then
                chown root:sslcerts "$CERT_FILE"
                chmod 640 "$CERT_FILE"
                UPDATED_COUNT=$((UPDATED_COUNT + 1))
            else
                log "Failed to move certificate for $DOMAIN" error
                continue
            fi
        else
            rm "$TMP_FILE" 2>/dev/null || true
        fi
    done < "$DOMAINS_FILE"

    rm -f "$DOMAINS_FILE"
    rm -rf "$TMP_DIR" 2>/dev/null || true

    log "Certificates processed ($UPDATED_COUNT updated)" normal
    return 0
}

# Function to get file hash
get_file_hash() {
    [ -f "$1" ] && md5sum "$1" 2>/dev/null | cut -d' ' -f1 || echo "none"
}

# Initial log
[ "$LOG_LEVEL" != "silent" ] && log "Starting certificate manager..." normal

# Initial generation
if generate_certificates; then
    log "Initial certificate generation completed" normal
else
    log "Waiting for certificates to become available..." normal
fi

# Optimized monitoring
LAST_HASH=""
if command_exists inotifywait; then
    [ "$LOG_LEVEL" = "verbose" ] && log "Using inotify monitoring"

    ACME_DIR=$(dirname "$ACME_JSON")
    ACME_FILE=$(basename "$ACME_JSON")

    # Monitoring the directory instead of the file itself
    inotifywait -m -e create,modify,delete,move "$ACME_DIR" 2>/dev/null | \
    while read -r directory event filename; do
        [ "$filename" = "$ACME_FILE" ] || continue

        [ "$LOG_LEVEL" = "verbose" ] && log "Detected $event on $filename, waiting for stability..."

        sleep "$DEBOUNCE_DELAY"
        CURRENT_HASH=$(get_file_hash "$ACME_JSON")
        sleep 2
        NEW_HASH=$(get_file_hash "$ACME_JSON")

        if [ "$CURRENT_HASH" = "$NEW_HASH" ] && [ "$CURRENT_HASH" != "$LAST_HASH" ]; then
            [ "$LOG_LEVEL" = "verbose" ] && log "File stabilized, processing certificates..."
            if generate_certificates; then
                LAST_HASH="$CURRENT_HASH"
            else
                log "Certificate generation failed" error
            fi
        fi
    done
else
    [ "$LOG_LEVEL" = "verbose" ] && log "Using polling mode"
    while true; do
        if [ -f "$ACME_JSON" ]; then
            CURRENT_HASH=$(get_file_hash "$ACME_JSON")
            if [ "$CURRENT_HASH" != "$LAST_HASH" ] && [ -n "$LAST_HASH" ]; then
                [ "$LOG_LEVEL" = "verbose" ] && log "ACME file changed, processing certificates..."
                if generate_certificates; then
                    LAST_HASH="$CURRENT_HASH"
                else
                    log "Certificate generation failed" error
                fi
            else
                LAST_HASH="$CURRENT_HASH"
            fi
        fi
        sleep "$CHECK_INTERVAL"
    done
fi