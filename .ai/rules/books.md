---
paths:
  - 'app/Services/Books/**'
  - app/Services/Books/PageTextNormalizer.php
  - app/Services/Books/PageExtractor.php
---

# Books

## Parallel Tesseract must set OMP_THREAD_LIMIT=1
Tesseract is multithreaded by default and takes every visible core. Pages are OCR'd by a pool of parallel Tesseract processes, so without `OMP_THREAD_LIMIT=1` in the child environment the CPU is oversubscribed badly enough to run slower than serial. `TesseractOcr::environment()` sets it; anything that shells out to tesseract must use it.

Also: `Process::pool`/`Process::concurrently` have no concurrency cap (unlike `Http::pool`) — they start every process handed to them at once. Chunk work into waves of `config('books.extraction.concurrency')` yourself.

## Normalization must never delete a line of content
This corpus feeds a citation-bearing RAG index, so a dropped ingredient line becomes an incorrect recipe downstream with nothing to signal it. The Python original (`.claude/source/cocktail-historian/scripts/01_extract_text.py`) dropped lines under 3 chars, lines below 40% alphanumeric, and lines with >3 apostrophes — which between them delete "Gin.", "1/2", rule lines, and most French text. Do not port that. Garbage filtering belongs at chunk time where there is context.

Also deliberately not ported: the "OCR spacing fixes" loop, which ran `\b([a-z]) ([a-z]{1,})\b` → `$1$2` five times and turned "a drink" into "adrink". Regression tests pin both decisions.

Bump `PageTextNormalizer::VERSION` when rules change; `raw_text` is kept on every extraction so the corpus can be re-normalized without re-OCR.

## Render and OCR must both run in parallel waves
`pdftoppm` is roughly a third of a page's cost and grows with the source scan's size. An early version rendered pages serially and only pooled tesseract, which pinned the container to a single core: on the 159 MB Harry Johnson 1888 that was ~16 pages/min. Pooling both stages took the same book to ~240 pages/min (40 pages in 10s).

If throughput ever collapses again, check `docker stats` — 100% CPU means one core, i.e. something in the wave went serial.
