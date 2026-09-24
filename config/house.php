<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Source Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk holding the exported JSON from the-krauss-haus. The
    | site's content is 239 hand-authored TypeScript modules that reference each
    | other by live object reference, so it cannot be read from here directly:
    | `npm run export:data` in that repository evaluates the modules through
    | Vite and writes slug-referenced JSON, which is what this disk points at.
    |
    | Configure its root with the HOUSE_PATH environment variable; see the
    | "house" disk in the filesystem configuration file. This mirrors BOOKS_PATH
    | exactly, and for the same reason: the corpus is not part of this
    | repository and should not be copied into it.
    |
    */

    'disk' => env('HOUSE_DISK', 'house'),

    /*
    |--------------------------------------------------------------------------
    | Site
    |--------------------------------------------------------------------------
    |
    | Citations point at the public site rather than at a local file, because a
    | house citation is a link a guest can open. The export supplies each
    | record's path ("/cocktails/mai-tai"); this is the origin it hangs from.
    |
    */

    'site_url' => rtrim(env('HOUSE_SITE_URL', 'https://thekrausshaus.com'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Fetch URL
    |--------------------------------------------------------------------------
    |
    | Where `house:fetch` downloads the export from. The site commits the export
    | under static/data, which SvelteKit serves from /data, so a deployed pod
    | with no checkout of that repository can still pull the files onto the
    | house disk. It is whatever the site has deployed, not a local re-export.
    |
    */

    'fetch_url' => rtrim(env(
        'HOUSE_FETCH_URL',
        rtrim(env('HOUSE_SITE_URL', 'https://thekrausshaus.com'), '/').'/data',
    ), '/'),

    /*
    |--------------------------------------------------------------------------
    | Rendering
    |--------------------------------------------------------------------------
    |
    | Every record renders to exactly one chunk -- there is no packer, no
    | segmenter and no byte arithmetic here, because the longest record in the
    | corpus is a fraction of the ceiling below. That makes "max_chars" an
    | assertion rather than a packing hint, the same standing it has in
    | config('books.chunking.max_chars'): HouseRenderer throws when a render
    | exceeds it rather than truncating, so a future flight of twenty cocktails
    | fails loudly instead of losing its tail to a 512-token window.
    |
    | "keyword_separator" joins the structured vocabulary into the B-weighted
    | half of the lexical index. It is a space because tsvector tokenizes on
    | whitespace and punctuation alike; the separator is only there to stop two
    | adjacent labels from fusing into one token.
    |
    */

    'rendering' => [
        'max_chars' => (int) env('HOUSE_RENDER_MAX_CHARS', 1600),
        'keyword_separator' => ' · ',
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding
    |--------------------------------------------------------------------------
    |
    | This block MUST match config('books.embedding') on provider, model and
    | dimensions, and `house:embed --verify` asserts that it does. One TEI
    | container serves one model: if these diverge, house queries are embedded
    | by a different model than the house corpus, which is the silent
    | catastrophe .ai/rules/retrieval.md opens with, arriving through a config
    | file rather than through whereVectorSimilarTo().
    |
    | The knobs that are allowed to differ are the batch size and the timeout,
    | which are about throughput rather than about meaning. 207 chunks embed in
    | one run of a handful of batches, so there is no bulk-run tuning to do.
    |
    */

    'embedding' => [
        'provider' => env('AI_EMBEDDINGS_PROVIDER', 'tei'),
        'model' => env('HOUSE_EMBEDDING_MODEL', 'BAAI/bge-m3'),
        'dimensions' => (int) env('HOUSE_EMBEDDING_DIMENSIONS', 1024),
        'batch_size' => (int) env('HOUSE_EMBEDDING_BATCH', 32),
        'timeout' => (int) env('HOUSE_EMBEDDING_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | The same hybrid shape as books -- dense plus lexical, fused with RRF --
    | with different numbers, because the corpora are three orders of magnitude
    | apart. Eddie's 60 candidates per channel is 0.24% of his 24,926 chunks; on
    | 207 it would be 29%, so nearly everything would land in both lists and RRF
    | would stop discriminating between them. 25 per channel is roughly the same
    | share of this corpus that 60 is of his.
    |
    | "min_similarity" is lower than books' 0.30 because these chunks are short.
    | A three-line syrup recipe scores lower against a conversational query than
    | a paragraph of prose does, on content rather than on relevance, so the
    | floor has to sit below where the short chunks land or it becomes a length
    | filter wearing a relevance filter's name.
    |
    | There is deliberately no "ef_search" key here, and deliberately no HNSW
    | index on house_chunks. At this size a 1024-wide exact scan is under a
    | megabyte and sub-millisecond, with 100% recall. Approximate search buys
    | nothing measurable and costs real recall risk: pgvector post-filters, so
    | an is_indexable predicate over an approximate scan can quietly return a
    | short list -- the exact failure books.retrieval.ef_search exists to
    | prevent, arriving through a different door. A knob wired to nothing is
    | worse than no knob, so there is no knob.
    |
    | Reranking is configured per corpus rather than globally. The binding is
    | contextual, so turning books' reranking off on an Apple Silicon dev
    | machine does not silently turn the house's off with it.
    |
    */

    'retrieval' => [
        'dense_candidates' => (int) env('HOUSE_RETRIEVAL_DENSE', 25),
        'lexical_candidates' => (int) env('HOUSE_RETRIEVAL_LEXICAL', 25),
        'min_similarity' => (float) env('HOUSE_RETRIEVAL_MIN_SIMILARITY', 0.25),
        'text_search_config' => env('HOUSE_RETRIEVAL_TEXT_CONFIG', 'english'),
        'rrf_k' => (int) env('HOUSE_RETRIEVAL_RRF_K', 60),
        'weights' => [
            'dense' => (float) env('HOUSE_RETRIEVAL_WEIGHT_DENSE', 1.0),
            'lexical' => (float) env('HOUSE_RETRIEVAL_WEIGHT_LEXICAL', 1.0),
        ],
        'limit' => (int) env('HOUSE_RETRIEVAL_LIMIT', 6),

        'rerank' => [
            'enabled' => (bool) env('HOUSE_RERANK_ENABLED', true),
            'provider' => env('AI_RERANKING_PROVIDER', 'tei-rerank'),
            'model' => env('HOUSE_RERANKING_MODEL', 'BAAI/bge-reranker-v2-m3'),
            'candidates' => (int) env('HOUSE_RERANK_CANDIDATES', 20),
            'timeout' => (int) env('HOUSE_RERANK_TIMEOUT', 30),
        ],
    ],

];
