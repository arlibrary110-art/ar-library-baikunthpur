FROM php:8.2-apache-bookworm

# PHP extensions required by the AR Library application.
# The Composer package mongodb/mongodb ^2.4 requires ext-mongodb ^2.4.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libssl-dev \
        libzip-dev \
        libsqlite3-dev \
        libcurl4-openssl-dev \
        libsasl2-dev \
        pkg-config \
        unzip; \
    docker-php-ext-install -j"$(nproc)" zip sqlite3; \
    pecl channel-update pecl.php.net; \
    printf '\n' | pecl install mongodb-2.5.3; \
    docker-php-ext-enable mongodb; \
    php --ri mongodb; \
    php -m | grep -i '^sqlite3$'; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN set -eux; \
    a2enmod rewrite headers deflate expires; \
    sed -ri 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf; \
    sed -ri 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY composer.json /var/www/html/composer.json
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . /var/www/html/

RUN set -eux; \
    mkdir -p /opt/ar_library_private; \
    if [ -d /var/www/html/AR_Library_Private ]; then cp -a /var/www/html/AR_Library_Private/. /opt/ar_library_private/; fi; \
    chown -R www-data:www-data /var/www/html /opt/ar_library_private

EXPOSE 10000
CMD ["apache2-foreground"]
