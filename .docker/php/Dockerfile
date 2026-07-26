FROM php:8.4-cli-alpine

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS openssl-dev \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && apk del .build-deps \
    && apk add --no-cache git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
