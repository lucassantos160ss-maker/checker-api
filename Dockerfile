FROM php:8.2-apache

# Instalar extensões necessárias para cURL e requisições
RUN apt-get update && apt-get install -y libcurl4-openssl-dev pkg-config libssl-dev \
    && docker-php-ext-install curl json mbstring

# Copiar os arquivos do projeto para a pasta pública do Apache
COPY . /var/www/html/

# Expor a porta padrão que o Render utiliza
EXPOSE 80
