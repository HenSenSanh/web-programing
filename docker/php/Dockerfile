FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y \
       unzip \
       git \
       libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY app/ /var/www/html/
COPY composer.json /var/www/html/composer.json

# Cài đặt vendor nếu có
RUN composer install --no-interaction --prefer-dist || true

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

RUN chown -R www-data:www-data /var/www/html

RUN a2enmod rewrite

EXPOSE 80
