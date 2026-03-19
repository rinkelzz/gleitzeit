FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_mysql

RUN a2enmod rewrite

RUN echo '<Directory /var/www/html>\n    AllowOverride All\n</Directory>' \
    >> /etc/apache2/apache2.conf
