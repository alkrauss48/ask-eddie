<?php

namespace App\Services\Books;

use App\Enums\PageStatus;
use App\Enums\SectionKind;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\BookPage;
use App\Models\BookSection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a book's pages into sections and chunks.
 *
 * Chunks are rebuilt wholesale rather than patched. Boundaries are global to a
 * book -- one character changed on page 3 shifts every offset after it -- so a
 * diff would leave chunks whose text no longer matches their offsets and, once
 * Phase 3 lands, embeddings that no longer match their text. Each book is
 * rebuilt inside its own transaction, so an interrupted run leaves every book
 * either fully chunked or exactly as it was.
 */
class BookChunker
{
    /**
     * Bumped when assembly, boundary or sizing rules change.
     *
     * Page text is retained, so re-chunking the corpus costs one command rather
     * than another OCR pass. The same reason PageTextNormalizer::VERSION exists.
     */
    public const VERSION = 1;

    public function __construct(
        private readonly SectionDetector $detector,
        private readonly BookStreamBuilder $streams,
        private readonly BlockSegmenter $segmenter,
        private readonly ChunkPacker $packer,
        private readonly ChunkClassifier $classifier,
        private readonly TokenEstimator $tokens,
    ) {}

    /**
     * Cut one book, replacing whatever it had before.
     */
    public function chunkBook(Book $book): ChunkingReport
    {
        [$sections, $chunks, $report] = $this->plan($book);

        DB::transaction(function () use ($book, $sections, $chunks, $report): void {
            $book->chunks()->delete();
            $book->sections()->delete();

            $sectionIds = $this->persistSections($book, $sections);
            $this->persistChunks($book, $chunks, $sectionIds);

            $book->forceFill([
                'metadata' => array_merge($book->metadata ?? [], [
                    'chunking' => [
                        'strategy' => $report->strategy->value,
                        'chunker_version' => self::VERSION,
                        'classifier_version' => ChunkClassifier::VERSION,
                        'detector_version' => SectionDetector::VERSION,
                        'stream_checksum' => $this->streamChecksum($book),
                        'chunk_count' => count($chunks),
                        'chunked_at' => now()->toIso8601String(),
                    ],
                ]),
            ])->save();
        });

        return $report;
    }

    /**
     * Work out a book's sections and chunks without writing anything.
     *
     * Separated from persistence so that --dry-run is the same computation
     * rather than a second code path that might disagree with it.
     *
     * @return array{0: list<DetectedSection>, 1: list<array<string, mixed>>, 2: ChunkingReport}
     */
    public function plan(Book $book): array
    {
        $pages = $book->pages()->get();
        $extracted = $pages->filter(fn ($page): bool => $page->status === PageStatus::Extracted)->values();

        $labels = PageLabelIndex::forBook($pages);
        $structure = $this->detector->detect($book, $extracted);
        $stream = $this->streams->build($book, $pages, $structure, $labels);

        $blocks = $this->segmenter->segment($stream);
        $pending = $this->packer->pack($stream, $blocks, $structure->strategy, $structure);

        [$firstBody, $lastBody] = $this->bodyBounds($labels, $pages);

        $rows = [];
        $index = 0;
        $lengths = [];
        $kinds = [];
        $covered = [];
        $maxTokens = 0;
        $hardCuts = 0;
        $indexable = 0;
        $estimated = 0;
        $unlabelled = 0;
        $headings = 0;

        foreach ($pending as $chunk) {
            $range = $stream->pageRangeFor($chunk->contentStart(), $chunk->end);

            if ($range === null) {
                continue;
            }

            $classification = $this->classifier->classify($chunk->text, new ClassificationContext(
                language: (string) $book->language,
                pageFrom: $range->pageFrom,
                pageTo: $range->pageTo,
                firstBodyPage: $firstBody,
                lastBodyPage: $lastBody,
                headingPresent: $chunk->heading !== null,
                recipeHeadings: $chunk->recipeHeadings,
                lineCount: $chunk->lineCount,
            ));

            $section = $structure->sectionFor($range->pageFrom);
            $tokenEstimate = $this->tokens->estimate($this->embeddingPreview($book, $section?->title, $chunk));

            $rows[] = [
                'strategy' => $structure->strategy->value,
                'section_page_from' => $section?->pageFrom,
                'chunk_index' => $index++,
                'kind' => $classification->kind->value,
                'is_indexable' => $classification->isIndexable(),
                'section_title' => $section?->title,
                'heading' => $chunk->heading,
                'headings' => $chunk->headings === [] ? null : $chunk->headings,
                'text' => $chunk->text,
                'char_count' => mb_strlen($chunk->text),
                'word_count' => count(preg_split('/\s+/u', trim($chunk->text), -1, PREG_SPLIT_NO_EMPTY) ?: []),
                'token_estimate' => $tokenEstimate,
                'overlap_chars' => $chunk->overlapChars,
                'char_start' => $chunk->start,
                'char_end' => $chunk->end,
                'page_from' => $range->pageFrom,
                'page_to' => $range->pageTo,
                'printed_page_from' => $range->printedFrom,
                'printed_page_to' => $range->printedTo,
                'printed_pages_estimated' => $range->printedEstimated,
                'signals' => $chunk->signals + $classification->signals,
            ];

            $lengths[] = strlen($chunk->text);
            $covered[] = [$chunk->contentStart(), $chunk->end];
            $kinds[$classification->kind->value] = ($kinds[$classification->kind->value] ?? 0) + 1;
            $maxTokens = max($maxTokens, $tokenEstimate);
            $hardCuts += isset($chunk->signals['hard_cut']) ? 1 : 0;
            $indexable += $classification->isIndexable() ? 1 : 0;
            $estimated += $range->printedEstimated ? 1 : 0;
            $unlabelled += $range->printedFrom === null ? 1 : 0;
            $headings += $chunk->heading === null ? 0 : 1;
        }

        sort($lengths);

        $report = new ChunkingReport(
            strategy: $structure->strategy,
            pageCount: $extracted->count(),
            sectionCount: count(array_filter($structure->sections, fn (DetectedSection $s): bool => $s->kind->carriesText())),
            chunkCount: count($rows),
            indexableCount: $indexable,
            medianChars: $lengths === [] ? 0 : $lengths[intdiv(count($lengths), 2)],
            maxTokens: $maxTokens,
            hardCuts: $hardCuts,
            estimatedLabels: $estimated,
            unlabelled: $unlabelled,
            headingCount: $headings,
            kindCounts: $kinds,
            coverageChars: $this->contentCovered($stream, $covered),
            streamChars: $this->contentLength($stream->text),
        );

        return [$structure->sections, $rows, $report];
    }

