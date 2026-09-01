FROM php:8.2-apache

RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl

# Cria a pasta de trabalho, define permissões para o Apache e ajusta o diretório
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

COPY . /var/www/html/

EXPOSE 80
