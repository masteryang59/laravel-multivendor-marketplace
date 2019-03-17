# syntax=docker/dockerfile:1

# --- Frontend assets ---------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY vite.config.js ./
COPY resources ./resources
RUN npx vite build

# --- PHP dependencies --------------------------------------------------------
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# --- Runtime -----------------------------------------------------------------
FROM php:8.4-apache AS app
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libsqlite3-dev \
 && docker-php-ext-install pdo_mysql pdo_sqlite bcmath zip opcache \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
 && rm -rf node_modules .cache tests \
 && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
ENV PROMOTE_USER=true