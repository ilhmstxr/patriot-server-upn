FROM php:8.4-fpm-alpine

# Install system dependencies, PHP extensions, Nginx, and Supervisor
RUN apk add --no-cache \
    bash \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libwebp-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    mysql-client \
    oniguruma-dev \
    icu-dev \
    nginx \
    supervisor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP Extensions (gd, pdo_mysql, intl, zip, exif, mbstring, bcmath, opcache, pcntl)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd pdo_mysql intl zip exif mbstring bcmath opcache pcntl

# Set PHP memory limit for local development
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Copy custom PHP config
COPY ./docker/php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www

# Copy application files (including pre-installed vendor/ and compiled public/build/ from host)
COPY --chown=www-data:www-data . .

# Configure Nginx & Supervisor using Local version configurations
COPY ./docker/nginx.local.conf /etc/nginx/http.d/default.conf
COPY ./docker/supervisord.local.conf /etc/supervisor/conf.d/supervisord.conf

# Setup required directories and set proper ownership using /var/www paths
RUN mkdir -p /var/log/supervisor /run/nginx /var/run \
    && chown -R www-data:www-data /var/log/nginx /var/lib/nginx /run/nginx /var/log/supervisor /var/www/storage /var/www/bootstrap/cache

# Set permissions for Local Entrypoint
COPY ./docker/entrypoint.local.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
