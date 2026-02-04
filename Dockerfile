FROM mcr.microsoft.com/playwright:v1.41.2-jammy

# Install PHP
RUN apt-get update && apt-get install -y \
    php-cli php-pgsql php-curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

CMD ["php", "index.php"]
