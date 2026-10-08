FROM dunglas/frankenphp:php8.4

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_pgsql redis intl zip gd

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --optimize-autoloader

EXPOSE 8000

CMD ["frankenphp", "php-server", "--root", "public/", "--listen", ":8000"]