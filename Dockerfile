FROM mcr.microsoft.com/playwright:v1.58.1-jammy

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Asia/Kolkata

# Install PHP
RUN apt-get update && apt-get install -y \
    php-cli php-pgsql php-curl tzdata \
    && ln -fs /usr/share/zoneinfo/$TZ /etc/localtime \
    && dpkg-reconfigure -f noninteractive tzdata \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

# Install Playwright dependency (version will match the image)
RUN npm init -y && npm install playwright@1.58.1

CMD ["php", "index.php"]
