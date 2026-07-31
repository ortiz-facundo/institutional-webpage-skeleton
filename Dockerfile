# syntax=docker/dockerfile:1.7

FROM php:8.4-apache

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        default-libmysqlclient-dev \
        libzip-dev \
        zlib1g-dev \
        pkg-config; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    docker-php-ext-install \
        intl \
        opcache \
        pdo_mysql \
        zip; \
    pecl install apcu; \
    docker-php-ext-enable apcu

RUN set -eux; \
    a2enmod rewrite headers; \
    a2dissite 000-default

COPY apache/skeleton.conf /etc/apache2/sites-available/skeleton.conf
RUN a2ensite skeleton

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/apcu.ini    /usr/local/etc/php/conf.d/apcu.ini

RUN set -eux; \
    mkdir -p /var/www/html/var /var/www/html/public; \
    chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html

EXPOSE 80
