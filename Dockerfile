FROM php:8.2-apache

RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl

# Permissões completas para o servidor web rodar sem bloqueios
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 777 /var/www/html

COPY . /var/www/html/

EXPOSE 80
