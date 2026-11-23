FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       unzip \
       git \
       libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json /var/www/html/composer.json
COPY app/ /var/www/html/
COPY database/ /var/www/database/

# Cài đặt vendor tối ưu cho môi trường production/cloud
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader || true

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

RUN chown -R www-data:www-data /var/www/html \
    && a2enmod rewrite

COPY docker/php/entrypoint.sh /usr/local/bin/dineflow-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/dineflow-entrypoint.sh \
    && chmod +x /usr/local/bin/dineflow-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/dineflow-entrypoint.sh"]
