---
paths:
  - 'database/migrations/**'
---

# Migrations

## Call ensureVectorExtensionExists before creating vector columns
The `vector` extension is not pre-created by a Docker init script, so `sail down -v` (or any fresh volume) starts without it. Any migration that defines a `vector` column must call `Schema::ensureVectorExtensionExists()` first. It installs the extension into whichever database the connection currently points at, so it covers both `laravel` (`sail artisan migrate`) and `testing` (`sail artisan test`) as each runs.

Use `$table->vector('embedding', dimensions: N)->index()` to get an HNSW cosine index, and cast the attribute with `Illuminate\Database\Eloquent\Casts\AsVector` on the model.
