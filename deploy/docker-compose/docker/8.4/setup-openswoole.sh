#!/bin/bash

set -e

echo "Updating package list and installing build dependencies..."
apt-get update && apt-get install -y \
    build-essential \
    php8.4-dev \
    php8.4-pcntl \
    php8.4-sockets \
    libcurl4-openssl-dev \
    libssl-dev \
    && rm -rf /var/lib/apt/lists/*

echo "Removing existing Swoole/OpenSwoole configurations and extensions..."
rm -f /etc/php/8.4/cli/conf.d/*swoole*.ini || true
rm -f /etc/php/8.4/fpm/conf.d/*swoole*.ini || true
rm -f /usr/lib/php/20240924/openswoole.so || true
rm -f /usr/lib/php/20240924/swoole.so || true

echo "Checking PHP version and extensions..."
php -v
php -m | grep -E "(sockets|openssl|curl)" || echo "Some required extensions may be missing"

echo "Cleaning PECL cache..."
pecl clear-cache

echo "Uninstalling any existing Swoole/OpenSwoole..."
pecl uninstall openswoole -r || true
pecl uninstall swoole -r || true

echo "Installing OpenSwoole with proper configuration..."
printf "yes\nyes\nyes\nno\nno\n" | pecl install openswoole

echo "Verifying OpenSwoole installation..."
if [ ! -f "/usr/lib/php/20240924/openswoole.so" ]; then
    echo "ERROR: OpenSwoole extension file not found!"
    exit 1
fi

echo "Enabling sockets extension..."
for SAPIS in cli fpm; do
    phpenmod -v 8.4 -s $SAPIS sockets
done

echo "Creating OpenSwoole configuration with proper load order..."
for SAPIS in cli fpm; do
    CONF_DIR="/etc/php/8.4/${SAPIS}/conf.d"
    INI_FILE="${CONF_DIR}/30-openswoole.ini"
    echo "Creating configuration for ${SAPIS}..."
    mkdir -p "$CONF_DIR"
    
    # Create configuration with proper settings (30- ensures loading after sockets which is 20-)
    cat > "${INI_FILE}" << EOF
; OpenSwoole extension configuration
; Loaded after sockets extension (20-sockets.ini)
extension=openswoole.so

; OpenSwoole settings
openswoole.display_errors=1
openswoole.use_shortname=0
EOF
done

echo "Checking if sockets extension is loaded..."
php -m | grep sockets || echo "WARNING: sockets extension not found!"

echo "Testing OpenSwoole installation..."
php -r "
if (extension_loaded('openswoole')) {
    echo 'OpenSwoole successfully loaded!' . PHP_EOL;
    if (defined('OPENSWOOLE_VERSION')) {
        echo 'OpenSwoole version: ' . OPENSWOOLE_VERSION . PHP_EOL;
    } elseif (defined('SWOOLE_VERSION')) {
        echo 'OpenSwoole version: ' . SWOOLE_VERSION . PHP_EOL;
    } else {
        echo 'OpenSwoole version: unknown' . PHP_EOL;
    }
} else {
    echo 'ERROR: OpenSwoole not loaded!' . PHP_EOL;
    exit(1);
}
"

echo "OpenSwoole setup completed successfully!"