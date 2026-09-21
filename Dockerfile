FROM php:8.2-apache

RUN docker-php-ext-install mysqli opcache \
    && a2enmod rewrite headers deflate expires


# Enable PHP OPcache and compress static assets to reduce Render response time.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.validate_timestamps=2'; \
      echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/ar-library-opcache.ini

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /opt/ar_library_private \
    && if [ -d /var/www/html/AR_Library_Private ]; then cp -a /var/www/html/AR_Library_Private/. /opt/ar_library_private/; fi \
    && rm -rf /var/www/html/AR_Library_Private \
    && chown -R www-data:www-data /var/www/html /opt/ar_library_private

# Render provides PORT at runtime; Apache listens on 10000 by default for this image.
RUN sed -ri 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf \
    && sed -ri 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000
CMD ["apache2-foreground"]
