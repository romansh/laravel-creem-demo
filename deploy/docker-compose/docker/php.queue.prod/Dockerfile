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

ENV PUPPETEER_SKIP_DOWNLOAD=true

# Install Node dependencies
COPY --chown=www-data:www-data package.json package-lock.json ./
RUN npm install

# Copy application source
COPY --chown=www-data:www-data . .

# Laravel build steps
RUN composer dump-autoload
RUN php artisan package:discover --ansi
RUN npm run build

# Ensure writable runtime dirs
RUN mkdir -p storage bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache

# Clean npm cache
RUN npm cache clean --force

# ================= FINAL (QUEUE WORKER) =================
FROM php-base AS php-queue-base

# Production PHP config
COPY docker/php.queue.prod/php.ini /usr/local/etc/php/conf.d/99-custom.ini
COPY docker/php.queue.prod/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
RUN echo "disable_functions=" > /usr/local/etc/php/conf.d/disable_functions.ini

# Copy built app from php-builder
COPY --from=php-builder --chown=www-data:www-data /var/www/html /var/www/html

# Entrypoint
USER root
COPY docker/php.queue.prod/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "queue:work", "--sleep=3", "--tries=3", "--max-time=3600"]
