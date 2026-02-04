FROM mcr.microsoft.com/playwright:v1.41.2-jammy

# Prevent timezone prompt
ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Asia/Kolkata

# Install PHP + required extensions
RUN apt-get update && apt-get install -y \
    php-cli php-pgsql php-curl \
    tzdata \
    && ln -fs /usr/share/zoneinfo/$TZ /etc/localtime \
    && dpkg-reconfigure -f noninteractive tzdata \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

CMD ["php", "index.php"]
