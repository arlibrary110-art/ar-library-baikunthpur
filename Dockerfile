FROM php:8.2.33-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/html

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libssl-dev \
        libzip-dev \
        libsqlite3-dev \
        libsasl2-dev \
        pkg-config \
        unzip \
        git \
        ca-certificates; \
    docker-php-ext-install -j"$(nproc)" zip sqlite3; \
    pecl channel-update pecl.php.net; \
    printf "\n" | pecl install mongodb-2.5.2; \
    docker-php-ext-enable mongodb; \
    php -m | grep -i '^mongodb$'; \
    php -m | grep -i '^sqlite3$'; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

RUN a2enmod rewrite headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html/

RUN if [ -f composer.json ]; then \
        composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader; \
    fi

RUN chown -R www-data:www-data /var/www/html

ENV PORT=10000

EXPOSE 10000

CMD ["bash", "-lc", "sed -i 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf; sed -i 's/:80>/:10000>/g' /etc/apache2/sites-available/000-default.conf; apache2-foreground"]
