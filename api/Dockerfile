FROM php:8.3-apache

# Gabungan: halaman aplikasi di / DAN API di /api — satu service, satu domain.
RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod headers expires \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/pulse-app.conf \
    && a2enconf pulse-app

COPY api/php.ini /usr/local/etc/php/conf.d/pulse.ini

# Kebijakan cache/Vary untuk aset statis (hemat bandwidth).
COPY .htaccess /var/www/html/.htaccess

# Halaman aplikasi: https://<domain>/
COPY index.html /var/www/html/index.html

# API: https://<domain>/api/...
COPY api/ /var/www/html/api/

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT}>/g\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
