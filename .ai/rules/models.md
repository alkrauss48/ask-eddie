---
paths:
  - app/Models/BookChunk.php
---

# Models

## BookChunk::toArray() is the retrieval payload, not presentation
laravel/ai's SimilaritySearch::usingModel() runs $model::query()->whereVectorSimilarTo(...)->limit(...)->get()->map(fn ($m) => Arr::except($m->toArray(), [$column])) and hands the result straight to the model. So $with, $hidden and $appends here decide what the LLM sees. It never calls with(), which is why $with eager-loads the book; without it every citation N+1s. BookChunkCitationTest asserts the exact eight-key payload so a new column cannot leak into a prompt.

It does accept a ?Closure $query hook, so excluding non-indexable chunks needs no global scope: pass query: fn ($q) => $q->where('is_indexable', true). Its default limit is 15, not 8.

Printed page labels are interpolated only within a numbering series and flagged with printed_pages_estimated (rendered as a tilde); the physical PDF page is always exact and never null. Do not write inferred labels back to book_pages — that column is Phase 1's observed evidence.
