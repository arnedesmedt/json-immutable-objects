FROM php:8.2-fpm

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

ENV PHP_IDE_CONFIG="serverName=xdebug"

WORKDIR /var/www