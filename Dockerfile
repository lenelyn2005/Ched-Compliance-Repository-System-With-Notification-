# CHED Compliance Repository — production image for Render (Docker runtime)
# One container runs: Apache + PHP (web), the Ratchet WebSocket server, and
# the email-queue worker, all under supervisord.

FROM php:8.3-apache-bookworm

# --- System packages + PHP extensions -------------------------------------
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor unzip ca-certificates \
    && install-php-extensions pdo_mysql gd zip intl bcmath opcache exif sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# --- Apache ----------------------------------------------------------------
RUN a2enmod rewrite headers remoteip proxy proxy_http proxy_wstunnel \
    && a2dissite 000-default
COPY docker/apache-vhost.conf /etc/apache2/sites-available/ched.conf
RUN a2ensite ched \
    && sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf

# --- PHP config ------------------------------------------------------------
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-ched.ini"

WORKDIR /var/www/html

# --- Composer dependencies (cached layer) ----------------------------------
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# --- Application -----------------------------------------------------------
COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p logs system/storage/cache uploads/avatars /var/www/storage/documents /tmp/ched_temp \
    && chown -R www-data:www-data logs system uploads websocket /var/www/storage /tmp/ched_temp \
    && touch measure_baseline_log.jsonl && chown www-data:www-data measure_baseline_log.jsonl \
    && chmod +x docker/entrypoint.sh docker/email-queue-loop.sh

COPY docker/supervisord.conf /etc/supervisor/supervisord.conf

# Render injects PORT (default 10000). Container-level defaults below; real
# secrets come from the Render dashboard / render.yaml, never from the image.
ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false \
    SECURE_STORAGE_PATH=/var/www/storage/documents/ \
    TEMP_PATH=/tmp/ched_temp/ \
    WS_PUBLIC_PATH=/ws \
    WS_PORT=8080 \
    WS_INTERNAL_PORT=8090 \
    EMAIL_DISABLE_SPAWN=1 \
    PHP_CLI_BINARY=/usr/local/bin/php

EXPOSE 10000

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
