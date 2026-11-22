# Auto-detect untuk Render "Blueprint" (render.yaml).
# Blueprint lama menunjuk layanan ke ./api/Dockerfile; agar build dari folder api/
# tetap berhasil, TIDAK ada Dockerfile yang menyalin index.html di sini.
# Dockerfile utama (menyajikan UI + API satu domain) ada di root: ../Dockerfile
#
# Rekomendasi: hapus layanan ini dan buat Web Service dengan
#   Dockerfile Path = ./Dockerfile  (root repo, satu domain, tanpa CORS)

FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod headers \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/pulse-api.conf \
    && a2enconf pulse-api

COPY php.ini /usr/local/etc/php/conf.d/pulse.ini
COPY common.php auth.php dataset.php health.php migrate-schema.php bootstrap-admin.php .htaccess /var/www/html/

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT}>/g\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
