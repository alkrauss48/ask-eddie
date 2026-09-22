---
paths:
  - 'docker/**'
---

# Docker

## docker/8.5/Dockerfile is locally owned and no longer tracks Sail
`php artisan sail:publish` was run so the OCR toolchain could be installed; `compose.yaml` now builds from `./docker/8.5` and mounts `./docker/pgsql/create-testing-database.sql`. Sail's upstream Dockerfile changes no longer arrive automatically — diff against `vendor/laravel/sail/runtimes/8.5/Dockerfile` after a Sail upgrade.

The OCR packages (poppler-utils, tesseract-ocr + eng/spa/ita/fra) are a separate appended `RUN` layer at the bottom, kept apart from Sail's own apt block so that diff stays readable. Sail's `PHP_EXTENSIONS` build arg cannot install these — it prefixes every value with `php8.5-`.

Unused published runtimes (8.0–8.4, mysql, mariadb) were deleted; keep `docker/8.5` and `docker/pgsql`.

## docker/app is the deployed image; docker/8.5 is Sail
`docker/app/Dockerfile` is what CI builds and Kubernetes runs; `docker/8.5` remains the local Sail runtime and is excluded from the build context. Do not confuse them.

It serves with `php artisan serve` on :80, not nginx + php-fpm: an answer streams SSE for thirty seconds or more and the built-in server passes those bytes through without buffering. Binding :80 unprivileged works because of `setcap cap_net_bind_service=+ep` on the php binary (Docker and Kubernetes both leave NET_BIND_SERVICE in the default bounding set); the process runs as uid 82. `PHP_CLI_SERVER_WORKERS` in the ConfigMap is what keeps one streamed answer from blocking every other request.

`opcache.enable_cli=1` in `docker/app/php.ini` is load-bearing, not a copy-paste error: `artisan serve` is the CLI SAPI, so `opcache.enable` alone does nothing.

`docker/app/entrypoint` runs `php artisan optimize` at start, not at build: every config value arrives from the ConfigMap and Secrets, so a cache baked into the image would freeze the wrong ones in.

The OCR toolchain (poppler-utils, tesseract) is deliberately absent -- the book and house pipelines run against the Sail image and reach production as rows.
