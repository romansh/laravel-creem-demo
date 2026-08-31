#!/bin/sh

# Logging function
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> /tmp/wrap.log 2>&1; }

# Function to determine the best available port-checking tool
detect_port_tool() {
    if command -v ss >/dev/null 2>&1; then
        log "Using 'ss' for port checking"
        CHECK_PORT="ss -tuln"
        CHECK_PORT_GREP="grep -q"
        PORT_9221=":9221"
        PORT_9222=":9222"
    elif command -v netstat >/dev/null 2>&1; then
        log "Using 'netstat' for port checking"
        CHECK_PORT="netstat -tuln"
        CHECK_PORT_GREP="grep -q"
        PORT_9221=":9221"
        PORT_9222=":9222"
    elif command -v lsof >/dev/null 2>&1; then
        log "Using 'lsof' for port checking"
        CHECK_PORT="lsof -iTCP"
        CHECK_PORT_GREP="-sTCP:LISTEN -t >/dev/null 2>&1"
        PORT_9221=":9221"
        PORT_9222=":9222"
    elif [ -f /proc/net/tcp ]; then
        log "Using '/proc/net/tcp' for port checking"
        CHECK_PORT="grep"
        CHECK_PORT_GREP=">/dev/null 2>&1"
        PORT_9221=" 0x23F9 "  # 9221 in hex
        PORT_9222=" 0x23FA "  # 9222 in hex
        # Override CHECK_PORT for /proc/net/tcp since it needs a file
        check_port() {
            local port="$1"
            grep "$port" /proc/net/tcp >/dev/null 2>&1
        }
    else
        log "No port checking tools available (ss, netstat, lsof, /proc/net/tcp), exiting"
        exit 1
    fi
}

# Default port checking function (overridden for /proc/net/tcp)
check_port() {
    local port="$1"
    $CHECK_PORT | $CHECK_PORT_GREP "$port"
}

start_socat() {
    pkill -f "socat TCP-LISTEN:9222" || log "No socat to kill"
    sleep 1
    if check_port "$PORT_9222"; then
        fuser -k 9222/tcp || log "Failed to free 9222"
        sleep 1
    fi
    socat TCP-LISTEN:9222,fork,reuseaddr TCP:127.0.0.1:9221 >> /tmp/wrap.log 2>&1 &
    SOCAT_PID=$!
    sleep 1
    if kill -0 "$SOCAT_PID" 2>/dev/null; then
        log "Socat started (PID $SOCAT_PID)"
    else
        log "Socat start failed, retrying..."
        sleep 2
        socat TCP-LISTEN:9222,fork,reuseaddr TCP:127.0.0.1:9221 >> /tmp/wrap.log 2>&1 &
        SOCAT_PID=$!
        if ! kill -0 "$SOCAT_PID" 2>/dev/null; then
            log "Socat failed again, exiting"
            exit 1
        fi
    fi
}

restart_chromium() {
    log "Preparing to restart Chromium..."
    CHROME_PID=$(pgrep -f "chromium.*--remote-debugging-port=9221")
    if [ -n "$CHROME_PID" ]; then
        kill -SIGTERM "$CHROME_PID"
        sleep 5
        if kill -0 "$CHROME_PID" 2>/dev/null; then
            kill -SIGKILL "$CHROME_PID"
            log "Force-killed Chromium PID $CHROME_PID"
        fi
    fi
    if check_port "$PORT_9221"; then
        fuser -k 9221/tcp || log "Failed to free 9221"
        sleep 2
    fi
    chromium --headless=new --remote-debugging-port=9221 --remote-debugging-address=0.0.0.0 --no-sandbox --disable-gpu --disable-dev-shm-usage >> /tmp/wrap.log 2>&1 &
    sleep 3
    if ! check_port "$PORT_9221"; then
        log "Chromium failed to start on 9221, exiting"
        exit 1
    fi
    start_socat
}

# Detect the best tool for port checking
detect_port_tool

# Initial Chromium start
log "Starting initial Chromium..."
chromium --headless=new --remote-debugging-port=9221 --remote-debugging-address=0.0.0.0 --no-sandbox --disable-gpu --disable-dev-shm-usage >> /tmp/wrap.log 2>&1 &
sleep 3
start_socat

# Monitoring loop
while true; do
    sleep 60
    if ! check_port "$PORT_9222"; then
        log "Socat not running, restarting..."
        start_socat
    fi
    if ! pgrep -f "chromium.*--remote-debugging-port=9221" > /dev/null; then
        log "Chromium not running, restarting..."
        restart_chromium
    elif ! curl -s http://127.0.0.1:9222/json >/dev/null 2>&1; then
        log "Chromium unresponsive, restarting..."
        restart_chromium
    fi
done