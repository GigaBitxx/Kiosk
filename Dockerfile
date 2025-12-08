FROM php:8.2-cli

# Install MySQL extensions needed by the app
RUN docker-php-ext-install mysqli pdo_mysql

WORKDIR /app
COPY . /app

# Railway sets PORT; fall back to 8000 locally
ENV PORT=8000

# Serve the project from the repository root
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /app"]

