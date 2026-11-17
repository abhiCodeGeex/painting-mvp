# Development image: PHP 8.4 CLI + extensions + Composer + Node 22
FROM php:8.4-cli-bookworm

ARG UID=1000
ARG GID=1000

COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates gnupg postgresql-client \
    && install-php-extensions pdo_pgsql pgsql redis intl zip gd exif bcmath pcntl opcache \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd -g ${GID} app && useradd -m -u ${UID} -g app app

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
USER app

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
