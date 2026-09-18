---
paths:
  - 'app/Services/House/**'
  - 'app/Models/House*.php'
  - 'app/Console/Commands/House/**'
  - 'app/Services/Embedding/**'
  - config/house.php
---

# House

## The house corpus is structured on purpose, and separate from the books on purpose

`house_chunks` is its own table rather than rows in `book_chunks`. `books:embed --verify` asserts exactly one `(model, dimensions, version)` triple **table-wide**; a shared table would stretch that invariant across two corpora with two independent renderer versions, and the first house render change would fail Eddie's verifier.

But both corpora embed through the *same* TEI `bge-m3` at 1024 dimensions, because corpus and query vectors must come from one implementation (see .ai/rules/retrieval.md). `house:embed --verify` asserts `house.embedding` still agrees with `books.embedding` on provider, model and width, and names both values. That failure is invisible in every row: every vector would be the right width, the right count and self-consistent, and every query answered by a model that never read the corpus.

Only the retrieval knobs differ. 60 candidates/channel is 0.24% of the 24,926 book chunks and 29% of the 207 house chunks, where RRF would stop discriminating.

## No HNSW index, and no ef_search knob

Deliberate, and `HouseChunkEmbeddingSchemaTest` asserts the index's **absence** by name so adding one is a decision rather than an accident. At 207 rows an exact scan is sub-millisecond with 100% recall; pgvector post-filters, so an `is_indexable` predicate over an approximate scan can quietly return a short list. `config/house.php` has no `ef_search` key because a knob wired to nothing is worse than no knob.

## Ingredients are catalog-only, never chunked

131 of 338 candidate records. A rendered ingredient is near-zero prose and dozens are mutually near-identical under bge-m3, so a query for "rum" would return thirty nearly-tied ingredient chunks and crowd out every cocktail. They also have no URL to cite — `/ingredients` is an index page with no `[slug]` route. `HouseSourceType` has no `Ingredient` case. Every ingredient title still reaches retrieval as a B-weighted keyword on the drinks that pour it.

## Do not fuzzy-link Recipe.ingredients to house_ingredients

`house_recipes.ingredients` is free text (`"9oz 40% ABV Vodka"`). Matching it to a catalog slug is the same wrong-merge risk .ai/rules/books.md already refuses for drink names: a real recipe, a real URL, and the wrong ingredient in it, with every invariant green.

## house_cocktail_ingredients.house_ingredient_id is nullable, and that is load-bearing

The site's ingredient list is a real union — measured pours beside bare strings (`'8 basil leaves'`, `'Served in a smoked glass'`). Exactly one of `house_ingredient_id` / `free_text` is set; `--verify` rejects a row with neither. Flattening free text into a fake ingredient row would invent a catalog member the site does not have, and every structured query could then find it.

## source is json, not jsonb

jsonb canonicalizes key order, which would make `house_cocktails.content_hash` unstable across a round trip that changed nothing — and an unstable hash means every import reports a change, so no import can be trusted when it reports one. Pinned by a test.

## Staleness is a content hash, and it covers the prefix

`house_chunks.content_hash` is sha256 over the **embedded** string (provenance prefix + text), not over `text` alone — so a cocktail that is renamed and nothing else still becomes pending. `house:embed` writes the stored `content_hash` into `embedded_content_hash`; pending is `embedded_content_hash is distinct from content_hash` (`is distinct from`, not `!=`, because null != anything is null and would drop every never-embedded row).

Do not write a recomputed hash into `embedded_content_hash` — the pending predicate compares the two columns in SQL, so anything else makes them incomparable the moment they disagree. `house:import --verify` is what guarantees `content_hash` describes the row's own text.

## Rendering is folded into house:import; there is no house:chunk

207 records render in about a second, and `content_hash` + `renderer_version` already decide what is rewritten. The whole run is one transaction — a half-applied catalog answers questions with menus missing a round. `--dry-run` does the real work inside a transaction it always rolls back, so its counts are the counts.

**A second run must write nothing.** Collection membership is diffed before writing for exactly this reason; without it, "did anything change" is unanswerable.

## Menus and flights are one table

Both are a titled, ordered list of cocktails with a slug. `house_collection_cocktails`'s unique key includes `is_featured` on purpose: a menu drink can sit in a section *and* in `featuredDrinks`, and both facts are true — without it the second insert collides and one silently goes missing.

## HouseChunk::toArray() is the prompt payload — five keys

`kind`, `title`, `text`, `url`, `citation`. Asserted by **count** in `HouseChunkCitationTest`. `keywords` is hidden deliberately: it is an index artefact (the same standing as `search_vector`) and its content already reaches the model as prose inside `text`. Do not weaken the count assertion to `toContain`.

## Flight chunks dominate mood queries — measured
## The 10 flight chunks are the only mood-prose in the corpus, and dense retrieval finds them first

Measured against the real 207-chunk corpus, embedded through TEI bge-m3, exact scan, cosine:

    "something smoky and agave-forward, nothing too sweet"
      1. 0.653  [path] Shaman        6. 0.542  [path] Fisherman
      2. 0.603  [path] Rogue         7. 0.533  [cocktail] Oaxaca Old Fashioned
      3. 0.598  [path] Warrior       8. 0.521  [path] Mage
      4. 0.558  [path] Hunter        9. 0.520  [cocktail] Hot Toddy
      5. 0.549  [path] Bard
      top-25 mix: cocktail=13, path=9, bartender=1, menu=1, recipe=1

    "a bright citrusy gin drink"        top-25 mix: cocktail=24, bartender=1
      1. Gin and Tonic  2. Negroni Bianco Bergamotto  3. Singapore Sling  4. Alaska

    "what should I make with rye whiskey"   top-25 mix: cocktail=24, recipe=1
      1. Sazerac  2. Whiskey Sour  3. Vieux Carré  4. Manhattan

So the dense channel is good for queries that name an ingredient or a category, and skews to flights for queries that describe a *mood*. The cause is structural rather than a defect: a cocktail renders mostly as a measured build, while a flight renders as flavour prose ("Vibrant. Approachable. Crowd-pleasing…"), so a conversational sentence embeds nearer the prose. 10 of 207 chunks (4.8%) carry almost all the mood language in the corpus.

**Do not fix this by cutting flight chunks.** A flight genuinely is the answer to "what should we drink tonight", and the drinks are still there — Oaxaca Old Fashioned ranks 7th on a mezcal query with no mezcal word in it.

What to do about it in Phase C:
- The lexical channel is the counterweight, and it works: the tag vocabulary ("Mezcal", "Smoky") is B-weighted in `keywords`, so RRF pulls the named drinks back up. This is a concrete argument for keeping both channels rather than going dense-only at this corpus size.
- If a bartender still leads with flights on mood questions, prefer a reranker or a per-kind cap in the tool over changing the renderer. Measure before changing `HouseRenderer::VERSION` — a re-render costs one `house:import` and one `house:embed` (~4 minutes for 207 chunks on emulated TEI, measured at 0.8 chunks/s).
