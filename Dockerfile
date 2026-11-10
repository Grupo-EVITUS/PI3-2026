# SAA — imagem da aplicação (PHP 8.4 + extensão MongoDB).
# Serve com o servidor embutido do PHP: adequado para desenvolvimento, demonstração e homologação.
# Em produção, prefira Apache/Nginx + PHP-FPM com o DocumentRoot em public/ (o public/.htaccess já está pronto).
FROM php:8.4-cli-bookworm

ARG MONGODB_EXT_VERSION=2.5.0

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libssl-dev libcurl4-openssl-dev pkg-config unzip; \
    pecl install "mongodb-${MONGODB_EXT_VERSION}"; \
    docker-php-ext-enable mongodb; \
    rm -rf /tmp/pear /var/lib/apt/lists/*; \
    php -m | grep -q '^mongodb$'

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependências primeiro (camada reaproveitada enquanto composer.lock não mudar).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

COPY . .
RUN set -eux; \
    composer dump-autoload --no-dev --optimize --no-interaction; \
    mkdir -p var/sessions var/mail; \
    chown -R www-data:www-data var; \
    chmod +x docker/entrypoint.sh

USER www-data

ENV APP_ENV=development \
    MONGODB_URI=mongodb://mongo:27017 \
    MONGODB_DB=saa \
    PHP_CLI_SERVER_WORKERS=4

EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=5s --start-period=10s --retries=6 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8000/health") === false ? 1 : 0);'

ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000", "router.php"]
