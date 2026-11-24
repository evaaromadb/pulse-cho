# Docker image untuk deploy PULSE CHO sebagai SATU web service:
#   /            -> index.html (halaman aplikasi)
#   /api/*.php   -> API PHP (auth, dataset, health)
#
# Build context = root repo. Di Render: New > Web Service > Docker,
# Dockerfile Path = ./Dockerfile (bukan ./api/Dockerfile).
#
# CATATAN: ./api/Dockerfile adalah salinan identik (untuk build context api/).
# Jika mengubah Dockerfile ini, ubah juga ./api/Dockerfile.

FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod headers expires \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/pulse-app.conf \
    && a2enconf pulse-app

# Konfigurasi PHP aplikasi (upload 250 MB, memory 1.5 GB, tanpa output buffering)
COPY api/php.ini /usr/local/etc/php/conf.d/pulse.ini

# Kebijakan cache/Vary untuk aset statis (hemat bandwidth).
COPY .htaccess /var/www/html/.htaccess

# Halaman aplikasi di root: https://<domain>/
COPY index.html /var/www/html/index.html

# API di subfolder: https://<domain>/api/...  (satu domain => tanpa CORS)
COPY api/ /var/www/html/api/

# Kredensial TIDAK disertakan. Set environment di Render, atau, jika memakai
# secret file, mount file-nya dan set PULSE_TIDB_CA_PATH.

ENV PORT=10000
EXPOSE 10000

CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT}>/g\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
