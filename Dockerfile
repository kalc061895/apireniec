FROM php:8.2-apache

# Instalar extensiones necesarias y git/unzip para composer
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libxml2-dev \
    git \
    unzip \
    && docker-php-ext-install curl xml \
    && a2enmod rewrite

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html/

# Instalar dependencias de PHP sin archivos de desarrollo
RUN composer install --no-dev --optimize-autoloader

EXPOSE 80

CMD ["apache2-foreground"]