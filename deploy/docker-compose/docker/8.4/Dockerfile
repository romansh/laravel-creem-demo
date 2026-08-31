FROM sail-8.4-app:latest

# Refresh Yarn apt key from source to avoid NO_PUBKEY failures inherited from base image.
RUN mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://dl.yarnpkg.com/debian/pubkey.gpg | gpg --batch --yes --dearmor -o /etc/apt/keyrings/yarn.gpg

# Install dependencies required for OpenSwoole
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# Remove regular Swoole if installed
RUN pecl uninstall -r swoole || true

RUN rm -f /etc/php/8.4/cli/conf.d/20-xdebug.ini \
    && rm -f /etc/php/8.4/fpm/conf.d/20-xdebug.ini

# Copy setup script
COPY ./setup-openswoole.sh /tmp/setup-openswoole.sh

# Give execution permissions and run the script
RUN chmod +x /tmp/setup-openswoole.sh && /tmp/setup-openswoole.sh && rm /tmp/setup-openswoole.sh

# Override Sail entrypoint to prepare writable runtime directories on each start.
COPY ./start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

