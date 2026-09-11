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

## Chunk a whole book at a time, never page by page
1,445 of 4,910 extracted pages (29%) end mid-sentence and 127 end mid-word, so cutting each page independently severs a third of the corpus's sentences. BookStreamBuilder assembles a book into one string with a PageSpan index; chunk offsets are then resolved back to page ranges, which is where citations come from.

Offsets are BYTE offsets throughout, because preg_* reports bytes when capturing positions. Every cut must land on a line, sentence or word boundary or the slice is invalid UTF-8 that Postgres rejects mid-insert. ChunkPacker asserts mb_check_encoding for exactly this reason.

Never use trim() with a character list containing a multibyte character — the list is matched byte by byte and can shear one byte off an unrelated character. Use HeadingPatterns::trimEdges(), which is preg-based. This bug produced a heading ending in half of the "ç" in "Curaçao".

## Chunk ceilings are asserted; garbage is classified, never deleted
config('books.chunking.max_chars') is a ceiling enforced in ChunkPacker::assertWithinCeiling(), not a packing hint. The Python original treated its equivalent as a threshold, so oversized chunks reached a 512-token model and lost their tails silently. Overlap is stored inside the chunk text and spends the same budget, so content is packed to TokenEstimator::content*Ceiling() which reserves room for it — pack to the full ceiling and the overlap is silently dropped instead.

Nothing is deleted at chunk time. ChunkClassifier sets kind + is_indexable and records its measurements in signals; retrieval filters on the column. This is the debt .ai/rules/books.md parks in this phase. Recognise a recipe positively (heading + measure or instruction word) and test that before any density heuristic, or a page of 20-character ingredient lines reads as a list — the Python's "80% short lines" rule would delete every page of Cafe Royal.

Bump BookChunker::VERSION when assembly, boundary or sizing rules change; ChunkClassifier::VERSION when only classification does; SectionDetector::VERSION for structure. isStale() also compares the assembled stream's checksum, which is what catches a books:renormalize run that moved page text without touching any version.
