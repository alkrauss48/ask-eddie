---
paths:
  - 'app/Services/Retrieval/**'
  - 'app/Ai/Tei/**'
  - app/Tools/SearchTheBooks.php
  - app/Agents/EddieAgent.php
  - app/Services/Books/ChunkEmbedder.php
  - 'app/Services/Retrieval/Drink*.php'
---

# Retrieval

## Corpus and query vectors must come from the same server
`whereVectorSimilarTo($column, $string)` auto-embeds a string query through `ai.default_for_embeddings` with no provider or model argument. If that key points somewhere other than the model the corpus was embedded with, every query is embedded by a different model than the passages — no exception, no warning, plausible-looking output, badly wrong ranking. `ChunkRetriever::embed()` therefore embeds explicitly and passes an **array** to `whereVectorSimilarTo`, which short-circuits the auto-embed entirely. Never pass it a string.

Same reason TEI runs in dev as well as production rather than Ollama locally: a llama.cpp-based server and a transformers-based one can differ in pooling or normalization. Swapping the implementation is only safe behind an equivalence check — embed a 100-chunk sample through both and assert pairwise cosine similarity > 0.999 first.

`embedding_model`, `embedding_dimensions` and `embedder_version` are recorded per row so a model change makes the corpus pending automatically. `books:embed --verify` asserts exactly one triple table-wide and that it matches configuration.

