FROM hyperf/hyperf:8.3-alpine-v3.21-swoole

ARG TIMEZONE=UTC

ENV TIMEZONE=${TIMEZONE} \
    APP_ENV=production \
    SCAN_CACHEABLE=true

RUN set -ex \
    && cd /etc/php* \
    && { \
        echo "memory_limit=128M"; \
        echo "upload_max_filesize=16M"; \
        echo "post_max_size=16M"; \
        echo "date.timezone=${TIMEZONE}"; \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=1"; \
        echo "opcache.memory_consumption=64"; \
        echo "opcache.interned_strings_buffer=8"; \
        echo "opcache.max_accelerated_files=5000"; \
        echo "opcache.validate_timestamps=0"; \
        echo "opcache.jit_buffer_size=32M"; \
        echo "opcache.jit=tracing"; \
        echo "opcache.fast_shutdown=1"; \
        echo "zend.assertions=-1"; \
        echo "realpath_cache_size=4096k"; \
        echo "realpath_cache_ttl=600"; \
    } | tee conf.d/99_overrides.ini \
    && ln -sf /usr/share/zoneinfo/${TIMEZONE} /etc/localtime \
    && echo "${TIMEZONE}" > /etc/timezone \
    && rm -rf /var/cache/apk/* /tmp/* /usr/share/man \
    && echo -e "\033[42;37m Build Completed :).\033[0m\n"

WORKDIR /opt/www/nano

COPY nano/composer.json nano/composer.lock ./

RUN composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction \
    && composer clear-cache \
    && rm -rf /root/.composer

COPY nano/ ./