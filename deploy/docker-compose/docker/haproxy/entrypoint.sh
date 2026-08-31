#!/bin/sh
set -e

CERT_DIR="/certs"
HAPROXY_CONFIG="/data/haproxy.cfg"
HAPROXY_MAP="/data/traefik_backends.map"
HAPROXY_PID="/var/run/haproxy/haproxy.pid"
HAPROXY_RUN_DIR="/var/run/haproxy"
HAPROXY_SOCK="/var/run/haproxy/haproxy.sock"
TEST_MODE="${TEST_MODE:-false}"

# Cleanup function
cleanup() {
    echo "🧹 Cleaning up..."
    if [ -f "$HAPROXY_PID" ]; then
        PID=$(cat "$HAPROXY_PID" 2>/dev/null || echo "")
        if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
            echo "🛑 Stopping HAProxy (PID $PID)"
            kill -TERM "$PID" 2>/dev/null || true
            i=1
            while [ $i -le 10 ]; do
                if ! kill -0 "$PID" 2>/dev/null; then
                    break
                fi
                sleep 1
                i=$((i + 1))
            done
            if kill -0 "$PID" 2>/dev/null; then
                echo "🔪 Force killing HAProxy"
                kill -KILL "$PID" 2>/dev/null || true
            fi
        fi
    fi
    exit 0
}

# Check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Set up signal handlers
trap cleanup TERM INT QUIT

echo "🔧 Starting HAProxy with certificate and config monitoring..."

# Check dependencies
for cmd in inotifywait haproxy timeout socat; do
    if ! command_exists "$cmd"; then
        echo "❌ Error: $cmd is not installed"
        exit 1
    fi
done

# Ensure directories and files exist
if [ ! -d "$CERT_DIR" ]; then
    echo "❌ Certificate directory $CERT_DIR does not exist"
    exit 1
fi
if [ ! -f "$HAPROXY_CONFIG" ]; then
    echo "❌ HAProxy config file not found: $HAPROXY_CONFIG"
    exit 1
fi
if [ ! -f "$HAPROXY_MAP" ]; then
    echo "❌ HAProxy map file not found: $HAPROXY_MAP"
    exit 1
fi

# Start HAProxy
echo "🚀 Starting HAProxy..."
if ! haproxy -f "$HAPROXY_CONFIG" -c; then
    echo "❌ HAProxy configuration test failed. Detailed error output:"
    haproxy -f "$HAPROXY_CONFIG" -c -V 2>&1
    exit 1
fi

mkdir -p "$HAPROXY_RUN_DIR"
chown haproxy:haproxy "$HAPROXY_RUN_DIR"
chmod 755 "$HAPROXY_RUN_DIR"

haproxy -W -db -f "$HAPROXY_CONFIG" -p "$HAPROXY_PID" &
sleep 2

if [ -f "$HAPROXY_PID" ]; then
    PID=$(cat "$HAPROXY_PID")
    if kill -0 "$PID" 2>/dev/null; then
        echo "✅ HAProxy started successfully (PID $PID)"
    else
        echo "❌ HAProxy failed to start"
        exit 1
    fi
else
    echo "❌ HAProxy PID file not created"
    exit 1
fi

if [ "$TEST_MODE" = "true" ]; then
    echo "🧪 Test mode enabled, checking startup..."
    sleep 2

    if kill -0 "$PID" 2>/dev/null; then
        echo "✅ HAProxy test passed"
        kill "$PID" 2>/dev/null || true
        exit 0
    else
        echo "❌ HAProxy died during test"
        exit 1
    fi
fi

# Monitor certificates and config
echo "👁️ Starting monitoring for $CERT_DIR, $HAPROXY_CONFIG and $HAPROXY_MAP..."
consecutive_failures=0

while true; do
    if [ ! -d "$CERT_DIR" ] || [ ! -f "$HAPROXY_CONFIG" ] || [ ! -f "$HAPROXY_MAP" ]; then
        echo "⚠️ Required watch paths missing ($CERT_DIR, $HAPROXY_CONFIG, $HAPROXY_MAP), waiting..."
        sleep 30
        continue
    fi
    set +e
    changed_file=$(timeout 300 inotifywait -e close_write,create,delete --format '%w%f' "$CERT_DIR" "$HAPROXY_CONFIG" "$HAPROXY_MAP" 2>/dev/null)
    exit_code=$?
    set -e
    case $exit_code in
        0)
            echo "📝 Detected change: $changed_file"
            if echo "$changed_file" | grep -q "^$CERT_DIR"; then
                echo "🔐 Certificate change detected: $changed_file"
                if [ -f "$changed_file" ]; then
                    echo "set ssl cert $changed_file < $changed_file" | socat stdio "$HAPROXY_SOCK" && \
                    echo "✅ Certificate updated successfully" || \
                    echo "❌ Failed to update certificate"
                fi
            elif [ "$changed_file" = "$HAPROXY_CONFIG" ] || [ "$changed_file" = "$HAPROXY_MAP" ]; then
                if [ "$changed_file" = "$HAPROXY_CONFIG" ]; then
                    echo "📄 Config change detected: $HAPROXY_CONFIG"
                else
                    echo "🗺️ Map change detected: $HAPROXY_MAP"
                fi

                if haproxy -f "$HAPROXY_CONFIG" -c; then
                    if [ -f "$HAPROXY_PID" ]; then
                        PID=$(cat "$HAPROXY_PID")
                        if haproxy -f "$HAPROXY_CONFIG" -sf "$PID"; then
                            echo "✅ HAProxy config reloaded successfully"
                        else
                            echo "❌ HAProxy config reload failed"
                        fi
                    fi
                else
                    echo "❌ HAProxy configuration test failed. Detailed error output:"
                    haproxy -f "$HAPROXY_CONFIG" -c -V 2>&1
                fi
            fi
            consecutive_failures=0
            ;;
        124)
            consecutive_failures=0
            ;;
        130)
            echo "🛑 Received interrupt signal"
            cleanup
            ;;
        *)
            consecutive_failures=$((consecutive_failures + 1))
            echo "❌ Monitor error (exit code: $exit_code), consecutive failures: $consecutive_failures"
            if [ $consecutive_failures -ge 5 ]; then
                echo "❌ Too many consecutive monitor failures, giving up"
                exit 1
            fi
            sleep 30
            ;;
    esac
done