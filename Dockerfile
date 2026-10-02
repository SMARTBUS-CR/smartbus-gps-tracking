FROM php:8.3-cli-alpine

# Install system dependencies (including nginx and gettext for envsubst)
RUN apk add --no-cache \
    supervisor \
    nginx \
    gettext \
    bash \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    postgresql-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    mbstring \
    zip \
    exif \
    pcntl \
    bcmath \
    intl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory
WORKDIR /var/www/html

# Copy the dependency files first to take advantage of the Docker layer cache
COPY composer.json composer.lock ./

# Install PHP dependencies without running post-install scripts
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy the rest of the microservice code
COPY . .

# Generate the optimized autoloader
RUN composer dump-autoload --optimize

# Set the permissions Laravel needs
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Copy the configuration files
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf.template
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expose Render's default port
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]