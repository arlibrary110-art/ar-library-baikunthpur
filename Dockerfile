# AR Library - Render production Dockerfile
# PHP 8.2 + Apache + SQLite + MongoDB
# Permanent attendance QR is handled by the application and is NOT changed here.

FROM php:8.2.33-apache-bullseye

ENV APACHE_DOCUMENT_ROOT=/var/www/html \
    COMPOSER_ALLOW_SUPERUSER=1

# Build/runtime libraries. Keep the MongoDB extension version explicit so
# Render builds do not unexpectedly change when PECL publishes a new release.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        unzip \
        pkg-config \
        libssl-dev \
        libcurl4-openssl-dev \
        libzip-dev \
        libsqlite3-dev \
        libsasl2-dev; \
    docker-php-ext-install -j"$(nproc)" zip sqlite3; \
    pecl channel-update pecl.php.net; \
    printf "\n" | pecl install mongodb-2.5.2; \
    docker-php-ext-enable mongodb; \
    php -m | grep -Fx mongodb; \
    php -m | grep -Fx sqlite3; \
    php -r 'echo "MongoDB driver ", phpversion("mongodb"), PHP_EOL;'; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

# Composer from the official Composer image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Render uses port 10000 for the web service.
RUN set -eux; \
    a2enmod rewrite headers deflate expires; \
    sed -ri 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf; \
    sed -ri 's/<VirtualHost \\*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

# Install Composer dependencies before copying application source.
COPY composer.json ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Copy application.
COPY . .

# Keep private library data outside the public web root when present.
RUN set -eux; \
    mkdir -p /opt/ar_library_private; \
    if [ -d /var/www/html/AR_Library_Private ]; then \
        cp -a /var/www/html/AR_Library_Private/. /opt/ar_library_private/; \
    fi; \
    chown -R www-data:www-data /var/www/html /opt/ar_library_private

EXPOSE 10000

CMD ["apache2-foreground"]
