FROM php:8.3-apache

# MySQL drivers + GD (TCPDF needs it for PNG images with alpha)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo_mysql mysqli gd \
 && rm -rf /var/lib/apt/lists/*

# Allow larger photo uploads / cropped-image POSTs (full-resolution crops).
RUN { \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=40M'; \
      echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# Serve from the public/ folder so config.php (at the project root) is never web-accessible
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

EXPOSE 80
