FROM php:8.2-apache

# Evitar prompts interativos durante a instalação
ENV DEBIAN_FRONTEND=noninteractive

# Instalar dependências do sistema e o Google Chrome
RUN apt-get update && apt-get install -y \
    wget \
    gnupg \
    unzip \
    libxi6 \
    libnss3 \
    libatk-bridge2.0-0 \
    libdrm2 \
    libxkbcommon0 \
    libgbm1 \
    libasound2 \
    libx11-xcb1 \
    libxcb1 \
    && wget -q -O /tmp/chrome.deb https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb \
    && apt-get install -y /tmp/chrome.deb \
    && rm /tmp/chrome.deb \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Baixar o ChromeDriver correspondente ou gerenciar pelo Selenium (o Chrome moderno já gerencia bem)
# Instalar extensões PHP úteis
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Configurar o Apache para escutar na porta que o Render exige ($PORT)
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Copiar os arquivos do projeto
COPY . /var/www/html/
WORKDIR /var/www/html/

# Ajustar permissões
RUN chown -R www-data:www-data /var/www/html
