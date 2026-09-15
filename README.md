# Ask Eddie

Eddie is a 1930s uptown bartender you can talk to — and unlike most cocktail chatbots, he
doesn't make things up. Every drink he pours is grounded in a corpus of public-domain
bartending manuals published between the 1860s and the 1930s, cited back to the book, the
edition, and the page it came from.

Getting there takes two halves:

1. **Build the corpus.** Turn a shelf of scanned PDFs into clean, page-addressable,
   citation-bearing text — see [Step 1: the book pipeline](#step-1-the-book-pipeline).
2. **Cut it into passages.** Turn those pages into retrievable chunks that each know the
   book and the page range they came from — see [Step 2: chunking](#step-2-chunking).
3. **Give it a voice.** Embed the chunks into pgvector, retrieve against them with a hybrid
   query, and serve them through Eddie — see
   [Step 3: embed, retrieve, answer](#step-3-embed-retrieve-answer).

---

## Eddie's persona

The system prompt Eddie is being built around. It is reproduced here verbatim because it is
a product decision, not an implementation detail — the retrieval layer exists to serve it,
and the corpus is what keeps it honest.

> **SYSTEM PROMPT: "Eddie, 1930s Uptown Bartender AI"**
>
> You are Eddie, a suave, unflappable 1930s bartender working in an upscale uptown lounge
> where the lighting is low, the brass glints like a wink, and the piano never quite stops
> humming. Your entire persona is rooted in effortless charm, quiet competence, and the
> sense that you've seen a thousand nights and a thousand stories walk through your doors.
>
> You speak with the warmth and rhythm of a seasoned barkeep—smooth voice, a little wit, a
> little wisdom, never corny, never modern, never breaking the illusion of your time and
> place. You address guests as friend, pal, doll, sir, madam, or with their name if given.
> You carry yourself with the dignity and ease of someone who takes pride in the perfect
> pour, the perfect garnish, the perfect moment.
>
> Your knowledge of drinks is encyclopedic and delivered naturally, like you've been making
> them for decades. You describe cocktails with sensory detail—aroma, texture, color,
> mood—inviting the guest into the experience rather than lecturing. When a drink doesn't
> exist, you invent one on the spot with period-appropriate ingredients and flair.
>
> Your tone stays grounded in hospitality. You ask gentle questions to understand what your
> guest likes—sweet or stiff, smoky or bright, classic or adventurous—and then offer
> suggestions with a knowing smile. You can tell small stories about prohibition, regulars
> you once knew, jazz nights, and the little truths a bartender picks up over time.
> Everything remains classy, cool, and in-era.
>
> You never break character. You never reference technology, AI, or anything beyond your
> 1930s world. You stay in the bar, polishing a glass, adjusting the radio, or leaning in
> like you've got all the time in the world for the person in front of you.
>
> Your goals are simple: make the guest feel welcome, mix them the perfect drink, and keep
> the atmosphere glowing like the last tableside candle of the night.

Eddie stays in character, but he does not get to invent history. A drink he presents as
coming from a book has to actually come from that book — which is why the corpus is built
first, and built to preserve the page.

---

## Step 1: the book pipeline

Takes a directory of scanned PDFs and produces one row of clean text per physical page,
with the printed page number preserved and the raw tool output kept alongside it.

### What a run does

```
PDFs on the books disk
        │
        │  books:import        filename → title / author / year / edition / language,
        │                      sha256 checksum, page count
        ▼
   books table
        │
        │  books:extract       per book, in parallel waves:
        │
        │    ┌─ pdftotext -layout ──────────► text layer candidate
        │    │                                (one call for the whole book)
        │    │
        │    └─ pdftoppm ──► page PNG ──► tesseract ──► OCR candidate
        │
        │                      each candidate is normalized and quality-scored,
        │                      then both are stored
        ▼
book_page_extractions          two rows per page: raw_text + normalized text + score
        │
        │  PagePromoter        pick the winner per page (highest score, or the
        │                      book's preferred source if one is set)
        ▼
   book_pages                  canonical text, printed_page_label, char/word counts
```

Design decisions worth knowing before you run it:

- **Every page is OCR'd by default.** The strategy is `ocr_all`, not `gated`. The embedded
  text layer in this corpus is a pre-LSTM OCR pass that renders "ROCHESTER PUNCH" as
  `KOCllESTKli rUKCll.`, and the quality scorer can't reliably tell a lightly damaged page
  from a clean one. A full corpus run costs about an hour of local CPU, once. Set
  `BOOKS_STRATEGY=gated` to OCR only low-scoring pages instead.
- **Both candidates are kept forever.** Choosing between the text layer and the OCR is a
  query (`books:compare --promote`), not another hour of work.
- **Raw tool output is kept forever too.** Improving the normalizer means bumping
  `PageTextNormalizer::VERSION` and running `books:renormalize` — one pass over the
  database, no PDFs touched.
- **Normalization never deletes a line.** This corpus feeds a citation-bearing index, so a
  dropped ingredient line becomes a wrong recipe downstream with nothing to signal it.
  Garbage filtering belongs at chunk time, where there's context to judge with.
- **Render and OCR both run in parallel waves.** `pdftoppm` is roughly a third of a page's
  cost. Pooling only Tesseract pinned the container to one core (~16 pages/min on a 159 MB
  scan); pooling both stages took the same book to ~240 pages/min.
- **Printed page labels are captured before they're stripped.** Physical page 47 of a book
  with front matter is printed "39", and a citation needs the printed one.

### Requirements

The pipeline shells out to `pdfinfo`, `pdftotext`, `pdftoppm` (poppler-utils) and
`tesseract` with the `eng`, `spa`, `ita` and `fra` language packs. All four are baked into
the Sail image by the OCR layer at the bottom of `docker/8.5/Dockerfile`, so under Sail
there is nothing to install.

### Running it

```bash
# 0. Point BOOKS_PATH at your directory of PDFs, then bring the stack up.
sail up -d
sail artisan migrate

# 1. Confirm the toolchain, the source disk and the scratch directory are usable.
sail artisan books:doctor

# 2. Register the PDFs. Cheap, idempotent, safe to re-run.
sail artisan books:import

# 3. Extract. This is the long one.
sail artisan books:extract

# 4. See where things stand.
sail artisan books:status
```

`books:extract` holds a cache lock for the duration, so a second concurrent run is turned
away rather than duplicating every page's work. Interrupting it is safe — each parallel
wave is committed before the next begins, so a Ctrl-C loses at most one wave, and re-running
picks up only the pages that aren't done.

### Commands

| Command | What it does |
| --- | --- |
| `books:doctor` | Checks the four binaries, the Tesseract language packs, the source disk and the working directory. Run this first when something breaks. |
| `books:import` | Registers the PDFs on the books disk with their metadata and checksums. `--dry-run` reports what would change. Re-hashing is skipped unless size or mtime moved; a book whose PDF disappeared is marked missing and its text is kept. |
| `books:extract` | The main event. `--book=slug` (repeatable), `--pages=10-40`, `--strategy=`, `--concurrency=`, `--dpi=`, `--psm=`, `--force`, `--retry-failed`. |
| `books:status` | Per-book progress: pages done, pages failed, the OCR/text-layer split, and mean quality. |
| `books:compare` | Prints a book's two candidates side by side for sampled pages. `--pages=6`, `--page=30` (repeatable), and `--promote=ocr\|text_layer` to lock a book to one source. |
| `books:renormalize` | Recomputes text and quality scores from stored raw output. No OCR, no PDFs. `--book=`, `--all`. |

A changed PDF (new checksum) drops that book's pages, because a re-scan may have been
re-paginated and pages keyed by the old numbering would be quietly wrong rather than merely
stale.

### Reading quality scores

Pages are scored 0–1 on how much of their text looks like real language. The scorer is
deliberately dictionary-free — the corpus is full of obscure French and Spanish liqueur
names and archaic spellings, and a word list would reject as much good text as bad. Measured
against real pages:

| Score | What it looks like |
| --- | --- |
| 0.58 | library stamps, scanner noise |
| 0.84 | index pages, dominated by dot leaders |
| 0.94 | readable prose carrying one mangled heading |
| 0.97 | clean prose |

It detects **malformed** words, not **wrong** ones. "Snuterne" for "Sauterne" is correctly
cased and plausibly spelled, so it scores as clean. Treat the number as a severity flag and
a way to rank two candidates against each other — not as proof a page is correct. That
limitation is exactly why the default strategy OCRs everything instead of trusting the
score.

### Configuration

Set in `.env`; see `config/books.php` for the rest and the reasoning behind each default.

| Variable | Default | Purpose |
| --- | --- | --- |
| `BOOKS_PATH` | the reference corpus (gitignored) | Directory of source PDFs. Must be a local path — the tools are CLI binaries. |
| `BOOKS_TEMP_PATH` | `/tmp/ask-eddie` | Scratch space for PDF copies and page images. Keep it off the bind mount; rendering re-reads the PDF once per page. |
| `BOOKS_STRATEGY` | `ocr_all` | `ocr_all` or `gated`. |
| `BOOKS_CONCURRENCY` | `8` | Pages rendered and OCR'd per wave. |
| `BOOKS_OCR_DPI` | `300` | Render resolution handed to Tesseract. |
| `BOOKS_OCR_PSM` | `1` | Page segmentation mode; 1 is auto with orientation/script detection. |
| `BOOKS_PAGE_TIMEOUT` | `180` | Per-process timeout, seconds. |
| `BOOKS_QUALITY_MIN_SCORE` | `0.90` | Severity floor. Only used under `gated`. |

Language is per book and can't be guessed from a filename, so `config('books.catalog')`
overrides metadata by filename — that's where the Spanish, Italian and French titles get
their Tesseract language codes (including combinations like `eng+fra` for Frank Meier's
Ritz-Paris book, which is English but dense with French drink names).

### Data model

- **`books`** — one row per PDF. Slug is derived once at import and is independent of the
  filename, so renaming a PDF never orphans the citations that will point at it. Carries
  the checksum, page count, status, and an optional `preferred_text_source`.
- **`book_pages`** — one row per physical page. Holds the *promoted* text, its source, the
  printed page label, and char/word counts. This is the table the retrieval layer will read.
- **`book_page_extractions`** — one row per (page, source), so two per page. Holds
  `raw_text` exactly as the tool emitted it, the normalized `text`, the quality score and
  its breakdown, the settings used, and the normalizer version.

---

## Step 2: chunking

Takes the page rows from step 1 and cuts them into overlapping passages, each carrying the
book, the section it sits in, and the page range it came from.

```
book_pages
     │
     │  SectionDetector      running heads -> chapter page ranges, and which heads
     │                       are noise; also picks this book's cutting strategy
     │
     │  PageLabelIndex       observed folios -> numbering series -> the printed page
     │                       number for pages the scanner could not read
     ▼
BookStreamBuilder            the whole book as one string, plus an index back to
     │                       every page. Words broken across a page turn are rejoined.
     │
     │  BlockSegmenter       blank-line paragraphs, subdivided until each fits
     │  ChunkPacker          blocks packed into chunks; a recipe is never split
     │  ChunkClassifier      recipe / prose / index / advertisement / noise …
     ▼
book_sections + book_chunks
```

Current corpus: **8,554 chunks across 30 books**, 7,979 of them indexable.

### Why chunking works on a whole book at a time

Because a third of the corpus does not stop at the page boundary. **1,445 pages (29%) end
mid-sentence and 127 end mid-word**, so cutting each page independently would sever every one
of them and split recipes that run over a page turn. Instead each book is assembled into a
single stream with an index back to its pages: a word broken as `cock-` / `tail` is rejoined,
a sentence still in progress continues with a space, and a finished page gets a blank line.

Page ranges then fall out of the offsets rather than being tracked alongside them, which is
what makes them checkable — see `books:chunks --verify`.

### Decisions worth knowing

- **A recipe is never split.** Where a book's heading structure is dense enough to measure,
  chunks are anchored to headings; elsewhere paragraph blocks are simply packed to a size
  target. The choice is measured per book, not hand-assigned, and recorded in
  `books.metadata.chunking.strategy`. Eighteen of the cocktail books take the heading path.
- **Several whole recipes travel together.** Café Royal's median paragraph is 20 characters;
  one chunk per recipe would produce thousands of near-contextless vectors. A chunk holds
  several complete recipes and names all of them.
- **Recipe chunks get no overlap.** A tail borrowed from the previous chunk would put a
  neighbouring drink's ingredients under this chunk's citation. Prose chunks do overlap,
  taken as whole sentences from the text immediately before them.
- **`max_chars` is a ceiling, not a hint, and it is asserted in code.** With 3.6 characters
  budgeted per token, a chunk plus its provenance prefix stays inside a 512-token window, so
  no embedding model chosen in step 3 can silently truncate one.
- **Nothing is deleted — it is classified.** Index pages, publisher advertisements, front
  matter and scanner noise all become chunks with a `kind`, and retrieval filters on it. This
  is the debt `.ai/rules/books.md` parks here: normalization must never drop a line, and
  garbage filtering belongs at chunk time "where there is context". Re-deciding is an
  `UPDATE`, not another run.
- **The one thing chunking does remove is a proven running head**, and it keeps a receipt.
  Old Waldorf Bar Days prints its own title on 103 versos, which would otherwise be repeated
  inside 103 chunks; the `book_sections` row that accounts for it stores every spelling and
  the pages each appeared on, and the page rows still carry the head either way.
- **Printed page numbers are interpolated, and flagged when they are.** Only 61% of pages
  carry a folio the scanner could read.

### Reading a citation

`book_chunks` exists to answer "where did that come from", so a chunk renders its own:

```
Old Waldorf Bar Days (1931), "Concerning the Curriculum", pp. 107–108 (PDF pp. 119–120)
Café Royal Cocktail Book (1937), "Cocktails", PDF pp. 39–40
The Flowing Bowl (1892), pp. ~295–~296 (PDF pp. 299–300)
```

The physical PDF page is always present and always exact — anyone can open the file at that
page. The printed page leads when it is known, and a **tilde marks one interpolated** from the
book's numbering series rather than read off the page.

That interpolation is why most citations have a printed page at all. Folios are read on only
3,108 of 5,099 pages, and a single book-wide offset would be wrong for most books: When It's
Cocktail Time in Cuba runs at an offset of −17 through page 29 and −18 from page 31, because
an unnumbered plate is bound in between, and Harry Johnson 1888 shifts seventeen times. So
observed folios are split into **numbering series** — a run of consecutive pages that advance
in step — and only interpolated within one. Arabic and roman are kept apart, since front
matter restarts. A series needs two consecutive agreeing observations before it is believed,
because an isolated folio in this corpus is usually a misread: Old Waldorf offers "1931" from
its title page, and Café Royal's thirteen folios include "1937", "C" and "0", no two agreeing.
**Café Royal therefore reports no printed page at all** — the honest answer, since a guessed
page number is a fabricated citation.

### Commands

| Command | What it does |
| --- | --- |
| `books:chunk` | Cuts extracted pages into chunks. `--book=slug` (repeatable), `--force`, `--dry-run`. Skips books already current; re-chunks a book whose pages or rules have moved. **Exits non-zero if any content was dropped**, a chunk overran the token ceiling, or a cut had to fall inside a word. |
| `books:chunks {book}` | Reads chunks back. `--outline` for the detected structure, `--page=`, `--kind=`, `--excluded`, `--search=`, `--payload` for the literal retrieval payload, and `--verify`. |

`books:status` gains a `Chunks` count and a `Chunked` column showing `v1`, `stale`, or `—`.
A book goes stale when a version constant moves **or** when the text it was cut from changes,
which is what catches a `books:renormalize` run: the check compares the assembled stream's
checksum, not just the versions.

### `--verify`, and why the offsets are stored

Every chunk records its byte offsets into the assembled stream. `books:chunks {book} --verify`
re-assembles the book and asserts that each chunk is exactly the slice it claims to be, that
its page range is the one those offsets fall on, and that the chunks together account for
every non-whitespace character of the book:

```
Old Waldorf Bar Days (1931)
  361 chunk(s) over 284,200 characters of assembled text.
  offsets: every chunk is exactly the slice it claims
  coverage: every content character is accounted for
```

That turns "chunking never loses a line" from a claim into a check, and because the page range
is derived from the offsets, proving the offsets proves the citations. Whitespace is excluded
from the coverage measure: the blank line between two paragraphs belongs to no chunk by design,
and counting it would leave the number stuck near 99.7% and unfalsifiable.

### Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `BOOKS_CHUNK_TARGET` | `1200` | Target chunk size in characters, roughly one page. |
| `BOOKS_CHUNK_MAX` | `1600` | Hard ceiling. Asserted, not hoped for. |
| `BOOKS_CHUNK_MIN` | `250` | Below this a chunk merges with its neighbour — or is kept as it is, never dropped. |
| `BOOKS_CHUNK_OVERLAP` | `200` | Prose overlap. Recipes get none. |
| `BOOKS_CHUNK_MAX_TOKENS` | `440` | Token ceiling including the provenance prefix. |
| `BOOKS_CHUNK_CHARS_PER_TOKEN` | `3.6` | Measured at 5.73 characters per word; budgeted low for OCR debris and non-English titles. |
| `BOOKS_CHUNK_HEADINGS_PER_PAGE` | `1.5` | Headings per page needed for the heading strategy. |

`config('books.chunking.strategy_overrides')` pins a book to a strategy by slug, the same way
`catalog` overrides its metadata. It is empty on purpose.

### Data model

- **`book_sections`** — one row per detected division: title, kind, the page range its running
  head was observed over, a confidence, and `head_variants` recording every spelling that
  produced it. A section's range is where its *head* appeared, which under-reports the section
  slightly — a chapter's last page often carries no head — so a chunk is attributed to the
  section it *begins* in and may end a page past it.
- **`book_chunks`** — one row per passage. Verbatim text with the heading left in place,
  char/word/token counts, the byte offsets it was cut from, the physical and printed page
  ranges, its `kind` and `is_indexable`, and the `signals` the classifier acted on.

`BookChunk::toArray()` is deliberately narrow, because step 3's retrieval tool hands the model
`Arr::except($chunk->toArray(), ['embedding'])` verbatim — the serialized shape *is* the
citation payload. It is eight keys: `book_title`, `author`, `year`, `section_title`, `heading`,
`pages`, `citation`, `text`. `BookChunkCitationTest` asserts that set exactly, so a column
added later cannot leak into a prompt unnoticed.

The embedded string is derived rather than stored, by `BookChunk::embeddingText()`, so `text`
stays byte-identical to the corpus and a citation can always be checked by re-slicing the
book. It prefixes the book, year, section and heading, because a query like "a 1930s London
gin cocktail" has to match provenance the passage never states — and because the Python
pipeline this replaces lifted the heading out of the body and then embedded the body alone,
leaving "Blue Lady" unsearchable in a book that is nothing but drink names.

---

## Step 3: embed, retrieve, answer

Turns 24,926 indexable chunks into vectors, retrieves against them with a hybrid dense +
lexical query, and hands the winners to Eddie as citation-bearing passages.

```
book_chunks
     │
     │  books:embed         BookChunk::embeddingText() -> TEI (bge-m3, 1024d)
     ▼                      written back with the model, width and version that made it
embedding  vector(1024)     HNSW, vector_cosine_ops
search_vector  tsvector     generated column, GIN

     a question
     │
     ├── dense    embed the query with the same model -> HNSW nearest neighbours (60)
     │
     └── lexical  websearch_to_tsquery -> ts_rank_cd over search_vector (60)
             │
             ▼
       Reciprocal Rank Fusion    score = Σ wᵢ / (k + rankᵢ)
             │
             ▼
       cross-encoder rerank      bge-reranker-v2-m3, top 40, optional
             │
             ▼
       8 passages -> SearchTheBooks -> EddieAgent
```

### Why it is hybrid

Neither channel is sufficient, and the two verification queries show why:

- **"a bitter gin drink with orange"** shares no keyword with the recipe that answers it.
  Only the vector finds it.
- **"Blue Lady"** is a proper noun an embedding will happily place next to every other blue
  drink in the corpus. Only the tsvector pins it.

RRF fuses the two on rank alone, never on the channels' own scores — cosine similarity and
`ts_rank_cd` are not comparable numbers, and normalizing them into one scale is a guess that
changes with the corpus. With `k = 60`, a passage both channels found in their top ten beats
one a single channel put first, which is the property being bought.

`eddie:ask --sources` prints both channel ranks beside the fused score. That is not
decoration: a hybrid search where one channel silently returns nothing answers questions
perfectly well, slightly worse, in a way no single answer reveals. A column of dashes shows
it immediately.

### Why local inference, and why the same server in both environments

Embeddings come from [Text Embeddings Inference](https://github.com/huggingface/text-embeddings-inference)
running in Docker — `tei-embed` for `BAAI/bge-m3`, `tei-rerank` for `BAAI/bge-reranker-v2-m3`.
Production has to embed the user's query on every request, so a model-serving process must
exist there regardless; TEI serves embeddings *and* cross-encoder rerankers from one image,
so reranking is incremental on infrastructure that already has to be there. It speaks
OpenAI's `/v1/embeddings` shape, so `laravel/ai`'s stock `openai-compatible` driver drives it
with no custom code.

Using it in **both** environments is deliberate. The corpus vectors and every query vector
must come from the same implementation: a llama.cpp-based server and a transformers-based one
can differ in pooling or normalization, and the mismatch degrades retrieval silently rather
than loudly. Swapping implementations later is only safe behind an equivalence check — embed
a 100-chunk sample through both and assert pairwise cosine similarity > 0.999 first.

`bge-m3` is chosen for being *symmetric*. Retrieval auto-embeds a bare query string with no
instruction prefix, and the obvious alternatives (`multilingual-e5-large`,
`nomic-embed-text`) are asymmetric — they want a `query:` prefix and lose recall without one,
with no error to show for it. bge-m3 needs none, is natively 1024-dimensional (under
pgvector's 2,000-dim HNSW ceiling), and is multilingual for the 951 non-English chunks and
the accented drink names throughout.

### Running it

```bash
sail up -d                                     # brings up tei-embed and tei-rerank
sail artisan books:doctor                      # both TEI services reachable, model ids match
sail artisan migrate

sail artisan books:embed --book=heres-how-1927 # time one small book first
sail artisan books:embed                       # then the corpus
sail artisan books:embed --verify

sail artisan eddie:ask "what goes in a Blue Lady?" --sources
sail artisan eddie:ask "a bitter gin drink with orange" --sources
```

`books:embed` is resumable. A vector belongs to exactly one chunk and is written by an
id-keyed update, and each batch commits in its own transaction rather than the run holding
one open — so interrupting it costs one batch, and re-running picks up only what still needs
a vector. Every row records the model, width and embedder version that produced it, which is
what makes trying a new model a command rather than a manual purge: a row disagreeing with
configuration is pending again without `--force`.

### On Apple Silicon, this is slow

TEI publishes **linux/amd64 only** — there is no arm64 manifest for any tag — so
`compose.yaml` pins `platform: linux/amd64` and the containers run under emulation. Measured
on an M-series host:

| | Emulated (Apple Silicon) | Expected on amd64 |
| --- | --- | --- |
| Bulk embed | ~0.8 chunks/s → **~9 hours** for the corpus | 1–3 hours |
| Rerank, 40 passages | **~66 seconds** | 0.5–1.5s |

It is not misconfigured — the embedder runs at ~870% CPU, saturating the cores. This is the
price of one embedding implementation across dev and production, and it is paid once for the
bulk embed. Set `BOOKS_RERANK_ENABLED=false` locally, though: retrieval degrades to the fused
RRF order, which is most of the quality anyway, and a 66-second pause is not a bar.

The two containers also hold about **9 GB between them** (embed ~5.2, rerank ~4.1) whether or
not they are serving anything. Stop `tei-rerank` before a bulk embed — it is only needed at
query time. A full run alongside another project's Docker stack was killed for low memory an
hour in; `books:embed` resumed from exactly where it stopped, but the lock a killed run leaves
behind has to be cleared by hand (the command prints how).

Two TEI flags are not optional. `--max-batch-tokens 4096`, because the 16384 default
allocates a warm-up buffer bge-m3 cannot fit beside its float32 weights and the container is
OOM-killed before it serves anything. And `--max-client-batch-size 64` on the reranker,
because the default is 32 while `rerank.candidates` is 40 — which would 413 every request.

### The schema, and what it pins

Both indexes are one-way doors over 26,466 rows, and two of them fail *quietly* when they
drift, so each has a test:

- **`vector(1024)`** is hard-coded in the migration rather than read from config, because a
  migration must replay identically forever. `BookChunkEmbeddingSchemaTest` pins the column's
  width to `books.embedding.dimensions`, so a divergence fails at edit time.
- **`vector_cosine_ops`** is baked into the HNSW index. `whereVectorSimilarTo` compiles to
  `<=>` and converts `minSimilarity` as `1 - similarity`, which is meaningful only for
  cosine. An `l2_ops` index would rank *almost* right, which is harder to notice than ranking
  wrong.
- **`search_vector`** is a generated `tsvector` written by hand, not with `$table->fullText()`.
  `PostgresGrammar::compileFulltext()` emits `to_tsvector(cfg, a) || to_tsvector(cfg, b)` with
  no `coalesce`, and NULL propagates through `||`. Only 56% of chunks have a `heading` and 36%
  a `section_title`, so `fullText()` would leave **64% of the corpus indexed as NULL** —
  matching nothing, forever, with nothing anywhere to say so. The test that pins this looks
  for a chunk whose only distinguishing word is "Curaçao" in its body.

`headings` is in the lexical index too, so a packed block of ten Cafe Royal recipes is
findable by all ten drink names rather than only the one it opens with.

A later index rebuild over populated rows wants `set maintenance_work_mem = '512MB'`:
24,926 × 1024 float4 is ~102 MB against a 64 MB default, which forces pgvector's slow
two-pass build.

### `books:embed --verify`

Seven invariants, each a query, each something retrieval is otherwise entitled to assume. A
corpus that fails one still answers questions — just with the wrong passages.

- every indexable chunk is embedded, and **no non-indexable chunk is**
- exactly one `(model, dimensions, version)` triple exists table-wide, and it matches config
- `vector_dims(embedding)` equals the width each row claims
- no zero-norm vector (cosine distance against one is NaN, which poisons every ordering it
  reaches)
- no chunk whose `updated_at` moved after it was embedded — `books:renormalize` and
  `books:chunk` both do that without touching any version number

### The eight-key payload is still eight keys

`BookChunk::toArray()` *is* what the language model is handed, and Step 3 added six columns to
the table. All six are hidden, and both `BookChunkCitationTest` and `SearchTheBooksToolTest`
assert the payload **by count**, not by subset — once on a chunk that has an embedding and a
populated `search_vector`.

Retrieval metadata lives on `RetrievedChunk`, never on the model: no `setAttribute('score')`,
no `$appends`, no `addSelect` of a distance alias. A score in the payload reads to the model
as content it may repeat, and "relevance 0.87" in a bartender's answer is both meaningless to
a guest and a break in character.

### Reranking is a mode, not a dependency

`NullReranker` is bound whenever the stage is disabled, and `AiReranker` falls back to it on
any failure — an unreachable service, an error response, or a provider name that turns out not
to support reranking at all (`AiManager::rerankingProvider()` throws a `LogicException`
outright for that). A cross-encoder outage costs ordering quality, never an exception in the
middle of answering a guest.

It is a legitimate mode rather than a stub. Hybrid plus RRF is the bulk of the quality gain,
and over 25,000 short recipe passages a cross-encoder's marginal value is smaller than it
would be over long documents. Measure before assuming it earns its latency; if it is too
slow, lower `BOOKS_RERANK_CANDIDATES` before turning the stage off.

TEI's `/rerank` is not a shape `laravel/ai` ships, so `TeiRerankerProvider` is registered with
`Ai::extend('tei-rerank', …)` in `AppServiceProvider::boot()`. It is a real provider, so
`Reranking::of()`, `Reranking::fake()` and everything else work on it unchanged — the only
difference from using Cohere is the name in configuration.

### Commands

| Command | What it does |
| --- | --- |
| `books:embed` | Embeds indexable chunks. `--book=slug` (repeatable), `--force`, `--batch=`, `--dry-run`, `--verify`. Resumable; holds a cache lock. |
| `eddie:ask` | Asks Eddie a question. `--sources` shows both channel ranks and the fused score, `--retrieval-only` stops before the language model, `--limit=`. |

### Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `TEI_EMBED_URL` | `http://tei-embed:80/v1` | Embedding service, as seen from inside the Docker network. |
| `TEI_RERANK_URL` | `http://tei-rerank:80` | Reranking service. |
| `BOOKS_EMBEDDING_MODEL` | `BAAI/bge-m3` | Recorded per row; changing it makes the corpus pending. |
| `BOOKS_EMBEDDING_DIMENSIONS` | `1024` | Pinned to the column's own width by a test. |
| `BOOKS_EMBEDDING_BATCH` | `32` | Chunks per request. TEI's client batch cap is 32. |
| `BOOKS_RETRIEVAL_DENSE` | `60` | Dense candidates before fusion. |
| `BOOKS_RETRIEVAL_LEXICAL` | `60` | Lexical candidates before fusion. |
| `BOOKS_RETRIEVAL_MIN_SIMILARITY` | `0.30` | A floor against nonsense, not a relevance gate. |
| `BOOKS_RETRIEVAL_EF_SEARCH` | `100` | Must be ≥ the dense candidate count or HNSW returns short. |
| `BOOKS_RETRIEVAL_RRF_K` | `60` | Fusion damping. |
| `BOOKS_RETRIEVAL_LIMIT` | `8` | Passages handed to Eddie. |
| `BOOKS_RERANK_ENABLED` | `true` | Set `false` on Apple Silicon. |
| `BOOKS_RERANK_CANDIDATES` | `40` | Lower this before disabling the stage. |
| `OPENAI_TEXT_MODEL` | `gpt-5.6-luna` | Model that writes Eddie's answers. |

`ai.default` stays on OpenAI for text generation. `default_for_embeddings`,
`default_for_reranking` and `default` resolve independently, which is exactly the
multi-provider shape the package is built for — but leaving `default_for_embeddings` on
`openai` would be the one silent catastrophe here, because `whereVectorSimilarTo` auto-embeds
with no model argument and queries would be embedded by a different model than the corpus.

### Deployment

The two TEI containers must exist in production too, and `BOOKS_EMBEDDING_MODEL` must name
the model `tei-embed` is actually serving — `books:doctor` checks exactly that, because a TEI
instance serves one model and never says so in a response. On real amd64 hardware neither the
embed rate nor the rerank latency above applies; both are emulation artefacts.

---

## Local development

Standard Laravel Sail, with two local modifications:

- Postgres is `pgvector/pgvector:pg18` rather than stock Postgres, ready for the embedding
  step.
- `docker/8.5/Dockerfile` was published from Sail so the OCR toolchain could be added, and
  no longer tracks upstream. Diff it against
  `vendor/laravel/sail/runtimes/8.5/Dockerfile` after a Sail upgrade.

```bash
cp .env.example .env
sail up -d
sail artisan key:generate
sail artisan migrate
```

The app serves on `http://localhost:9000` by default (`APP_PORT`).

### Tests

```bash
sail artisan test --compact
```

Step 1's coverage lives in `tests/Unit/PageTextNormalizerTest.php`,
`tests/Unit/PageTextQualityTest.php`, `tests/Unit/BookMetadataParserTest.php`,
`tests/Unit/TesseractOcrTest.php`, and the `tests/Feature/Books*CommandTest.php` files.
The normalizer tests pin the two deliberate departures from the original Python pipeline —
they exist to fail loudly if someone reintroduces line-dropping or the letter-joining
"spacing fix".

Step 2 adds `tests/Unit/{HeadingPatterns,PageLabelIndex,SectionDetector,BookStreamBuilder,
BlockSegmenter,ChunkPacker,ChunkClassifier,TokenEstimator}Test.php` and
`tests/Feature/{BookChunker,BookChunkCitation,BooksChunkCommand,BooksChunksCommand}Test.php`.

Step 3 adds `tests/Unit/ReciprocalRankFusionTest.php` and
`tests/Feature/{BookChunkEmbeddingSchema,BooksEmbedCommand,ChunkRetriever,SearchTheBooksTool,
TeiRerankerProvider,Reranker,EddieAskCommand}Test.php`. **No test requires TEI to be running** —
`Http::preventStrayRequests()` is on for the whole Feature suite and `phpunit.xml` points both
TEI URLs at an unroutable host as a second backstop. `Embeddings::fake()` clones the
*resolved* provider, so fake vectors come out at 1024 dimensions with nothing said. Vectors in
tests are one-hot (`unitVector()`), because cosine similarity between two one-hot vectors is
exactly 0 or exactly 1 — which makes an ordering assertion a fact rather than a probability.
Several are regression pins in the same spirit: that chunks tile their input with nothing
lost, that a short-line recipe block is never mistaken for a list, that overlap can only come
from the immediately preceding text, and that the citation payload is exactly eight keys.

Chunking services take plain strings and unsaved models, so the algorithmic work is unit
tested with no database. Real page text lives in `tests/Fixtures/Books/`, named for the page
it was exported from, so a failing assertion can be checked against the actual scan.
