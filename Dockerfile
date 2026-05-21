FROM php:8.1-apache

RUN apt-get update \
  && apt-get install -y libzip-dev zip unzip libonig-dev libxml2-dev \
  && docker-php-ext-install pdo pdo_mysql mysqli \
  && a2enmod rewrite \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT /var/www/html

# Make Apache use APACHE_DOCUMENT_ROOT when changed
RUN sed -ri -e 's!DocumentRoot /var/www/html!DocumentRoot ${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!<Directory /var/www/html>!<Directory ${APACHE_DOCUMENT_ROOT}>!g' /etc/apache2/apache2.conf

# Copy application files into the document root
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
