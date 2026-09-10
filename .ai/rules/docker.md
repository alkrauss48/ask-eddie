---
paths:
  - 'docker/**'
---

# Docker

## docker/8.5/Dockerfile is locally owned and no longer tracks Sail
`php artisan sail:publish` was run so the OCR toolchain could be installed; `compose.yaml` now builds from `./docker/8.5` and mounts `./docker/pgsql/create-testing-database.sql`. Sail's upstream Dockerfile changes no longer arrive automatically — diff against `vendor/laravel/sail/runtimes/8.5/Dockerfile` after a Sail upgrade.

The OCR packages (poppler-utils, tesseract-ocr + eng/spa/ita/fra) are a separate appended `RUN` layer at the bottom, kept apart from Sail's own apt block so that diff stays readable. Sail's `PHP_EXTENSIONS` build arg cannot install these — it prefixes every value with `php8.5-`.

Unused published runtimes (8.0–8.4, mysql, mariadb) were deleted; keep `docker/8.5` and `docker/pgsql`.
