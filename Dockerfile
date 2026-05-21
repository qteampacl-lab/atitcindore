FROM php:8.1-apache

RUN apt-get update \
  && apt-get install -y libzip-dev zip unzip libonig-dev libxml2-dev \
  && docker-php-ext-install pdo pdo_mysql mysqli \
  && a2enmod rewrite \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT /var/www/html
