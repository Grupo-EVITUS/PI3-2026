FROM composer:2 AS composer
FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates git libonig-dev libssl-dev unzip \
    && docker-php-ext-install -j4 mbstring \
    && MAKEFLAGS=-j4 pecl install mongodb-2.5.0 \
    && docker-php-ext-enable mongodb \
    && php --ri mongodb | grep -q 'libmongoc SSL => enabled' \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist
COPY bin/ bin/
COPY public/ public/
COPY src/ src/
COPY templates/ templates/
COPY tests/ tests/
COPY router.php ./

EXPOSE 8080
