# Ask Eddie

Eddie is a 1930s uptown bartender you can talk to — and unlike most cocktail chatbots, he
doesn't make things up. Every drink he pours is grounded in a corpus of public-domain
bartending manuals published between the 1860s and the 1930s, cited back to the book, the
edition, and the page it came from.

Getting there takes two halves:

1. **Build the corpus.** Turn a shelf of scanned PDFs into clean, page-addressable,
   citation-bearing text. **This is what exists today** — see
   [Step 1: the book pipeline](#step-1-the-book-pipeline).
2. **Give it a voice.** Chunk and embed that text into pgvector, retrieve against it, and
   serve it through Eddie. Not built yet; the schema and the `laravel/ai` SDK are in place
   for it.

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

The pipeline's own coverage lives in `tests/Unit/PageTextNormalizerTest.php`,
`tests/Unit/PageTextQualityTest.php`, `tests/Unit/BookMetadataParserTest.php`,
`tests/Unit/TesseractOcrTest.php`, and the `tests/Feature/Books*CommandTest.php` files.
The normalizer tests pin the two deliberate departures from the original Python pipeline —
they exist to fail loudly if someone reintroduces line-dropping or the letter-joining
"spacing fix".
