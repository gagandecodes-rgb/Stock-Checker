FROM php:8.2-cli-bullseye

# System deps
RUN apt-get update && apt-get install -y \
    git curl unzip wget gnupg \
    libpq-dev \
    chromium \
    libnss3 libatk1.0-0 libatk-bridge2.0-0 \
    libcups2 libxkbcommon0 libxcomposite1 \
    libxdamage1 libxrandr2 libgbm1 libasound2 \
    fonts-liberation \
    nodejs npm \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql

# Playwright
RUN npm install -g playwright
RUN playwright install chromium

WORKDIR /app
COPY . /app

CMD ["php", "index.php"]
