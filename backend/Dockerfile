FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY public/ /var/www/html/

RUN a2enmod rewrite

EXPOSE 80
