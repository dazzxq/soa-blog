FROM php:8.2-fpm-alpine

# nginx serves HTTP :80 and forwards .php to php-fpm via FastCGI.
# supervisord is PID 1, runs both processes; wget is for healthcheck.
# mbstring (with oniguruma) — required by mb_strlen / mb_strtolower used in controllers.
RUN apk add --no-cache nginx supervisor wget oniguruma \
    && apk add --no-cache --virtual .build-deps oniguruma-dev \
    && docker-php-ext-install pdo pdo_mysql opcache mbstring \
    && apk del --no-network .build-deps

WORKDIR /app

# Composer install — copy lockfile so versions are reproducible across builds.
COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# App source
COPY public/ ./public/
COPY src/    ./src/

# Process supervisor + nginx
COPY nginx.conf       /etc/nginx/http.d/default.conf
COPY supervisord.conf /etc/supervisord.conf

# php-fpm listens on 9000; nginx listens on 80
EXPOSE 80
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
