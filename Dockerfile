FROM php:8.4-cli

# Install system dependencies and PHP extensions (MySQL & PostgreSQL) + Supervisor
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    supervisor \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application files
COPY . .

# Install PHP production dependencies
RUN composer install --no-dev --optimize-autoloader

# Ensure storage subdirectories exist & set permissions
RUN mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache/data storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

# Default port for Render
ENV PORT=10000
EXPOSE 10000

ENV PHP_CLI_SERVER_WORKERS=10

CMD ["/var/www/html/docker/entrypoint.sh"]
