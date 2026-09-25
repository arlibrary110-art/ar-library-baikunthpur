FROM php:8.2-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/html

# Runtime/build dependencies for the application and MongoDB PHP driver.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libssl-dev \
        libzip-dev \
        libsqlite3-dev \
        libsasl2-dev \
        pkg-config \
        unzip \
        ca-certificates; \
    docker-php-ext-install -j"$(nproc)" zip sqlite3; \
    pecl channel-update pecl.php.net; \
    printf "\n" | pecl install -f mongodb-2.5.2; \
    docker-php-ext-enable mongodb; \
    php -m | grep -E '^mongodb$'; \
    php -m | grep -E '^sqlite3$'; \
    php -r 'echo "MongoDB extension: ", phpversion("mongodb"), PHP_EOL;'; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

# Composer is copied from the official Composer image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache on Render listens on port 10000.
RUN set -eux; \
    a2enmod rewrite headers deflate expires; \
    sed -ri 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf; \
    sed -ri 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Install PHP dependencies before copying the full source for better Docker caching.
COPY composer.json /var/www/html/composer.json
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --ignore-platform-req=ext-mongodb

COPY . /var/www/html/

# Keep private library data outside the web root as expected by the application.
RUN set -eux; \
    mkdir -p /opt/ar_library_private; \
    if [ -d /var/www/html/AR_Library_Private ]; then \
        cp -a /var/www/html/AR_Library_Private/. /opt/ar_library_private/; \
    fi; \
    chown -R www-data:www-data /var/www/html /opt/ar_library_private

EXPOSE 10000
CMD ["apache2-foreground"]