## bge-m3 is chosen for being symmetric, not for being best
`multilingual-e5-large` and `nomic-embed-text` are asymmetric: they want a `query:` / `search_query:` prefix and lose recall without one, silently. Because retrieval auto-embeds a bare query string there is nowhere to put such a prefix. bge-m3 needs none, is natively 1024-dim (under pgvector's 2,000-dim HNSW ceiling), and is multilingual for the 951 non-English chunks and the accented drink names throughout.

## The lexical column is hand-written because `$table->fullText()` would index NULL
`PostgresGrammar::compileFulltext()` emits `to_tsvector(cfg, a) || to_tsvector(cfg, b)` with **no `coalesce`**, and NULL propagates through `||`. Only 56% of chunks have a `heading` and 36% a `section_title`, so `fullText()` would leave 64% of the corpus indexed as NULL — matching nothing, forever, with no error. `search_vector` is a generated column with explicit `coalesce` on every part. `BookChunkEmbeddingSchemaTest` pins it.

`headings #>> '{}'` is in there so a packed block of ten recipes is findable by all ten names. It resolves to `json_extract_path_text`, which is immutable and therefore legal in a generated column.

Query it with `websearch_to_tsquery` (a guest can quote `"blue lady"`; it never throws on malformed input) and rank with `ts_rank_cd(..., 32)` — flag 32 divides by `rank + 1`, which stops a 1,400-character prose chunk from outranking a 200-character recipe on length alone.

## Both channels must draw from the same universe
The lexical query carries `embedding is not null` as well as `is_indexable`. Without it a passage could rank purely because the dense channel was structurally unable to consider it. Retrieval is 4 queries: one embed, one dense select, one lexical select, one hydrate.

`hnsw.ef_search` must be ≥ the dense candidate count, or the index scan quietly returns a short list — which reads as a thin corpus rather than a mis-set knob. `ChunkRetriever::dense()` sets it per query.

## Scores live on RetrievedChunk, never on the model
`BookChunk::toArray()` **is** the prompt payload — eight keys, asserted by count in `BookChunkCitationTest` and again in `SearchTheBooksToolTest`. Never `setAttribute('score', …)`, never add to `$appends`, never `addSelect` a distance alias. Each leaks fusion bookkeeping into the language model's context, where it reads as content the model may repeat; "relevance 0.87" in a bartender's answer is both meaningless to a guest and a break in character. Do not weaken either test to `toContain`.

## Reranking fails closed, and is a mode rather than a dependency
`NullReranker` is bound when the stage is disabled, and `AiReranker` falls back to it on any throwable — including `AiManager::rerankingProvider()`'s outright `LogicException` for a provider that is not a `RerankingProvider`. A cross-encoder outage must cost ordering quality, never an exception mid-answer. Do not resolve the reranking provider eagerly to decide which to bind.

Hybrid + RRF is the bulk of the quality gain; over 25k short recipe passages a cross-encoder's marginal value is smaller than over long documents. If reranking is too slow, lower `books.retrieval.rerank.candidates` before disabling the stage.

## TEI is amd64-only and is emulated on Apple Silicon
There is no arm64 manifest for any `text-embeddings-inference` tag, so `compose.yaml` pins `platform: linux/amd64`. Measured on an M-series host: **0.5 chunks/s**, about 14 hours for the 24,926-chunk corpus, at ~870% CPU — it is saturating the cores, not misconfigured, so there is no tuning win to find.

Reranking is emulated too, and worse: **66 seconds for 40 passages**, against the 0.5–1.5s that image costs on real amd64 hardware. Lowering `rerank.candidates` does not rescue it — it is ~1.65s per passage — so on an Apple Silicon dev machine set `BOOKS_RERANK_ENABLED=false` and let `NullReranker` serve the fused order. The config default stays `true` because it is correct for production; do not flip the default to make local development comfortable.

**The two containers hold ~9 GB between them** (embed ~5.2 GB, rerank ~4.1 GB) against a 15.7 GB Docker allocation, and they hold it whether or not they are serving anything. A full corpus embed alongside another project's stack was killed for low memory an hour in. Stop `tei-rerank` before a bulk run — it is only needed at query time, and on this hardware reranking is off anyway.

`books:embed` survived that kill exactly as designed: 2,648 chunks committed, no partial or mis-indexed writes, and re-running picked up only what was missing. But a killed run never reaches `$lock->release()`, and the lock's TTL has to outlive a nine-hour job — so the stale lock blocks every retry until it expires. The command now prints the `forceRelease()` incantation when it is turned away.

Two things had to be capped to make it run at all: `--max-batch-tokens 4096` (TEI's 16384 default allocates a warm-up buffer bge-m3 cannot fit beside its float32 weights, and the container is OOM-killed before serving anything), and `--max-client-batch-size 64` on the reranker (the default is 32 and `rerank.candidates` is 40, which would 413 every request).

## Do not use SimilaritySearch::usingModel()
It is dense-only, passes the query through as a string (see the first rule), gives no hook for `set hnsw.ef_search`, and truncates to its own limit before fusion could see both lists. `SimilaritySearch`'s public closure constructor is the package's own escape hatch; `SearchTheBooks` wraps `ChunkRetriever` instead, which is supported rather than a fork.

Reranking through the package needed one small provider: laravel/ai ships it for Bedrock, Jina, Cohere and VoyageAI only, and `Collection::rerank()` always dispatches to `Reranking::of()` — its `Closure $by` is a field resolver, not a scorer. `AiManager extends MultipleInstanceManager`, so `Ai::extend('tei-rerank', …)` in `AppServiceProvider::boot()` registers `TeiRerankerProvider` cleanly, and `Reranking::fake()` then works on it unchanged.

## The survey payload is six keys, and coverage travels with it
`DrinkSummary::payload()` is what the language model is handed: name, books, mentions, years, also_printed_as, citations. Asserted by count in DrinkSummaryTest and SurveyTheBooksToolTest, the same discipline BookChunkCitationTest holds over the eight-key passage payload. No id, no slug, no canonical_key, no version, no score.

`Drink::toArray()` deliberately never reaches a prompt — unlike BookChunk, which had to become its own payload because SimilaritySearch serializes the model out of the app's reach. Keep the value object; do not start passing models.

Every survey carries `DrinkCoverage::sentence()` in its preamble. The narrative books print no drink headings, so a tally is a claim about the books it could count — without that sentence Eddie states a corpus-wide claim he cannot support, and "most of my books" and "all my books" become the same sentence to him.

SurveyTheBooks fails closed like AiReranker: catch Throwable, report(), return a sentence. Three distinct returns — not-yet-tallied, nothing-matched, tally-unavailable — because a bare `[]` reads to the model as "no such drinks exist", which is a false claim about the books.
