FROM php:8.3-apache

# Runtime-only image. The application source (including compiled assets and
# vendor/) is mounted from the host via docker-compose bind mount, so no
# Composer/Node.js are needed here and no source is COPY'd into the image.

# RouterOS API uses PHP sockets (ext-sockets), not curl.
RUN apt-get update && apt-get install -y \
        zip \
        unzip \
        libonig-dev \
        libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite mbstring sockets \
    && rm -rf /var/lib/apt/lists/*

# Serve the Slim front controller in public/ as the Apache document root
ENV APACHE_DOCUMENT_ROOT=/var/www/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Enable mod_rewrite (required by public/.htaccess routing)
RUN a2enmod rewrite

# Entrypoint: fixes bind-mount ownership of the SQLite database dir before
# Apache starts (chown as root, or chmod fallback for rootless Docker).
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]

EXPOSE 80
