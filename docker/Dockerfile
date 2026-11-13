# GoDaddy Linux hosting supports PHP 8.2 and 8.3 on Apache.
# Build with: --build-arg PHP_VERSION=8.2  or  8.3
ARG PHP_VERSION=8.2
FROM composer:2 AS composer

FROM php:${PHP_VERSION}-apache

# Extensions commonly enabled on GoDaddy cPanel (Select PHP Version / MultiPHP).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        gettext \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        soap \
        sockets \
        zip \
    && a2enmod rewrite headers expires deflate \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/godaddy.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