    /**
     * Check stored chunks against the text they claim to be slices of.
     *
     * This is what the offsets are for. Re-assembling the book and comparing
     * each chunk to stream[char_start..char_end] proves the offsets, and the
     * offsets are what the page range is derived from -- so it proves the
     * citations too. The uncovered count then proves nothing was lost.
     *
     * @param  Collection<int, BookChunk>  $chunks
     * @return array{0: int, 1: list<string>, 2: int} stream length, mismatches, uncovered characters
     */
    public function verify(Book $book, Collection $chunks): array
    {
        $pages = $book->pages()->get();
        $extracted = $pages->filter(fn ($page): bool => $page->status === PageStatus::Extracted)->values();
        $labels = PageLabelIndex::forBook($pages);
        $structure = $this->detector->detect($book, $extracted);
        $stream = $this->streams->build($book, $pages, $structure, $labels);

        $mismatches = [];
        $covered = [];

        foreach ($chunks as $chunk) {
            $expected = $stream->slice((int) $chunk->char_start, (int) $chunk->char_end);

            if ($expected !== $chunk->text) {
                $mismatches[] = sprintf(
                    'chunk %d does not match bytes %d-%d of the assembled text',
                    $chunk->chunk_index,
                    $chunk->char_start,
                    $chunk->char_end,
                );
            }

            $range = $stream->pageRangeFor(
                (int) $chunk->char_start + (int) $chunk->overlap_chars,
                (int) $chunk->char_end,
            );

            if ($range === null || $range->pageFrom !== (int) $chunk->page_from || $range->pageTo !== (int) $chunk->page_to) {
                $mismatches[] = sprintf(
                    'chunk %d claims pages %d-%d but its offsets fall on %s',
                    $chunk->chunk_index,
                    $chunk->page_from,
                    $chunk->page_to,
                    $range === null ? 'no page' : $range->pageFrom.'-'.$range->pageTo,
                );
            }

            // A chunk must *begin* inside the section it is attributed to. It
            // may well end past it: a section's page range is where its running
            // head was observed, and a chapter's last page or two often carry
            // no head at all, so the range under-reports the chapter's true
            // extent by design. Attribution follows the chunk's first page,
            // which is the page the citation leads with.
            if ($chunk->book_section_id !== null) {
                $section = $chunk->section;

                if ($section !== null
                    && ((int) $chunk->page_from < (int) $section->page_from
                        || (int) $chunk->page_from > (int) $section->page_to)) {
                    $mismatches[] = sprintf(
                        'chunk %d starts on page %d, outside its section %s',
                        $chunk->chunk_index,
                        $chunk->page_from,
                        $section->pageRangeLabel(),
                    );
                }
            }

            $covered[] = [(int) $chunk->char_start + (int) $chunk->overlap_chars, (int) $chunk->char_end];
        }

        $content = $this->contentLength($stream->text);
        $uncovered = max(0, $content - $this->contentCovered($stream, $covered));

        return [$stream->length(), $mismatches, $uncovered];
    }

    /**
     * How many of the stream's content characters reached a chunk.
     *
     * Whitespace is excluded from both sides of this measure. The blank line
     * between two paragraphs belongs to no chunk by design -- chunks are
     * trimmed -- so counting raw characters would report 99.7% forever and make
     * the guarantee unfalsifiable. Counting non-whitespace instead makes it a
     * real check: every character of every recipe has to be somewhere.
     *
     * @param  list<array{0: int, 1: int}>  $ranges  each chunk's own content, overlap excluded
     */
    private function contentCovered(BookTextStream $stream, array $ranges): int
    {
        if ($ranges === []) {
            return 0;
        }

        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $covered = 0;
        $cursor = 0;

        foreach ($ranges as [$start, $end]) {
            $start = max($start, $cursor);

            if ($end <= $start) {
                continue;
            }

            $covered += $this->contentLength($stream->slice($start, $end));
            $cursor = $end;
        }

        return $covered;
    }

