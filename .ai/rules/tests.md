---
paths:
  - 'tests/**'
---

# Tests

## Run tests through Sail, not the host PHP
`phpunit.xml` no longer forces in-memory SQLite — it sets only `DB_DATABASE=testing`, so tests use the `pgsql` connection from `.env`. Since `DB_HOST=pgsql` only resolves inside the Docker network, any test that touches the database fails from the host with `could not translate host name "pgsql"`.

Use `./vendor/bin/sail artisan test`. Host-side `php artisan test` appears to pass only while no test hits the database, which is misleading — don't trust it as a green signal.

This tradeoff is deliberate: vector columns and `pgvector` queries can't be exercised on SQLite, so the suite runs on real Postgres.
