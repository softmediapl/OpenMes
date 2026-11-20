FROM golang:1.27.4-alpine@sha256:70bd7ddea8b6cbe5d6943cde279d6b67feb10cc924fb9100d1d8757c1575b280 AS roadrunner
WORKDIR /src
ADD --checksum=sha256:62cb109ee6487ae0a3c48b45254bafc3269a65f935e051c6b207cfac00fe3b1b https://codeload.github.com/roadrunner-server/roadrunner/tar.gz/321b817fab1056e404533ca1ddd200e77d1525fd /tmp/source.tar.gz
RUN tar -xzf /tmp/source.tar.gz --strip-components=1 -C /src && rm /tmp/source.tar.gz
ENV GOMAXPROCS=2 CGO_ENABLED=0
RUN go get github.com/klauspost/compress@v1.18.7 github.com/rabbitmq/amqp091-go@v1.13.0 golang.org/x/net@v0.62.0 google.golang.org/grpc@v1.83.2 && go mod verify
RUN go test -p 2 ./internal/... ./cmd/rr/...
RUN go build -p 2 -trimpath -ldflags="-s -w -X github.com/roadrunner-server/roadrunner/v2025/internal/meta.version=2025.1.15-security20261120 -X github.com/roadrunner-server/roadrunner/v2025/internal/meta.buildTime=2026-11-20T00:00:00Z" -o /usr/bin/rr ./cmd/rr && /usr/bin/rr -v

# Keep the PHP 8.3 security release, without a language-version migration.
FROM php:8.3.37-fpm-alpine3.24@sha256:8e8db93687494e48607b70cc7f7e9414d1cda0a393dfac5dae2580dff66a9fa4

RUN php -r 'if (PHP_VERSION_ID < 80337 || PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 3) exit(1);'
RUN printf 'session.upload_progress.enabled=0\n' > /usr/local/etc/php/conf.d/zzz-session-security.ini

# Install system dependencies
RUN apk upgrade --no-cache
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    zip \
    unzip \
    git \
    curl \
    oniguruma-dev \
    linux-headers \
    nodejs \
    npm

# Configure GD extension
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    zip \
    mbstring \
    opcache \
    gd \
    pcntl \
    sockets

# PHP upload / memory limits
COPY backend/docker-php-uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY backend/ .
COPY modules/ ./modules/
# Workspace UI package — backend/package.json depends on it via `file:../packages/ui`,
# which resolves to /var/www/packages/ui relative to this WORKDIR. Must be present
# before `npm ci` or the `@openmes/ui` import fails to resolve during `npm run build`.
COPY packages/ /var/www/packages/

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy the pinned RoadRunner binary without calling the GitHub API during each
# deployment. Laravel Octane discovers it in the project root.
COPY --from=roadrunner /usr/bin/rr /var/www/html/rr

# Install Node dependencies and build assets
RUN npm ci && npm run build

# Clean up node_modules + the build-only workspace sources to reduce image size
RUN rm -rf node_modules /var/www/packages

# Copy entrypoint script
COPY backend/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Expose port
EXPOSE 8000

# Set entrypoint
ENTRYPOINT ["docker-entrypoint.sh"]

# Serve with Laravel Octane on RoadRunner — a concurrent, in-memory app server
# (keeps the framework booted between requests; handles many simultaneous
# requests via a worker pool). Replaces the single-threaded `artisan serve`,
# which serialized requests and froze under any held connection.
#
# `--workers=auto` scales to CPU count; tune with --workers / --max-requests.
# Caveat: Octane keeps the app in memory across requests, so app/package state
# that assumes a fresh boot per request can leak. Validate the full app under
# Octane before production; to fall back, override CMD to:
#   php artisan serve --host=0.0.0.0 --port=8000   (dev only, single-threaded)
CMD ["php", "artisan", "octane:start", "--server=roadrunner", "--host=0.0.0.0", "--port=8000", "--workers=auto", "--max-requests=500"]

STOPSIGNAL SIGTERM
