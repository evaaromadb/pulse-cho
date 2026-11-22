FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/pulse-api.conf \
    && a2enconf pulse-api

COPY php.ini /usr/local/etc/php/conf.d/pulse.ini
COPY common.php auth.php dataset.php health.php .htaccess /var/www/html/

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT}>/g\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
