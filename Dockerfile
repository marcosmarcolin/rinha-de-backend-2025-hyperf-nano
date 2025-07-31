FROM php:8.4-alpine

ARG TIMEZONE=UTC

ENV TIMEZONE=${TIMEZONE}

RUN set -ex \
    && apk add --no-cache --virtual .build-deps \
        autoconf gcc g++ make pkgconfig brotli-dev \
    && apk add --no-cache \
        git bash curl tzdata \
        libstdc++ libcurl \
        openssl \
    \
    && pecl install swoole \
    && docker-php-ext-enable swoole \
    \
    && pecl install redis \
    && docker-php-ext-enable redis \
    \
    && cp /usr/share/zoneinfo/${TIMEZONE} /etc/localtime \
    && echo "${TIMEZONE}" > /etc/timezone \
    \
    && echo "memory_limit=128M" > /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "upload_max_filesize=16M" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "post_max_size=16M" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "date.timezone=${TIMEZONE}" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "opcache.memory_consumption=64" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "opcache.interned_strings_buffer=8" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "opcache.max_accelerated_files=5000" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "opcache.jit_buffer_size=32M" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "opcache.jit=tracing" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "opcache.fast_shutdown=1" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "zend.assertions=-1" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "realpath_cache_size=4096k" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    && echo "realpath_cache_ttl=600" >> /usr/local/etc/php/conf.d/99-overrides.ini \
    \
    && apk del .build-deps

WORKDIR /opt/www/nano

COPY nano/ ./