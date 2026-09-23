# syntax=docker/dockerfile:1

# The deployed image for ask.thekrausshaus.com, served by FrankenPHP.
#
# The draft alternative to docker/app/Dockerfile, which serves with
# `php artisan serve`. Both are one process in one container listening on :80;
# the difference is that this one is a real HTTP server. It serves the compiled
# assets off the filesystem instead of through a PHP worker, enforces
# max_execution_time (the CLI SAPI cannot), and drains in-flight connections on
# SIGTERM rather than cutting every open answer stream at deploy.
#
# SSE needs no coaxing here: output_buffering is off in php.ini and
# text/event-stream is excluded from Caddy's encoder, so a frame reaches the
# ingress -- which already sets proxy-buffering off -- when it is produced.
#
# This is classic mode, not Octane worker mode. Worker mode would mean adding
# laravel/octane to composer.json; classic mode keeps the dependency list as it
# is and still gets a real server. Concurrency is num_threads in the Caddyfile.
#
# The OCR toolchain (poppler-utils, tesseract) is deliberately absent, for the
# same reason as in the other image: the book and house pipelines run against
# the local Sail image and reach production as rows.

ARG PHP_VERSION=8.5
ARG NODE_VERSION=24


# Composer dependencies and the production autoloader.
FROM php:${PHP_VERSION}-cli-alpine AS php-builder
WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The runtime extensions are installed in the final stage, not this one, so
# dependency resolution has to be told not to look for them here.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --ignore-platform-reqs \
        --no-autoloader \
        --no-interaction \
        --no-progress

COPY . .
RUN composer dump-autoload \
        --no-dev \
        --classmap-authoritative \
        --no-interaction


# Compiled CSS and JS.
FROM node:${NODE_VERSION}-alpine AS node-builder
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Vite reads the Blade views and the application's own config through the
# Laravel plugin, so the backend comes in before the assets are compiled. The
# copy leaves the node_modules installed above in place.
COPY --from=php-builder /app .
RUN npm run build


# The deployed image.
FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine
WORKDIR /app

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && rm /usr/local/bin/install-php-extensions

# The container listens on 80 so the port matches the Service's targetPort, and
# the process still runs unprivileged: the file capability is what lets a
# non-root frankenphp bind below 1024. Docker and Kubernetes both leave
# NET_BIND_SERVICE in the default bounding set, so nothing else is needed.
#
# /data and /config are Caddy's XDG directories. `admin off` and `auto_https
# off` in the Caddyfile mean nothing is written to them, but Caddy checks them
# at startup and the check is done as www-data.
RUN apk add --no-cache libcap \
    && setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp \
    && chown -R 82:82 /data /config

COPY docker/app-frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app-frankenphp/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/app-frankenphp/entrypoint /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

COPY --from=php-builder --chown=82:82 /app .
COPY --from=node-builder --chown=82:82 /app/public public/

USER www-data

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
