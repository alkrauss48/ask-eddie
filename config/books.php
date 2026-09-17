<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Source Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk holding the source PDFs. Configure its root with the
    | BOOKS_PATH environment variable; see the "books" disk in the filesystem
    | configuration file.
    |
    */

    'disk' => env('BOOKS_DISK', 'books'),

    /*
    |--------------------------------------------------------------------------
    | Working Directory
    |--------------------------------------------------------------------------
    |
    | Scratch space for PDF copies and rendered page images. This must be on the
    | container's own filesystem rather than inside the project, because the
    | project is a bind mount and page rendering re-reads the source PDF once
    | per page. See LocalPdfWorkspace.
    |
    */

    'temp_path' => env('BOOKS_TEMP_PATH', '/tmp/ask-eddie'),

    /*
    |--------------------------------------------------------------------------
    | Binaries
    |--------------------------------------------------------------------------
    |
    | Absolute paths may be supplied here if these binaries are not on the
    | PATH. They are installed into the Sail image by the OCR layer of
    | the Dockerfile, so the defaults are correct under Sail.
    |
    */

    'binaries' => [
        'pdfinfo' => env('BOOKS_PDFINFO_BIN', 'pdfinfo'),
        'pdftotext' => env('BOOKS_PDFTOTEXT_BIN', 'pdftotext'),
        'pdftoppm' => env('BOOKS_PDFTOPPM_BIN', 'pdftoppm'),
        'tesseract' => env('BOOKS_TESSERACT_BIN', 'tesseract'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extraction
    |--------------------------------------------------------------------------
    |
    | "concurrency" controls how many pages are processed in parallel. Pages
    | are handled in chunks of this size because a process pool starts every
    | process it is given at once, which would overwhelm the container.
    |
    */

    'extraction' => [
        'concurrency' => (int) env('BOOKS_CONCURRENCY', 8),
        'timeout' => (int) env('BOOKS_PAGE_TIMEOUT', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR
    |--------------------------------------------------------------------------
    |
    | Page images are rendered at "dpi" before being handed to Tesseract. The
    | page segmentation mode of 1 requests automatic page segmentation with
    | orientation and script detection, which suits these scanned books.
    |
    */

    'ocr' => [
        'dpi' => (int) env('BOOKS_OCR_DPI', 300),
        'page_segmentation_mode' => (int) env('BOOKS_OCR_PSM', 1),
        'default_language' => env('BOOKS_OCR_LANGUAGE', 'eng'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extraction Strategy
    |--------------------------------------------------------------------------
    |
    | "ocr_all" renders and OCRs every page, keeping the PDF's embedded text
    | layer alongside as a second candidate. "gated" only OCRs pages whose text
    | layer scores below the threshold below, which is faster but inherits the
    | text layer's mistakes everywhere else.
    |
    | The default is "ocr_all" because the embedded layer in this corpus is a
    | pre-LSTM OCR pass that renders, for instance, "ROCHESTER PUNCH" as
    | "KOCllESTKli rUKCll.". Re-running the whole corpus costs about an hour of
    | local CPU, once, and both candidates are kept either way.
    |
    */

    'strategy' => env('BOOKS_STRATEGY', 'ocr_all'),

    /*
    |--------------------------------------------------------------------------
    | Text Quality
    |--------------------------------------------------------------------------
    |
    | Pages are scored between 0 and 1 on how much of their text looks like real
    | language. The score ranks a page's two candidates against each other and
    | flags badly degraded pages; see PageTextQuality for measured values.
    |
    | "min_score" is a severity floor, not a fine-grained gate: observed scores
    | run about 0.58 for scanner noise, 0.84 for dot-leader index pages, and
    | 0.94 upwards for readable prose. It only takes effect under the "gated"
    | strategy. A page must also yield "min_characters" characters before its
    | score means anything at all.
    |
    */

    'quality' => [
        'min_score' => (float) env('BOOKS_QUALITY_MIN_SCORE', 0.90),
        'min_characters' => (int) env('BOOKS_QUALITY_MIN_CHARACTERS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | Pages are cut into overlapping passages for retrieval. Chunking works on
    | a whole book at a time rather than page by page, because 29% of the
    | pages in this corpus end mid-sentence and 127 of them end mid-word: a
    | page-by-page cut would sever a third of the corpus's sentences.
    |
    | "target_chars" is roughly one page -- the mean page holds 1,081
    | characters -- which suits a corpus of thousands of short recipes, where
    | precision matters more than breadth. Per-book median paragraph blocks run
    | 14 to 140 characters, so a chunk this size still holds several *whole*
    | recipes rather than a fragment of one.
    |
    | "max_chars" is a ceiling that is asserted in code, not a packing hint.
    | The Python original treated its equivalent as a threshold, so a single
    | long "sentence" could produce an oversized chunk that the embedding model
    | then silently truncated. Measured across the corpus, text runs 5.73
    | characters per word; 3.6 characters per token is budgeted here because
    | OCR fragments, fractions like 1/2, and the French, Spanish and Italian
    | titles all tokenize worse than plain English prose. That puts
    | max_tokens + prefix_reserve_tokens at 480, inside a 512-token context
    | window, so no embedding model chosen later can truncate a chunk.
    |
    | Phase 5 note: the per-source prompt budget must be max_chars, not a
    | second independent number. The Python packed to 1,600 characters and then
    | truncated each source to 1,200 in the prompt, discarding a quarter of
    | every large chunk *after* it had been retrieved on the strength of that
    | text.
    |
    | "headings_per_page_min" decides per book whether chunks are anchored to
    | headings or simply packed. Candidate headings are counted per page,
    | ignoring the first and last line of each page where running heads sit.
    | Measured, the recipe books clear this comfortably -- Cafe Royal 1937 at
    | 6.4 per page, Harry Johnson 1882 at 4.1 -- while the narrative books fall
    | below it, which is the intended split.
    |
    | "label_*" govern printed page numbers. Only 61% of pages carry a folio,
    | so the rest are interpolated from the book's numbering series and flagged
    | as estimated. A series must hold "label_series_min_run" consecutive
    | observations at one offset before it counts, because isolated folios in
    | this corpus are usually misreads -- a title-page year read as "1937", or
    | a stray "C". Extrapolation past the ends of a series is capped, so a book
    | with no coherent numbering (Cafe Royal has 13 folios and no two agree)
    | reports no printed page at all rather than a guess.
    |
    */

    'chunking' => [
        'target_chars' => (int) env('BOOKS_CHUNK_TARGET', 1200),
        'max_chars' => (int) env('BOOKS_CHUNK_MAX', 1600),
        'min_chars' => (int) env('BOOKS_CHUNK_MIN', 250),
        'overlap_chars' => (int) env('BOOKS_CHUNK_OVERLAP', 200),
        'max_tokens' => (int) env('BOOKS_CHUNK_MAX_TOKENS', 440),
        'prefix_reserve_tokens' => (int) env('BOOKS_CHUNK_PREFIX_TOKENS', 40),
        'chars_per_token' => (float) env('BOOKS_CHUNK_CHARS_PER_TOKEN', 3.6),
        'headings_per_page_min' => (float) env('BOOKS_CHUNK_HEADINGS_PER_PAGE', 1.5),
        'noise_score' => (float) env('BOOKS_CHUNK_NOISE_SCORE', 0.70),
        'label_series_min_run' => (int) env('BOOKS_CHUNK_LABEL_MIN_RUN', 2),
        'label_extrapolation_max_pages' => (int) env('BOOKS_CHUNK_LABEL_EXTRAPOLATION', 10),

        /*
        | Keyed by slug, for a book whose structure the measured gate above gets
        | wrong. Empty on purpose: mirrors the catalog overrides below, and
        | nothing should be listed here until an outline review calls for it.
        */
        'strategy_overrides' => [
            //
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding
    |--------------------------------------------------------------------------
    |
    | Chunks are embedded locally by Text Embeddings Inference, configured as
    | the "tei" provider in config/ai.php. The model is bge-m3 at its native
    | 1024 dimensions, chosen because retrieval auto-embeds a bare query string
    | with no instruction prefix: the asymmetric alternatives (multilingual-e5,
    | nomic-embed-text) want a "query:" prefix and lose recall without one, with
    | no error to show for it. bge-m3 needs no prefix, is multilingual for the
    | 951 non-English chunks and the accented drink names throughout, and sits
    | under pgvector's 2,000-dimension HNSW ceiling.
    |
    | "dimensions" is mirrored by the vector column's own typmod, which is
    | hard-coded in the migration because a migration must replay identically
    | forever. BookChunkEmbeddingSchemaTest pins the two together, so a change
    | here fails a test rather than silently mismatching the schema.
    |
    | "version" is the embedder's own version, bumped when the string handed to
    | the model changes shape. Together with the model name and dimensions it
    | decides which rows books:embed considers pending.
    |
    */

    'embedding' => [
        'provider' => env('AI_EMBEDDINGS_PROVIDER', 'tei'),
        'model' => env('BOOKS_EMBEDDING_MODEL', 'BAAI/bge-m3'),
        'dimensions' => (int) env('BOOKS_EMBEDDING_DIMENSIONS', 1024),
        'batch_size' => (int) env('BOOKS_EMBEDDING_BATCH', 32),
        'timeout' => (int) env('BOOKS_EMBEDDING_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | Retrieval is hybrid: a dense channel over the vector column and a lexical
    | channel over a stored tsvector, fused with Reciprocal Rank Fusion. Both
    | matter here. "a bitter gin drink with orange" shares no keyword with the
    | recipe that answers it, and "Blue Lady" is a proper noun that a vector
    | will happily confuse with every other blue drink in the corpus.
    |
    | "min_similarity" is a floor against nonsense rather than a relevance gate.
    | Cosine similarity over a single-domain corpus compresses into a narrow
    | band, so a high floor would throw away the ranking the fusion step exists
    | to do.
    |
    | "ef_search" must be at least dense_candidates or the HNSW scan quietly
    | returns fewer rows than asked for, which looks like a thin corpus rather
    | than a mis-set knob.
    |
    | "rrf_k" damps the contribution of top ranks: with k = 60, rank 1 scores
    | 1/61 and rank 10 scores 1/70, so a document found by both channels beats
    | one found brilliantly by a single channel. That is the property being
    | bought.
    |
    | "text_search_config" is english for the whole corpus. Non-English is 951
    | of 24,926 chunks (3.8%); the English stemmer under-stems them but never
    | drops them, and exact drink-name tokens still match. It is baked into the
    | generated column, so changing it means a migration.
    |
    | Reranking defaults on because it is local and costs nothing per query
    | beyond latency. It is not free of risk, though: over 25k short recipe
    | passages a cross-encoder's marginal value is smaller than it would be
    | over long documents, so measure before assuming it earns its latency. If
    | it is too slow, lower "candidates" before disabling the stage.
    |
    */

    'retrieval' => [
        'dense_candidates' => (int) env('BOOKS_RETRIEVAL_DENSE', 60),
        'lexical_candidates' => (int) env('BOOKS_RETRIEVAL_LEXICAL', 60),
        'min_similarity' => (float) env('BOOKS_RETRIEVAL_MIN_SIMILARITY', 0.30),
        'ef_search' => (int) env('BOOKS_RETRIEVAL_EF_SEARCH', 100),
        'text_search_config' => env('BOOKS_RETRIEVAL_TEXT_CONFIG', 'english'),
        'rrf_k' => (int) env('BOOKS_RETRIEVAL_RRF_K', 60),
        'weights' => [
            'dense' => (float) env('BOOKS_RETRIEVAL_WEIGHT_DENSE', 1.0),
            'lexical' => (float) env('BOOKS_RETRIEVAL_WEIGHT_LEXICAL', 1.0),
        ],
        'limit' => (int) env('BOOKS_RETRIEVAL_LIMIT', 8),

        'rerank' => [
            'enabled' => (bool) env('BOOKS_RERANK_ENABLED', true),
            'provider' => env('AI_RERANKING_PROVIDER', 'tei-rerank'),
            'model' => env('BOOKS_RERANKING_MODEL', 'BAAI/bge-reranker-v2-m3'),
            'candidates' => (int) env('BOOKS_RERANK_CANDIDATES', 40),
            'timeout' => (int) env('BOOKS_RERANK_TIMEOUT', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Drinks
    |--------------------------------------------------------------------------
    |
    | The drink layer turns printed headings into canonical drinks, so that
    | "what comes up time and time again" is a query rather than a guess. It is
    | derived entirely from text already in the database -- a pass over stored
    | chunk text with the same HeadingPatterns the chunker used -- so it costs
    | no inference and re-running it is seconds.
    |
    | "stop_headings" are folded keys for divisions of a book rather than
    | drinks: a chapter called "PUNCHES." is a heading, and counting it would
    | put the corpus's most common "drink" at the top of every tally. They live
    | here rather than in code because finding another one is a corpus finding,
    | which should be an edit and not a deploy.
    |
    | "fuzzy" governs whether two keys one edit apart are merged. It is off by
    | default and should stay off until a --merges review says otherwise. The
    | reason is worth stating plainly: a wrong merge fabricates a citation that
    | passes every check this layer makes. If "Brandy Sour" and "Brandy Soup"
    | fold together, the survey hands Eddie a row named Brandy Sour carrying a
    | real book, a real page and a real byte offset, on which the word actually
    | printed is "Soup" -- and a guest cannot tell. One edit on keys of six
    | characters or more catches the OCR substitution ("BLUE LADV") and little
    | else, but there is no setting that catches it and not the other, which is
    | why the review comes first and the flag comes second.
    |
    | "aliases" and "splits" win over the clusterer in both directions, keyed by
    | folded key. Both are empty on purpose, the same way strategy_overrides is:
    | nothing belongs here until a review calls for it.
    |
    | Embeddings are deliberately absent. bge-m3 places "Blue Lady" nearer
    | "Pink Lady" than its own misreading, so a vector is the wrong instrument
    | for name identity. The legitimate use is offline: propose candidate pairs
    | for a human to paste into "aliases". Suggestion in, never a write.
    |
    */

    'drinks' => [
        'stop_headings' => [
            'punches', 'punch', 'cocktails', 'cocktail', 'fizzes', 'sours',
            'cobblers', 'juleps', 'slings', 'toddies', 'smashes', 'daisies',
            'flips', 'sangarees', 'shrubs', 'eggnoggs', 'noggs', 'crustas',
            'fixes', 'rickeys', 'coolers', 'cups', 'wines', 'liqueurs',
            'cordials', 'syrups', 'bitters', 'index', 'contents', 'appendix',
            'preface', 'introduction', 'miscellaneous', 'miscellaneousdrinks',
            'temperancedrinks', 'hotdrinks', 'summerdrinks', 'winterdrinks',
        ],

        'fuzzy' => [
            'enabled' => (bool) env('BOOKS_DRINKS_FUZZY', false),
            'min_key_length' => (int) env('BOOKS_DRINKS_FUZZY_MIN_LENGTH', 6),
            'max_edits' => (int) env('BOOKS_DRINKS_FUZZY_MAX_EDITS', 1),
        ],

        /*
        | Keyed by folded key. 'aliases' forces a merge the clusterer would not
        | make; 'splits' forbids one it would. Empty on purpose.
        */
        'aliases' => [
            //
        ],
        'splits' => [
            //
        ],

        /*
        |----------------------------------------------------------------------
        | Countability
        |----------------------------------------------------------------------
        |
        | Which rows the tally is allowed to count. Nothing here deletes a drink
        | or a mention: DrinkClassifier sets drinks.is_countable and records its
        | measurements in drinks.signals, exactly as ChunkClassifier sets
        | book_chunks.is_indexable.
        |
        | "min_books" is the rule that does the work -- 7,011 of the first real
        | run's 9,437 rows were printed in exactly one book, and that tail is
        | where the OCR wreckage lives. It is deliberately not a quality
        | threshold: a drink two books printed independently is a drink, however
        | odd it looks, which is why "Bishop" and "Shandy Gaff" survive it.
        |
        | Deliberately absent: a minimum share of mentions in recipe chunks. It
        | reads like the obvious rule and the corpus says otherwise -- below a
        | quarter sit "Gothic Punch", "Bilberry Cordial", "Hock Cobbler" and
        | "Soldiers Camping Punch", real drinks this shelf happens to print only
        | inside prose. The share is recorded in signals for a later pass that
        | has better evidence; it decides nothing today.
        |
        | "noise_headings" is a list rather than a heuristic because the words on
        | it are not structurally distinguishable from drinks -- "This" appears
        | in 6 books and "Bishop" in 27, and nothing but English separates them.
        | Several are drop-cap artefacts, where a decorative first letter was
        | scanned as its own word: "Ne-Half" is one-half, "Uice" is juice, "Hree"
        | is three, "T He" is the. Grow it from `books:drinks --noise`, which
        | proposes candidates and writes nothing.
        */
        'classification' => [
            'min_books' => (int) env('BOOKS_DRINKS_MIN_BOOKS', 2),
            'max_words' => (int) env('BOOKS_DRINKS_MAX_WORDS', 6),
            'min_letter_ratio' => (float) env('BOOKS_DRINKS_MIN_LETTER_RATIO', 0.6),

            'noise_headings' => [
                // Sentence openers caught by the caps_prefix family.
                'this', 'there', 'here', 'heres', 'they', 'them', 'these', 'those',
                'when', 'then', 'before', 'after', 'although', 'having', 'never',
                'very', 'little', 'clean', 'take', 'mix', 'add', 'three', 'drink',

                // Drop-cap artefacts: the decorative initial read as its own word.
                'the', 'nehalf', 'nequarter', 'wothirds', 'hree', 'uice',
                'ablespoonful', 'ters',

                // Book furniture.
                'page', 'chapter', 'note', 'plate', 'figure',
            ],
        ],

        /*
        | The survey tool's shape. "citations" is how many printed occurrences
        | accompany each drink -- enough for Eddie to name a book and a page
        | without handing him a page of them to read out.
        */
        'survey' => [
            'limit' => (int) env('BOOKS_DRINKS_SURVEY_LIMIT', 10),
            'max_limit' => (int) env('BOOKS_DRINKS_SURVEY_MAX', 25),
            'citations' => (int) env('BOOKS_DRINKS_SURVEY_CITATIONS', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog Overrides
    |--------------------------------------------------------------------------
    |
    | Metadata is derived from filenames, which is right for the whole corpus
    | but cannot know what language a book is written in. Anything set here,
    | keyed by filename with or without the extension, wins over the parsed
    | value. Supported keys: title, author, year, edition, language.
    |
    | Language codes are Tesseract's, and may be combined with a plus sign for
    | books that mix two.
    |
    */

    'catalog' => [
        'Manual del Cantinero by León Pujol and Oscar Muñiz (1924)' => [
            'language' => 'spa',
        ],
        '1000 Misture by Elvezio Grassi (1936)' => [
            'language' => 'ita',
        ],
        'Bariana by Louis Fouquet (1896)' => [
            'language' => 'fra',
        ],
        'Cocktails Bar La Florida by Constante Ribalaigua Vert (1934)' => [
            'language' => 'spa+eng',
        ],
        'The Artistry of Mixing Drinks by Frank Meier (1936)' => [
            // Written in English at the Ritz in Paris, so it is dense with
            // French drink names and proper nouns.
            'language' => 'eng+fra',
        ],

        /*
        | Books added after the original 28. The filenames come from the EUVS
        | scans verbatim, so anything the parser gets wrong is corrected here
        | rather than by renaming the file away from its source.
        */
        '1883 McDonough\'s bar-keepers\' guide, and gentlemen\'s sideboard companion (1883).pdf' => [
            // Year appears twice; the trailing parenthetical wins and leaves the leading
            // one stranded at the front of the title.
            'title' => 'McDonough\'s Bar-Keepers\' Guide, and Gentlemen\'s Sideboard Companion',
        ],
        '1884 American and Other Drinks ( 1 st edition ) by Charlie Paul.pdf' => [
            // An edition note sits in the title half, where extractEdition cannot reach it.
            'title' => 'American and Other Drinks',
            'edition' => '1st edition',
        ],
        '1898 Mixology; the art of preparing all kinds of drinks ...pdf' => [
            // Scanner ellipsis marks a truncated subtitle, not part of the title.
            'title' => 'Mixology: The Art of Preparing All Kinds of Drinks',
        ],
        '1900 The 20th century guide for mixing fancy drinks ...pdf' => [
            'title' => 'The 20th Century Guide for Mixing Fancy Drinks',
        ],
        '1912 The Buffet Blue Book bu John H Considine.pdf' => [
            // Filename typo: "bu" for "by", so the author never splits off.
            'title' => 'The Buffet Blue Book',
            'author' => 'John H. Considine',
        ],
        '1913 Bartenders\' Manual (Bartenders Association of America).pdf' => [
            // Unlike the U.K.B.G. title, this parenthetical is the author, not part of
            // how the book is known.
            'title' => 'Bartenders\' Manual',
            'author' => 'Bartenders Association of America',
        ],
        '1923 Harry of Ciro\'s ABC of mixing cocktails (second impression).pdf' => [
            // "Harry of Ciro's" is the author, but reads as title text without a "by".
            'title' => 'ABC of Mixing Cocktails',
            'author' => 'Harry McElhone',
            'edition' => 'second impression',
        ],
        '1930 Cocktails by _Jimmy_ late of Ciro\'s London.pdf' => [
            // Underscores stand in for the quotation marks around the pseudonym.
            'author' => 'Jimmy, late of Ciro\'s London',
        ],
        '1933 The Cocktail Book Repeal Edition (New Revised Edition).pdf' => [
            'title' => 'The Cocktail Book',
            'edition' => 'Repeal Edition, New Revised',
        ],
        '1934 100 Famous Cocktails ( second printing ) by Oscar of the Waldorf.pdf' => [
            'title' => '100 Famous Cocktails',
            'edition' => 'second printing',
        ],
        '1935 Sloppy Joe\'s Bar ( season 1935 ).pdf' => [
            // EUVS issues one volume per season; the season is the edition.
            'title' => 'Sloppy Joe\'s Bar',
            'edition' => 'season 1935',
        ],
        '1922 Old Time Recipes Liquors Shrubs(4th edition) by Helen S Wright.pdf' => [
            // Filename abbreviates a much longer title.
            'title' => 'Old-Time Recipes for Home Made Wines, Cordials and Liqueurs',
            'author' => 'Helen S. Wright',
            'edition' => '4th edition',
        ],
        '1891 Cocktail Botthby\'s American Bar-Tender.pdf' => [
            // Filename misspells Boothby, which would otherwise reach a citation.
            'title' => 'Cocktail Boothby\'s American Bar-Tender',
            'author' => 'William T. Boothby',
        ],
        '1878 American and other drinks.pdf' => [
            'title' => 'American and Other Drinks',
            'author' => 'Leo Engel',
        ],
        '1927 Barflies and Cocktails.pdf' => [
            'author' => 'Harry McElhone',
        ],
        '1892 Drinks of the world.pdf' => [
            'title' => 'Drinks of the World',
            'author' => 'James Mew and John Ashton',
        ],
        'Cups & Their Custom by Henry Porter & George Roberts (1863).pdf' => [
            'title' => 'Cups and Their Customs',
        ],
    ],

];
