#!/usr/bin/env bash
# exit on error
set -o errexit

# Instalar dependências do sistema para o Chrome rodar
apt-get update && apt-get install -y \
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
    libasound2

# Baixar e instalar a versão mais recente do Google Chrome
wget -q -O /tmp/chrome.deb https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
apt-get install -y /tmp/chrome.deb
rm /tmp/chrome.deb
