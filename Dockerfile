FROM php:8.2-apache-bookworm

# Only libonig-dev is required for mbstring
# All other needed extensions (pdo_sqlite, exif, pcntl, bcmath) are built into PHP 8.2 core
# RouterOS API uses PHP sockets (ext-sockets), not curl
RUN apt-get update && apt-get install -y \
    zip \
    unzip \
    libonig-dev \
    libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite mbstring exif pcntl bcmath sockets \
    && rm -rf /var/lib/apt/lists/*

# Serve the Slim front controller in public/ as the Apache document root
ENV APACHE_DOCUMENT_ROOT=/var/www/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Enable mod_rewrite (required by public/.htaccess routing)
RUN a2enmod rewrite

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

EXPOSE 80