FROM php:8.3-cli-alpine

# Instalar dependencias del sistema (incluyendo nginx y gettext para envsubst)
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

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Definir el directorio de trabajo
WORKDIR /var/www/html

# Copiar archivos de dependencias primero para optimizar la caché de capas de Docker
COPY composer.json composer.lock ./

# Instalar dependencias de PHP sin ejecutar scripts de post-instalación
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copiar el resto del código del microservicio
COPY . .

# Generar el autoloader optimizado
RUN composer dump-autoload --optimize

# Configurar permisos requeridos por Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Copiar configuraciones
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf.template
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Exponer el puerto por defecto de Render
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]