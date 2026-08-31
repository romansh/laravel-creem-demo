# ================= BASE =================
FROM php:8.4-cli AS php-base

RUN apt-get update && apt-get install -y \
    libpq-dev libzip-dev unzip procps \
    libcurl4-openssl-dev libssl-dev curl gosu \
    libmagickwand-dev imagemagick \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
    libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo pdo_pgsql zip pcntl exif gd intl bcmath \
    && pecl install imagick \
    && docker-php-ext-enable imagick pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install Node.js (used only at build time)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && \
    apt-get install -y nodejs

# Create app user
RUN groupadd -r www-data || true && useradd -r -g www-data -s /bin/bash www-data || true
WORKDIR /var/www/html
RUN mkdir -p /var/www/html /var/www/.npm && chown -R www-data:www-data /var/www

# ================= BUILDER =================
FROM php-base AS php-builder

USER www-data

# Install PHP dependencies
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Prevent Puppeteer from downloading Chromium
ENV PUPPETEER_SKIP_DOWNLOAD=true

# Install Node dependencies
COPY --chown=www-data:www-data package.json package-lock.json ./
RUN npm install

# Copy application source
COPY --chown=www-data:www-data . .

# Laravel build steps
# Ensure runtime directories exist before running artisan/package discovery
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache storage/framework \
    && chmod -R 775 storage bootstrap/cache storage/framework || true

RUN composer dump-autoload
RUN php artisan package:discover --ansi
RUN npm run build

# Ensure writable runtime dirs (re-apply ownership after build)
RUN mkdir -p storage bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache || true

# Clean npm cache
RUN npm cache clean --force

# ================= FINAL (APP / OCTANE) =================
FROM php-base AS php-app-base

# Install OpenSwoole only for Octane
RUN pecl install openswoole \
    && docker-php-ext-enable openswoole \
    && echo -e "openswoole.display_errors=1\nopenswoole.use_shortname=0" > /usr/local/etc/php/conf.d/20-openswoole.ini

# Production PHP config
COPY docker/php.app.prod/php.ini /usr/local/etc/php/conf.d/99-custom.ini
COPY docker/php.app.prod/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
RUN echo "disable_functions=" > /usr/local/etc/php/conf.d/disable_functions.ini

# Copy built app from builder
COPY --from=php-builder --chown=www-data:www-data /var/www/html /var/www/html

# Entrypoint
USER root
COPY docker/php.app.prod/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=9501", "--workers=4"]
