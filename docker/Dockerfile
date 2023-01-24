FROM php:8.2-fpm

RUN apt-get update -y && apt-get install -y libicu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

ENV PHP_IDE_CONFIG="serverName=xdebug"

WORKDIR /var/www