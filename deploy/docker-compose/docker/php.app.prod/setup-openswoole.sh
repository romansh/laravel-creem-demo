#!/bin/bash

echo "Creating OpenSwoole configuration..."

# Determine the correct path to PHP configuration
PHP_INI_DIR=$(php --ini | grep "Scan for additional .ini files" | cut -d: -f2 | xargs)

# If not found via --ini, try standard paths
if [ -z "$PHP_INI_DIR" ] || [ "$PHP_INI_DIR" = "(none)" ]; then
    # Try to find it via php-config
    if command -v php-config >/dev/null 2>&1; then
        PHP_INI_DIR=$(php-config --ini-dir)
    fi
fi

# If still not found, use default Docker paths
if [ -z "$PHP_INI_DIR" ] || [ "$PHP_INI_DIR" = "(none)" ]; then
    if [ -d "/usr/local/etc/php/conf.d" ]; then
        PHP_INI_DIR="/usr/local/etc/php/conf.d"
    elif [ -d "/etc/php/conf.d" ]; then
        PHP_INI_DIR="/etc/php/conf.d"
    else
        echo "Error: Could not find PHP configuration directory"
        echo "Available directories:"
        find /usr/local/etc /etc -name "conf.d" -type d 2>/dev/null || true
        exit 1
    fi
fi

echo "Using PHP configuration directory: $PHP_INI_DIR"

# Create the directory if it doesn't exist
mkdir -p "$PHP_INI_DIR"

INI_FILE="${PHP_INI_DIR}/20-openswoole.ini"

echo "Creating OpenSwoole configuration file: $INI_FILE"

# Remove old config if it exists
if [ -f "${INI_FILE}" ]; then
    echo "Old config found, removing: ${INI_FILE}"
    rm -f "${INI_FILE}"
fi

# Create a new config
cat > "${INI_FILE}" << EOF
extension=openswoole.so
openswoole.display_errors=1
openswoole.use_shortname=0
EOF

echo "OpenSwoole configuration created successfully"

# Verify installation
echo "Verifying OpenSwoole installation..."
php -m | grep -i openswoole && echo "✓ OpenSwoole extension loaded" || echo "✗ OpenSwoole extension not found"