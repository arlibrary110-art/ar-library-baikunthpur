FROM php:8.2-apache-bookworm

# PHP's official 8.2 Bookworm Apache image already includes SQLite3 and PDO_SQLite.
# No extra PHP extension compilation is required for the live Google Sheets adapter.
RUN set -eux; \
    a2enmod rewrite headers deflate expires; \
    sed -ri 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf; \
    sed -ri 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html/

# Keep private application data available to the application while preventing
# direct web access through the private folder's own .htaccess.
RUN set -eux; \
    mkdir -p /opt/ar_library_private; \
    if [ -d /var/www/html/AR_Library_Private ]; then cp -a /var/www/html/AR_Library_Private/. /opt/ar_library_private/; fi; \
    chown -R www-data:www-data /var/www/html /opt/ar_library_private

EXPOSE 10000
CMD ["apache2-foreground"]
