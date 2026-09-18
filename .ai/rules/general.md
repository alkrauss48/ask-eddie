---
paths:
  - compose.yaml
  - 'docker/**'
  - config/filesystems.php
---

# General

## Sail runs Postgres+pgvector on host port 5433
The `pgsql` service uses `pgvector/pgvector:pg18` (not `postgres:18-alpine`) so vector columns work locally. Keep the image tag's PG major in sync with Sail's default so the `sail-pgsql` volume's data dir stays valid.

Host ports are deliberately non-default because the developer usually has another Postgres and app running: `FORWARD_DB_PORT=5433` (host 5433 -> container 5432) and `APP_PORT=9000` (matches `APP_URL`/`SERVER_PORT`). From inside the containers use `DB_HOST=pgsql` / `DB_PORT=5432`; from the host (GUI clients, `psql`) use `localhost:5433`.

## The house export is mounted, not configured
## HOUSE_PATH alone cannot reach the house export

Unlike `BOOKS_PATH`, whose default sits under the project's own `.:/var/www/html` bind mount, the house export lives in a sibling checkout of the-krauss-haus and is outside the project entirely. Nothing outside the project is visible inside the container, so no value of `HOUSE_PATH` can reach it on its own.

`compose.yaml` mounts it read-only:

    - '${HOUSE_SOURCE:-../the-krauss-haus/static/data}:/var/www/house-data:ro'

and `config/filesystems.php` defaults the `house` disk's root to the container path `/var/www/house-data`. `HOUSE_SOURCE` is the host path; `HOUSE_PATH` is where it lands inside. Changing the mount needs `sail down && sail up -d`.

A missing sibling checkout makes Docker create an empty directory rather than failing, which `house:import` reports as an incomplete export naming the variable to set.

## The sail-8.5/app image can silently lose the OCR toolchain

`docker/8.5/Dockerfile` appends poppler-utils and tesseract in its own `RUN` layer, but the built `sail-8.5/app` image can be older than that layer — `sail up -d` does not rebuild an image that already exists. The symptom is `books:import` reporting `pdfinfo failed for [...]` with an empty error message for every book.

Check with `sail exec laravel.test which pdfinfo tesseract`, or `docker history --no-trunc sail-8.5/app | grep -c tesseract`. Fix with `sail build laravel.test && sail up -d`.
