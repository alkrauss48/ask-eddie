---
paths:
  - app/Models/BookChunk.php
  - app/Models/Drink.php
---

# Models

## BookChunk::toArray() is the retrieval payload, not presentation
laravel/ai's SimilaritySearch::usingModel() runs $model::query()->whereVectorSimilarTo(...)->limit(...)->get()->map(fn ($m) => Arr::except($m->toArray(), [$column])) and hands the result straight to the model. So $with, $hidden and $appends here decide what the LLM sees. It never calls with(), which is why $with eager-loads the book; without it every citation N+1s. BookChunkCitationTest asserts the exact eight-key payload so a new column cannot leak into a prompt.

It does accept a ?Closure $query hook, so excluding non-indexable chunks needs no global scope: pass query: fn ($q) => $q->where('is_indexable', true). Its default limit is 15, not 8.

Printed page labels are interpolated only within a numbering series and flagged with printed_pages_estimated (rendered as a tilde); the physical PDF page is always exact and never null. Do not write inferred labels back to book_pages — that column is Phase 1's observed evidence.

## Drink::toArray() is not a payload, and must not become one
BookChunk::toArray() had to *become* the prompt payload because laravel/ai's SimilaritySearch serializes the model out of the app's reach. Nothing does that for drinks, so the safer construction is available and is the one in use: DrinkSummary carries the payload, Drink::toArray() never reaches a prompt, and a column added to the drinks table cannot leak into an answer.

Do not add $hidden/$appends here to make the model promptable, and do not start handing Drink models to a tool. See .ai/rules/retrieval.md for the six-key survey payload.

Aggregates (mention_count, book_count, first_year, last_year, first_book_id, aliases) are always recomputed wholesale from drink_mentions, never incremented. An incremented count drifts silently across a partial re-run; `books:drinks --verify` recomputes them all and asserts they match.
