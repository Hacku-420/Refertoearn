FROM php:8.1-apache

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . .

RUN chown -R www-data:www-data /var/www/html \
    && chmod 775 /var/www/html \
    && touch users.json error.log \
    && chmod 664 users.json error.log \
    && chown www-data:www-data users.json error.log

RUN composer install --no-dev --optimize-autoloader

RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

EXPOSE 80
