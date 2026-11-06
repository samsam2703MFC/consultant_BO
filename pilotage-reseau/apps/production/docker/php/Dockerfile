FROM php:8.2-apache

# Instalacja rozszerzeń PHP
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Ustaw DocumentRoot na /var/www/html/public
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# Włącz mod_rewrite (ale NIE php8.2!)
RUN a2enmod rewrite

# Dodaj dodatkowy handler dla .php na wypadek błędnej konfiguracji
RUN echo '<FilesMatch \.php$>\n    SetHandler application/x-httpd-php\n</FilesMatch>' > /etc/apache2/conf-enabled/serve-php.conf

# Dodaj cron
RUN apt-get update && apt-get install -y cron

# Dodaj nano
RUN apt-get update && apt-get install -y nano

# Dodaj niestandardowy php.ini
COPY custom-php.ini /usr/local/etc/php/conf.d/99-custom.ini



# Kopiujemy cert do obrazu
COPY ./certs/rootCA.pem /usr/local/share/ca-certificates/mkcert-rootCA.crt

# Instalujemy go do systemu
RUN update-ca-certificates
