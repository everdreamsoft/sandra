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

# Non-root runtime user (fixed UID/GID for predictable volume permissions).
RUN addgroup -g 1000 -S app \
    && adduser -u 1000 -S -G app -h /app app

WORKDIR /app

COPY --from=vendor --chown=app:app /app/vendor ./vendor
COPY --chown=app:app src ./src
COPY --chown=app:app bin ./bin
COPY --chown=app:app composer.json composer.lock ./

RUN mkdir -p /app/dependencies/cache \
    && chown -R app:app /app/dependencies \
    && chmod -R 755 /app/dependencies/cache

COPY --chown=app:app docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER app

EXPOSE 8090

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "bin/mcp-http-server.php", "--host=0.0.0.0", "--port=8090"]
