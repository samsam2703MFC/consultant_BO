# [297] PHP 8.3 (FPM) de l'ERP pour un serveur dont le PHP est trop ancien (Ubuntu 20.04 : PHP 7.4, plus de PHP 8
# en paquet depuis juin 2025). Construit et lancé par deploy/php_docker.sh ; Apache du serveur lui passe les .php de
# /production (proxy_fcgi). Extensions : celles que deploy/server.sh installe d'habitude (mysql, mbstring, curl, gd,
# zip, xml) + intl, bcmath, opcache ; pdftotext (factures PDF) ; composer 2 (dépendances de l'ERP).
FROM php:8.3-fpm

RUN apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils unzip git libzip-dev libpng-dev libjpeg62-turbo-dev \
      libfreetype6-dev libicu-dev libxml2-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli zip gd intl bcmath opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY erp.ini /usr/local/etc/php/conf.d/zz-erp.ini
COPY zz-erp-pool.conf /usr/local/etc/php-fpm.d/zz-erp-pool.conf
