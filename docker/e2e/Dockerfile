# End-to-end harness image: a minimal real Laravel app that hosts the Restate Laravel package
# and serves it to a live Restate runtime over bidirectional HTTP/2 cleartext (h2c) via
# `php artisan restate:serve` (the SDK's AmpStreamingServer).
#
# Build context is the PACKAGE root (restate-sdk-laravel). The package itself is copied to
# /app and consumed by the app's Composer `path` repository (../../.. from the app dir = /app),
# while the SDK (qcodr/restate-sdk-php) is pulled from Packagist by the package's own require.
#
# Mirrors restate-sdk-php/conformance/Dockerfile.amp: plain PHP 8.4 CLI + amphp (NO ext-swoole),
# git/unzip for Composer dist extraction, ext-pcntl for `--workers` and graceful-shutdown
# signal handling. mbstring + sodium are already bundled in php:8.4-cli.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# The Restate Laravel package — the target of the app's `path` repository. Only the files
# Composer needs to resolve and autoload it (the package's own require-dev is ignored when it
# is consumed as a dependency).
COPY composer.json /app/composer.json
COPY src /app/src
COPY config /app/config

# The minimal Laravel app that lists the test services and serves them.
COPY tests/e2e/laravel-app /app/tests/e2e/laravel-app

WORKDIR /app/tests/e2e/laravel-app

# Resolves: laravel/framework (Packagist) + qcodr/restate-sdk-laravel (path -> /app) +
# qcodr/restate-sdk-php (Packagist, via the package require) + amphp/http-server.
RUN composer install --no-interaction --no-progress --optimize-autoloader

EXPOSE 9080

# Serve the configured endpoint over bidirectional HTTP/2 (amphp). Bind on all interfaces so
# the runtime can reach it via the compose network.
CMD ["php", "artisan", "restate:serve", "--host=0.0.0.0", "--port=9080"]
