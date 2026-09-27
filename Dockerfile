FROM composer:2 AS composer-dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        curl dom gd intl mbstring opcache pdo_mysql simplexml xml xmlreader xmlwriter zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/ports.conf /etc/apache2/ports.conf
COPY docker/php-production.ini /usr/local/etc/php/conf.d/zz-stagia-production.ini
COPY --chown=www-data:www-data . /var/www/html
COPY --from=composer-dependencies --chown=www-data:www-data /app/vendor /var/www/html/vendor

RUN mkdir -p \
        /var/www/html/output \
        /var/www/html/storage/certificates \
        /var/www/html/storage/communication \
        /var/www/html/storage/conventions \
        /var/www/html/storage/imports/student-imports \
        /var/www/html/storage/student-documents \
        /var/www/html/storage/student_documents \
        /var/www/html/tmp \
        /var/www/html/uploads/adhesions/logos \
        /var/www/html/uploads/certificates \
        /var/www/html/uploads/etablissements/documents \
        /var/www/html/uploads/etablissements/logos \
    && chown -R www-data:www-data \
        /var/www/html/output \
        /var/www/html/storage \
        /var/www/html/tmp \
        /var/www/html/uploads

EXPOSE 10000

CMD ["apache2-foreground"]
