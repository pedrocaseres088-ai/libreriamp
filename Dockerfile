FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends unzip git \
    && docker-php-ext-install mysqli \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencias PHP (phpdotenv + PHPMailer)
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-scripts --optimize-autoloader

COPY . .
RUN chmod +x docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

CMD ["./docker-entrypoint.sh"]


COPY . .
RUN chmod +x docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

CMD ["./docker-entrypoint.sh"]
