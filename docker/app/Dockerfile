# syntax=docker/dockerfile:1

# The previous deployed image for ask.thekrausshaus.com, kept as a fallback.
#
# CI builds docker/app-frankenphp/Dockerfile now; nothing builds this one. It
# is retained so the `php artisan serve` setup can be reached again in a hurry.
#
# It serves with `php artisan serve` rather than nginx + php-fpm. One answer
# streams Server-Sent Events for thirty seconds or more, and the built-in
# server passes those bytes straight through instead of having to be talked out
# of buffering them. The cost is that the server is single-threaded per worker,
# which is what PHP_CLI_SERVER_WORKERS in the deployment's ConfigMap answers.
#
# The OCR toolchain (poppler-utils, tesseract) is deliberately absent. The book
# and house pipelines run against the local Sail image and reach production as
# rows, so nothing the deployed container serves shells out to them.

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
FROM php:${PHP_VERSION}-cli-alpine
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

# The container listens on 80 so the port matches the Service's targetPort,
# and the process still runs unprivileged: the file capability is what lets a
# non-root php bind below 1024. Docker and Kubernetes both leave
# NET_BIND_SERVICE in the default bounding set, so nothing else is needed.
RUN apk add --no-cache libcap \
    && setcap cap_net_bind_service=+ep /usr/local/bin/php

COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/app/entrypoint /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

COPY --from=php-builder --chown=82:82 /app .
COPY --from=node-builder --chown=82:82 /app/public public/

USER www-data

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=80"]
