---
paths:
  - compose.yaml
---

# General

## Sail runs Postgres+pgvector on host port 5433
The `pgsql` service uses `pgvector/pgvector:pg18` (not `postgres:18-alpine`) so vector columns work locally. Keep the image tag's PG major in sync with Sail's default so the `sail-pgsql` volume's data dir stays valid.

Host ports are deliberately non-default because the developer usually has another Postgres and app running: `FORWARD_DB_PORT=5433` (host 5433 -> container 5432) and `APP_PORT=9000` (matches `APP_URL`/`SERVER_PORT`). From inside the containers use `DB_HOST=pgsql` / `DB_PORT=5432`; from the host (GUI clients, `psql`) use `localhost:5433`.
