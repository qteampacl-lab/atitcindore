FROM php:8.1-apache

RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html/webroot

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
/etc/apache2/sites-available/*.conf \
/etc/apache2/apache2.conf \
/etc/apache2/conf-available/*.conf

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
