FROM php:8.2-apache

RUN docker-php-ext-install mysqli \
    && a2enmod rewrite headers

WORKDIR /var/www/html
COPY . /var/www/html/

# Keep private SQL/config files out of the public web root at runtime.
RUN rm -rf /var/www/html/AR_Library_Private \
    && chown -R www-data:www-data /var/www/html

ENV APACHE_DOCUMENT_ROOT=/var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
