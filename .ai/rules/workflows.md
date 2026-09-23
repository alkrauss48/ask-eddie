---
paths:
  - '.github/workflows/**'
---

# Workflows

## CI needs pgvector, a vite build, and no DB_DATABASE
`.github/workflows/ci.yml` runs Back-end Format (pint --test), Back-end Test (pest), then Build, which pushes `alkrauss48/ask-eddie:dev-<branch>` to Docker Hub on push events only — so main publishes `dev-main`. Releases come from the semver tags. A deployment manifest has to name the same tag this produces.

Three things the test job cannot drop:
- The Postgres service must be `pgvector/pgvector:pg18`, not stock postgres. Two migrations create vector columns and an HNSW index, so a plain image fails to migrate at all.
- `DB_DATABASE` is deliberately unset in the job env. `phpunit.xml` sets it to `testing`, and PHPUnit's `<env>` does not overwrite a variable the environment already holds -- setting it in CI would silently win. The service's `POSTGRES_DB` is `testing` to match. The other `DB_*` vars *are* set in the job env, which is fine: Laravel's dotenv is immutable, so the real environment beats `.env`.
- `npm run build` runs before pest. ExampleTest renders the welcome view, which calls `@vite`, and without a manifest the suite fails on a missing build rather than on anything about the application.

phpstan is not installed, so there is no Back-end Lint job; pint is the only static check.
