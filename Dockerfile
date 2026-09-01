FROM php:8.2-apache

# Instalar dependências do sistema e o Google Chrome
RUN apt-get update && apt-get install -y \
    wget \
    gnupg \
    unzip \
    libxi6 \
    libgconf-2-4 \
    libnss3 \
    libatk-bridge2.0-0 \
    libdrm2 \
    libxkbcommon0 \
    libgbm1 \
    libasound2 \
    && wget -q -O /tmp/chrome.deb https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb \
    && apt-get install -y /tmp/chrome.deb \
    && rm /tmp/chrome.deb \
    && apt-get clean

# Instalar extensões do PHP necessárias (exemplo)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copiar os arquivos do projeto para o diretório do Apache
COPY . /var/www/html/
WORKDIR /var/www/html/

# Ajustar permissões se necessário
RUN chown -R www-data:www-data /var/www/html
