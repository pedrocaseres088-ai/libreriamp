#!/bin/sh
set -e

# Railway inyecta $PORT: Apache tiene que escuchar en ese puerto
PORT="${PORT:-80}"
sed -ri "s/^Listen 80\$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Crea las tablas la primera vez y sincroniza la contraseña del admin
php /var/www/html/db/init.php || echo "[init] AVISO: no se pudo inicializar la base de datos (revisá las variables DB_*)"

exec apache2-foreground
