FROM wordpress:php8.2-apache

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev

RUN docker-php-ext-install pdo pdo_mysql

# Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis
