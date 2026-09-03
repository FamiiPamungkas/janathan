FROM php:8.2-fpm-bookworm

# Only libonig-dev is required for mbstring
# All other needed extensions (pdo_sqlite, exif, pcntl, bcmath) are built into PHP 8.2 core
# RouterOS API uses PHP sockets (ext-sockets), not curl
RUN apt-get update && apt-get install -y \
    libonig-dev \
    libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite mbstring exif pcntl bcmath sockets \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy composer files first (for caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy source code
COPY . .

# Build assets (Node.js for Tailwind v4 + esbuild)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs && \
    npm install && \
    npm run build

EXPOSE 9000
CMD ["php-fpm"]