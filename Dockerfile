FROM php:8.2-apache-bookworm

# The application uses SQLite3 internally as a local SQL engine for the
# Google Sheets adapter. MySQL and OPcache compilation are not required.
RUN set -eux; \
    apt-get -o Acquire::Retries=5 update; \
    apt-get -o Acquire::Retries=5 install -y --no-install-recommends libsqlite3-dev; \
    docker-php-ext-install -j1 sqlite3; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod rewrite headers deflate expires

WORKDIR /var/www/html
COPY . /var/www/html/

# Keep the private application data inside the image but outside the web root.
RUN set -eux; \
    mkdir -p /opt/ar_library_private; \
    if [ -d /var/www/html/AR_Library_Private ]; then cp -a /var/www/html/AR_Library_Private/. /opt/ar_library_private/; fi; \
    chown -R www-data:www-data /var/www/html /opt/ar_library_private

# Render's Docker services can receive traffic on port 10000.
RUN set -eux; \
    sed -ri 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf; \
    sed -ri 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000
CMD ["apache2-foreground"]
