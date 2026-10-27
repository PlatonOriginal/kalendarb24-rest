FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql \
    && a2enmod ssl rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/app
