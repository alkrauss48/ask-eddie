---
paths:
  - 'docker/**'
---

# Docker

## docker/8.5/Dockerfile is locally owned and no longer tracks Sail
`php artisan sail:publish` was run so the OCR toolchain could be installed; `compose.yaml` now builds from `./docker/8.5` and mounts `./docker/pgsql/create-testing-database.sql`. Sail's upstream Dockerfile changes no longer arrive automatically — diff against `vendor/laravel/sail/runtimes/8.5/Dockerfile` after a Sail upgrade.

The OCR packages (poppler-utils, tesseract-ocr + eng/spa/ita/fra) are a separate appended `RUN` layer at the bottom, kept apart from Sail's own apt block so that diff stays readable. Sail's `PHP_EXTENSIONS` build arg cannot install these — it prefixes every value with `php8.5-`.

Unused published runtimes (8.0–8.4, mysql, mariadb) were deleted; keep `docker/8.5` and `docker/pgsql`.

## docker/app-frankenphp is the deployed image; docker/app is the fallback; docker/8.5 is Sail
`docker/app-frankenphp/Dockerfile` is what CI builds and Kubernetes runs. `docker/app/Dockerfile` is the previous `php artisan serve` image, kept as a fallback and no longer built by anything -- if you change one, decide deliberately whether the other still needs to follow. `docker/8.5` remains the local Sail runtime and is excluded from the build context. Do not confuse the three.

The deployed image serves with FrankenPHP (Caddy) on :80, in classic mode rather than Octane worker mode -- worker mode would mean adding `laravel/octane` to `composer.json`, and classic mode already gets a real HTTP server. Binding :80 unprivileged works because of `setcap cap_net_bind_service=+ep` on `/usr/local/bin/frankenphp` (Docker and Kubernetes both leave NET_BIND_SERVICE in the default bounding set); the process runs as uid 82.

Concurrency is `num_threads` in `docker/app-frankenphp/Caddyfile`, not `PHP_CLI_SERVER_WORKERS` in the ConfigMap. It must be set explicitly: Go sizes its default thread pool from the node's core count, not the container's CPU limit.

Do not size the pod as `num_threads` x `memory_limit`. Measured on this application: idle 79 MiB, 4 threads saturated 99 MiB, 12 threads saturated 124 MiB -- about 3 MiB per thread -- while a full framework boot and one handled request peaks at 22 MiB against a 256M `memory_limit`. `memory_limit` is a per-request ceiling, not an allocation, exactly as `pm.max_children` x `memory_limit` is not how php-fpm is sized. The Deployment's existing 768Mi is ample for 4 threads. For reference the `artisan serve` image measured 82 MiB idle and 86 MiB saturated on 4 workers, so FrankenPHP costs roughly 15% more for the Go runtime and ZTS PHP.

`text/event-stream` is deliberately excluded from the Caddyfile's `encode` match list. A compressed body is a buffered body, and POST /api/ask streams for thirty seconds or more. `output_buffering = Off` in php.ini is the other half of that.

`max_execution_time = 120` is enforced here, which it could not be under `artisan serve` (the CLI SAPI pins it to 0). It sits above what an answer with consults legitimately costs and below the ingress's `proxy-read-timeout` of 300, so the server gives up before the proxy does.

`docker/app-frankenphp/Caddyfile` replaces the image's own. `auto_https off` because the ingress terminates TLS and the pod cannot reach an ACME server; `admin off` because nothing reconfigures Caddy at runtime.

`docker/app-frankenphp/entrypoint` runs `php artisan optimize` at start, not at build: every config value arrives from the ConfigMap and Secrets, so a cache baked into the image would freeze the wrong ones in.

The OCR toolchain (poppler-utils, tesseract) is deliberately absent -- the book and house pipelines run against the Sail image and reach production as rows.

## Nothing in the deployed image runs migrations
The entrypoint runs `php artisan optimize` and nothing else. Production schema and data both arrive by restoring a `pg_dump` taken from the Sail database, so the dump's `migrations` table must already cover every file in `database/migrations/`. Restore with `--no-owner --no-acl`: the dump records `OWNER TO sail`, and that role does not exist in the cluster's Postgres.
