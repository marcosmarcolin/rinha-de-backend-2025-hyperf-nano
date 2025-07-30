FROM hyperf/hyperf:8.3-alpine-v3.21-swoole

ARG TIMEZONE=UTC

ENV TIMEZONE=${TIMEZONE} \
    APP_ENV=dev \
    SCAN_CACHEABLE=false

RUN set -ex \
    && cd /etc/php* \
    && { \
        echo "upload_max_filesize=128M"; \
        echo "post_max_size=128M"; \
        echo "memory_limit=1G"; \
        echo "date.timezone=${TIMEZONE}"; \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=1"; \
        echo "opcache.memory_consumption=128"; \
        echo "opcache.interned_strings_buffer=16"; \
        echo "opcache.max_accelerated_files=10000"; \
        echo "opcache.validate_timestamps=0"; \
        echo "opcache.jit_buffer_size=64M"; \
        echo "opcache.jit=tracing"; \
        echo "opcache.fast_shutdown=1"; \
        echo "opcache.huge_code_pages=1"; \
        echo "opcache.file_cache=/tmp/php-opcache"; \
        echo "opcache.file_cache_only=0"; \
        echo "realpath_cache_size=4096k"; \
        echo "realpath_cache_ttl=600"; \
        echo "zend.assertions=-1"; \
    } | tee conf.d/99_overrides.ini \
    && ln -sf /usr/share/zoneinfo/${TIMEZONE} /etc/localtime \
    && echo "${TIMEZONE}" > /etc/timezone \
    && rm -rf /var/cache/apk/* /tmp/* /usr/share/man \
    && echo -e "\033[42;37m Build Completed :).\033[0m\n"

WORKDIR /opt/www/nano

COPY nano/composer.json nano/composer.lock ./

RUN composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction && composer clear-cache

COPY nano/ ./