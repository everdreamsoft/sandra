# syntax=docker/dockerfile:1.7

# ─── Stage 1: Composer dependencies ─────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

# ─── Stage 2: Runtime ───────────────────────────────────────────────────────
FROM php:8.3-cli-alpine

RUN apk add --no-cache bash \
    && docker-php-ext-install pdo_mysql opcache \
    && php -m | grep -qi curl || (apk add --no-cache curl-dev && docker-php-ext-install curl)

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY bin ./bin
COPY composer.json composer.lock ./

RUN mkdir -p /app/dependencies/cache \
    && chmod -R 777 /app/dependencies/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8090

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "bin/mcp-http-server.php", "--host=0.0.0.0", "--port=8090"]
