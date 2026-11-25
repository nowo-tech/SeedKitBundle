# PHP 8.2 Alpine for development and tests
FROM php:8.2-cli-alpine

RUN apk add --no-cache \
    git \
    unzip \
    bash \
    libzip-dev \
    libpq

# pdo_mysql / pdo_pgsql: Database suite on the MySQL, MariaDB and PostgreSQL services (make test-with-db, REQ-TEST-005)
RUN apk add --no-cache --virtual .pg-build postgresql-dev \
    && docker-php-ext-install -j2 zip pdo_mysql pdo_pgsql \
    && apk del .pg-build

# PCOV for code coverage
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
