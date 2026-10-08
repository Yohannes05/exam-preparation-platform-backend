# ---------- Stage 1: frontend assets ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------- Stage 2: composer dependencies ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction

# ---------- Stage 3: runtime ----------
FROM php:8.3-fpm
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor gettext-base libpq-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_pgsql pcntl mbstring dom xml \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
RUN rm -rf /app/tests /app/node_modules \
    && mkdir -p storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/framework/testing storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf.template /etc/nginx/nginx-site.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && rm -f /etc/nginx/sites-enabled/default

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=10000

EXPOSE 10000
ENTRYPOINT ["/entrypoint.sh"]
