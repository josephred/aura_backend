FROM php:8.4-cli

# Install system dependencies and PHP extensions (MySQL & PostgreSQL)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql zip

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
    && chmod -R 777 storage bootstrap/cache

# Default port for Render
ENV PORT=10000
EXPOSE 10000

# Clear view cache, run migrations, seed DB, and start server
CMD ["sh", "-c", "php artisan storage:link || true && php artisan config:clear && php artisan view:clear && php artisan migrate --force && php artisan db:seed --force && php artisan serve --host=0.0.0.0 --port=$PORT"]
