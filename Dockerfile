FROM php:8.2-cli

# Install required PHP extensions (database + OPcache for faster cold starts)
RUN docker-php-ext-install mysqli pdo_mysql opcache

WORKDIR /app
COPY . /app

# Railway sets PORT; fall back to 8000 locally
ENV PORT=8000

# Enable OPcache (including for the built-in server) to speed up script loading.
# These -d flags apply at runtime without needing a custom php.ini.
CMD ["sh", "-c", "php -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.memory_consumption=128 -d opcache.interned_strings_buffer=16 -d opcache.max_accelerated_files=20000 -d opcache.validate_timestamps=0 -S 0.0.0.0:${PORT} -t /app"]