    /**
     * A string's length ignoring whitespace.
     */
    private function contentLength(string $text): int
    {
        return strlen(preg_replace('/\s+/u', '', $text) ?? $text);
    }

    /**
     * Whether this book's chunks predate the current rules or its page text.
     *
     * The checksum is what a version constant cannot cover: re-running
     * books:renormalize changes page text without touching any version here, and
     * without this the corpus would quietly drift out of step with its chunks.
     */
    public function isStale(Book $book): bool
    {
        $state = $book->metadata['chunking'] ?? null;

        if (! is_array($state) || $book->chunks()->doesntExist()) {
            return true;
        }

        if (($state['chunker_version'] ?? 0) !== self::VERSION
            || ($state['classifier_version'] ?? 0) !== ChunkClassifier::VERSION
            || ($state['detector_version'] ?? 0) !== SectionDetector::VERSION) {
            return true;
        }

        return ($state['stream_checksum'] ?? null) !== $this->streamChecksum($book);
    }

    /**
     * The checksum of the text this book would chunk from today.
     */
    public function streamChecksum(Book $book): string
    {
        $pages = $book->pages()->get();
        $extracted = $pages->filter(fn ($page): bool => $page->status === PageStatus::Extracted)->values();
        $labels = PageLabelIndex::forBook($pages);
        $structure = $this->detector->detect($book, $extracted);

        return $this->streams->build($book, $pages, $structure, $labels)->checksum;
    }

    /**
     * @param  list<DetectedSection>  $sections
     * @return array<int|string, int> keyed by the section's page_from
     */
    private function persistSections(Book $book, array $sections): array
    {
        $ids = [];
        $sequence = 0;

        foreach ($sections as $section) {
            $row = new BookSection([
                'book_id' => $book->id,
                'sequence' => $sequence++,
                'title' => $section->title,
                'kind' => $section->kind,
                'page_from' => $section->pageFrom,
                'page_to' => $section->pageTo,
                'head_variants' => $section->headVariants === [] ? null : $section->headVariants,
                'confidence' => $section->confidence,
                'detector_version' => SectionDetector::VERSION,
            ]);

            $row->save();

            if ($section->kind !== SectionKind::RunningHead) {
                $ids[$section->pageFrom] = (int) $row->id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @param  array<int|string, int>  $sectionIds
     */
    private function persistChunks(Book $book, array $chunks, array $sectionIds): void
    {
        $now = now();

        foreach (array_chunk($chunks, 500) as $batch) {
            $rows = [];

            foreach ($batch as $chunk) {
                $sectionPage = $chunk['section_page_from'];
                unset($chunk['section_page_from'], $chunk['strategy']);

                // insert() bypasses the model, so the json columns are encoded
                // and the timestamps set by hand.
                $chunk['book_id'] = $book->id;
                $chunk['book_section_id'] = $sectionPage === null ? null : ($sectionIds[$sectionPage] ?? null);
                $chunk['headings'] = $chunk['headings'] === null ? null : json_encode($chunk['headings']);
                $chunk['signals'] = json_encode($chunk['signals']);
                $chunk['chunker_version'] = self::VERSION;
                $chunk['classifier_version'] = ChunkClassifier::VERSION;
                $chunk['created_at'] = $now;
                $chunk['updated_at'] = $now;

                $rows[] = $chunk;
            }

            BookChunk::insert($rows);
        }
    }

    /**
     * What the embedded string will look like, for the token estimate.
     *
     * Mirrors BookChunk::embeddingText(); the model builds it for real once the
     * row exists, and this only needs to be the same size.
     */
    private function embeddingPreview(Book $book, ?string $sectionTitle, PendingChunk $chunk): string
    {
        $prefix = implode(' — ', array_filter([
            $book->year === null ? (string) $book->title : $book->title.' ('.$book->year.')',
            $sectionTitle,
            $chunk->heading,
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        return $prefix === '' ? $chunk->text : $prefix."\n\n".$chunk->text;
    }

    /**
     * The first and last page of the book proper, from its numbering series.
     *
     * Reuses the label index rather than guessing: where a book's arabic folios
     * begin is exactly where its front matter ends. A book with no coherent
     * numbering gets nulls, and front and back matter simply go unclassified,
     * which is the honest outcome.
     *
     * @param  Collection<int, BookPage>  $pages
     * @return array{0: int|null, 1: int|null}
     */
    private function bodyBounds(PageLabelIndex $labels, Collection $pages): array
    {
        $arabic = array_values(array_filter(
            $labels->series(),
            fn (LabelSeries $series): bool => $series->script === 'arabic',
        ));

        if ($arabic === []) {
            return [null, null];
        }

        return [$arabic[0]->pageFrom, $arabic[count($arabic) - 1]->pageTo];
    }
}
