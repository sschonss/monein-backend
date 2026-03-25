FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libonig-dev \
    unzip \
    git \
    && docker-php-ext-install pdo_pgsql mbstring bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0"]
