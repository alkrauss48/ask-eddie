<?php

namespace App\Services\Books;

use App\Enums\ChunkStrategy;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\Drink;
use App\Models\DrinkMention;
use App\Services\Retrieval\DrinkCoverage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns printed headings into canonical drinks and their occurrences.
 *
 * Written wholesale per book inside a transaction, the way BookChunker rebuilds
 * a book's chunks: a book's mentions are a function of its chunks, so patching
 * them would leave rows whose offsets no longer point where they claim. Unlike
 * books:embed there is nothing to resume -- the whole corpus is a regex pass
 * over text already in Postgres and finishes in seconds, so batch-level
 * resumability would be machinery this workload does not need.
 */
class DrinkExtractor
{
    /**
     * Bumped when scanning or acceptance changes, which means a re-scan of
     * chunk text. Folding and clustering move under
     * DrinkNameNormalizer::VERSION instead, because re-folding needs no re-scan.
     */
    public const VERSION = 1;

    public function __construct(
        private readonly DrinkHeadingScanner $scanner,
        private readonly DrinkNameNormalizer $normalizer,
        private readonly DrinkClusterer $clusterer,
        private readonly DrinkClassifier $classifier,
    ) {}

    /**
     * Find one book's drink names without writing anything.
     *
     * Separated from persistence so --dry-run is the same computation rather
     * than a second code path that can disagree with it, exactly as
     * BookChunker::plan() is.
     *
     * @return array{0: list<array<string, mixed>>, 1: DrinkExtractionReport}
     */
    public function plan(Book $book): array
    {
        $chunks = $book->chunks()->where('is_indexable', true)->get();

        $rows = [];

        // Seeded from the drinks already recorded, not just the ones this book
        // produces, so that a spelling in one book can merge into a spelling
        // first seen in another. Scoped per book, fuzzy matching would silently
        // only ever fold a variant into a name printed in the same volume --
        // which is the one place a book is least likely to spell it two ways.
        //
        // It does make a fuzzy run order-dependent: what a key merges into
        // depends on what has been extracted before it. That is another reason
        // the flag defaults off and --merges exists to be read.
        $keys = array_flip(Drink::query()->pluck('canonical_key')->all());
        $stopped = 0;
        $merged = [];

        foreach ($chunks as $chunk) {
            foreach ($this->scanner->scan($chunk) as $mention) {
                $key = $this->normalizer->key($mention->rawHeading);

                if ($key === '' || $this->clusterer->isStopHeading($key)) {
                    $stopped++;

                    continue;
                }

                $resolved = $this->clusterer->resolve($key, $keys);

                if ($resolved !== $key) {
                    $merged[] = "{$mention->rawHeading} -> {$resolved}";
                }

                $keys[$resolved] = true;
                $rows[] = $this->row($book, $chunk, $mention, $resolved);
            }
        }

        return [$rows, new DrinkExtractionReport(
            strategy: $this->strategyOf($book),
            chunkCount: $chunks->count(),
            mentionCount: count($rows),
            drinkCount: count(array_unique(array_column($rows, 'canonical_key'))),
            stopHeadingCount: $stopped,
            pageCount: (int) $book->page_count,
            mergedKeys: $merged,
        )];
    }

    /**
     * Extract one book, replacing whatever it had before.
     */
    public function extractBook(Book $book): DrinkExtractionReport
    {
        [$rows, $report] = $this->plan($book);

        DB::transaction(function () use ($book, $rows, $report): void {
            DrinkMention::query()->where('book_id', $book->id)->delete();

            $this->persistMentions($this->assignDrinks($rows));

            $book->forceFill([
                'metadata' => array_merge($book->metadata ?? [], [
                    'drinks' => [
                        'extractor_version' => self::VERSION,
                        'normalizer_version' => DrinkNameNormalizer::VERSION,
                        'scanner_version' => DrinkHeadingScanner::VERSION,
                        'classifier_version' => DrinkClassifier::VERSION,
                        // Borrowed from the chunking state rather than
                        // recomputed, so a books:chunk or books:renormalize run
                        // makes drinks stale without this class ever
                        // re-assembling a stream or touching book_pages.
                        'chunker_version' => $book->metadata['chunking']['chunker_version'] ?? null,
                        'stream_checksum' => $book->metadata['chunking']['stream_checksum'] ?? null,
                        'mention_count' => $report->mentionCount,
                        'drink_count' => $report->drinkCount,
                        'extracted_at' => now()->toIso8601String(),
                    ],
                ]),
            ])->save();
        });

        return $report;
    }

    /**
     * Rewrite every drink's aggregates from its mentions, and drop the empties.
     *
     * Always recomputed, never incremented. An incremented count is one that
     * drifts silently across a partial re-run, and these numbers exist to be
     * checkable -- --verify recomputes them again and asserts they match.
     */
    public function recomputeAggregates(): void
    {
        Drink::query()->chunkById(200, function ($drinks): void {
            foreach ($drinks as $drink) {
                $mentions = DrinkMention::query()
                    ->where('drink_id', $drink->id)
                    ->get(['book_id', 'book_year', 'raw_heading', 'chunk_kind', 'heading_family']);

                if ($mentions->isEmpty()) {
                    $drink->delete();

                    continue;
                }

                $years = $mentions->pluck('book_year')->filter()->sort()->values();
                $spellings = $mentions->countBy('raw_heading')->sortDesc();
                $display = $this->normalizer->display((string) $spellings->keys()->first());

                // Classified in the same pass that derives the counts, because
                // the verdict is a function of them: a rule reading book_count
                // must not read one this pass is about to overwrite.
                $verdict = $this->classifier->classify(
                    $display,
                    $drink->canonical_key,
                    DrinkEvidence::fromMentions($mentions),
                );

                $drink->forceFill([
                    'canonical_name' => $display,
                    'slug' => $this->uniqueSlug($display, $drink),
                    'aliases' => $spellings->all(),
                    'is_countable' => $verdict->isCountable,
                    'signals' => $verdict->signals + ['reason' => $verdict->reason],
                    'mention_count' => $mentions->count(),
                    'book_count' => $mentions->pluck('book_id')->unique()->count(),
                    'first_year' => $years->first(),
                    'last_year' => $years->last(),
                    'first_book_id' => $this->earliestBookId($mentions),
                    'extractor_version' => self::VERSION,
                    'normalizer_version' => DrinkNameNormalizer::VERSION,
                    'classifier_version' => DrinkClassifier::VERSION,
                ])->save();
            }
        });
    }

    /**
     * Re-derive countability without re-reading a single chunk.
     *
     * Classification is a pure function of stored mentions, so moving
     * DrinkClassifier::VERSION -- or editing the noise_headings list, which is
     * the common case -- costs this pass rather than a re-extraction. The book
     * stamps are rewritten too, or every book would keep reporting itself stale
     * against a classifier that has already run over it.
     *
     * @return int the number of drinks now countable
     */
    public function reclassify(): int
    {
        $this->recomputeAggregates();

        Book::query()->whereNotNull('metadata')->chunkById(100, function ($books): void {
            foreach ($books as $book) {
                $state = $book->metadata['drinks'] ?? null;

                if (! is_array($state)) {
                    continue;
                }

                $book->forceFill([
                    'metadata' => array_merge($book->metadata, [
                        'drinks' => array_merge($state, ['classifier_version' => DrinkClassifier::VERSION]),
                    ]),
                ])->save();
            }
        });

        return Drink::query()->where('is_countable', true)->count();
    }

    /**
     * Whether this book's drinks were derived by rules that have since moved.
     */
    public function isStale(Book $book): bool
    {
        $state = $book->metadata['drinks'] ?? null;

        if (! is_array($state)) {
            return true;
        }

        if (($state['extractor_version'] ?? 0) !== self::VERSION
            || ($state['normalizer_version'] ?? 0) !== DrinkNameNormalizer::VERSION
            || ($state['scanner_version'] ?? 0) !== DrinkHeadingScanner::VERSION) {
            return true;
        }

        // Classification alone is repaired by --reclassify rather than by
        // re-extracting the book, so it is reported as staleness but costs a
        // pass over stored mentions instead of a pass over chunk text.
        if (($state['classifier_version'] ?? 0) !== DrinkClassifier::VERSION) {
            return true;
        }

        $chunking = $book->metadata['chunking'] ?? [];

        return ($state['chunker_version'] ?? null) !== ($chunking['chunker_version'] ?? null)
            || ($state['stream_checksum'] ?? null) !== ($chunking['stream_checksum'] ?? null);
    }

    /**
     * Check the drink layer against the things retrieval is entitled to assume.
     *
     * One string per failure, in the shape of ChunkEmbedder::verify(). A corpus
     * that fails one of these still answers questions -- with a tally that is
     * quietly wrong, which is the only kind of wrong this layer can produce.
     *
     * @return list<string>
     */
    public function verify(): array
    {
        $failures = [];

        // The one that matters. A mention is a claim that a string was printed
        // at a byte offset; proving the offset proves the page range the
        // citation is rendered from. Everything else here is bookkeeping.
        $misplaced = [];
        $mismatched = [];
        $excluded = 0;
        $wrongYear = 0;

        DrinkMention::query()
            // forRetrieval() keeps the 4 KB vector and the stored tsvector out
            // of the hydrated chunks; neither is read here, and this walks
            // every mention in the corpus.
            ->with(['chunk' => fn ($chunk) => $chunk->forRetrieval()])
            ->chunkById(500, function ($mentions) use (
                &$misplaced, &$mismatched, &$excluded, &$wrongYear
            ): void {
                foreach ($mentions as $mention) {
                    $chunk = $mention->chunk;

                    if ($chunk === null) {
                        $misplaced[] = $mention->id;

                        continue;
                    }

                    if (! $chunk->is_indexable) {
                        $excluded++;
                    }

                    // Byte offsets throughout, so substr() rather than mb_substr().
                    $offset = $mention->char_start - $chunk->char_start;
                    $found = substr($chunk->text, $offset, strlen($mention->raw_heading));

                    if ($found !== $mention->raw_heading) {
                        $misplaced[] = $mention->id;
                    }

                    if ($mention->page_from !== $chunk->page_from
                        || $mention->page_to !== $chunk->page_to
                        || $mention->printed_page_from !== $chunk->printed_page_from
                        || $mention->printed_page_to !== $chunk->printed_page_to
                        || $mention->printed_pages_estimated !== $chunk->printed_pages_estimated) {
                        $mismatched[] = $mention->id;
                    }

                    if ($mention->book_year !== $chunk->book?->year) {
                        $wrongYear++;
                    }
                }
            });

        if ($misplaced !== []) {
            $failures[] = sprintf(
                '%d mention(s) are not the string they claim at the offset they claim: %s',
                count($misplaced),
                implode(', ', array_slice($misplaced, 0, 5)),
            );
        }

        if ($mismatched !== []) {
            $failures[] = sprintf(
                '%d mention(s) cite a page their chunk does not: %s',
                count($mismatched),
                implode(', ', array_slice($mismatched, 0, 5)),
            );
        }

        // The mirror image of "a vector on a chunk the classifier excluded",
        // and the one that keeps a book's own index out of its tally.
        if ($excluded > 0) {
            $failures[] = "{$excluded} mention(s) point at a chunk retrieval excludes";
        }

        if ($wrongYear > 0) {
            $failures[] = "{$wrongYear} mention(s) record a year their book does not";
        }

        $failures = array_merge($failures, $this->verifyDrinks());

        return $failures;
    }

    /**
     * The drink-level invariants: materialization, folding, and receipts.
     *
     * @return list<string>
     */
    private function verifyDrinks(): array
    {
        $failures = [];
        $empty = 0;
        $drifted = [];
        $unfoldable = [];
        $orphanAliases = [];
        $stopWords = [];

        Drink::query()->chunkById(200, function ($drinks) use (
            &$empty, &$drifted, &$unfoldable, &$orphanAliases, &$stopWords
        ): void {
            foreach ($drinks as $drink) {
                $mentions = DrinkMention::query()
                    ->where('drink_id', $drink->id)
                    ->get(['book_id', 'book_year', 'raw_heading']);

                if ($mentions->isEmpty()) {
                    $empty++;

                    continue;
                }

                $years = $mentions->pluck('book_year')->filter()->sort()->values();

                if ($drink->mention_count !== $mentions->count()
                    || $drink->book_count !== $mentions->pluck('book_id')->unique()->count()
                    || $drink->first_year !== $years->first()
                    || $drink->last_year !== $years->last()) {
                    $drifted[] = $drink->canonical_name;
                }

                // A stored name that no longer folds to its own key means the
                // normalizer moved without the corpus being re-extracted.
                if ($this->normalizer->key($drink->canonical_name) !== $drink->canonical_key) {
                    $unfoldable[] = $drink->canonical_name;
                }

                $printed = $mentions->pluck('raw_heading')->unique()->all();

                foreach (array_keys($drink->aliases ?? []) as $alias) {
                    if (! in_array($alias, $printed, true)) {
                        $orphanAliases[] = "{$drink->canonical_name}: {$alias}";
                    }
                }

                if ($this->clusterer->isStopHeading($drink->canonical_key)) {
                    $stopWords[] = $drink->canonical_name;
                }
            }
        });

        if ($empty > 0) {
            $failures[] = "{$empty} drink(s) have no mentions left";
        }

        if ($drifted !== []) {
            $failures[] = sprintf(
                '%d drink(s) store a tally their mentions do not support: %s',
                count($drifted),
                implode(', ', array_slice($drifted, 0, 5)),
            );
        }

        if ($unfoldable !== []) {
            $failures[] = sprintf(
                '%d drink(s) no longer fold to their own key: %s',
                count($unfoldable),
                implode(', ', array_slice($unfoldable, 0, 5)),
            );
        }

        if ($orphanAliases !== []) {
            $failures[] = sprintf(
                '%d alias(es) name a spelling no mention records: %s',
                count($orphanAliases),
                implode(', ', array_slice($orphanAliases, 0, 5)),
            );
        }

        if ($stopWords !== []) {
            $failures[] = sprintf(
                '%d drink(s) are division headings: %s',
                count($stopWords),
                implode(', ', array_slice($stopWords, 0, 5)),
            );
        }

        $pairs = Drink::query()
            ->select('extractor_version', 'normalizer_version', 'classifier_version')
            ->distinct()
            ->get();

        if ($pairs->count() > 1) {
            $failures[] = sprintf(
                'the corpus holds %d different (extractor, normalizer, classifier) version triples: %s',
                $pairs->count(),
                $pairs->map(fn (Drink $row): string => "v{$row->extractor_version}/v{$row->normalizer_version}/v{$row->classifier_version}")->implode(', '),
            );
        }

        $pair = $pairs->first();

        if ($pair !== null && ((int) $pair->extractor_version !== self::VERSION
            || (int) $pair->normalizer_version !== DrinkNameNormalizer::VERSION
            || (int) $pair->classifier_version !== DrinkClassifier::VERSION)) {
            $failures[] = sprintf(
                'the corpus was extracted by v%d/v%d/v%d but the code is v%d/v%d/v%d',
                $pair->extractor_version, $pair->normalizer_version, $pair->classifier_version,
                self::VERSION, DrinkNameNormalizer::VERSION, DrinkClassifier::VERSION,
            );
        }

        return $failures;
    }

    /**
     * How much of the shelf the tally actually covers.
     *
     * Reported rather than asserted, and it is the number that keeps Eddie
     * honest: the narrative books print few headings, which is the same
     * measurement that put them on the packing path, so a corpus-wide claim
     * drawn from this layer is really a claim about the books it could count.
     *
     * @return array{counted: int, total: int, drinks: int, uncountable: int, silent: list<string>, carrying: int}
     */
    public function coverage(): array
    {
        $perBook = DrinkMention::query()
            ->selectRaw('book_id, count(*) as total')
            ->groupBy('book_id')
            ->pluck('total')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $counted = count($perBook);
        $total = Book::query()->count();

        $silent = Book::query()
            ->whereNotIn('id', DrinkMention::query()->select('book_id')->distinct())
            ->orderBy('slug')
            ->pluck('slug')
            ->all();

        return [
            'counted' => $counted,
            'total' => $total,
            // The countable rows only, because this is the number that reaches
            // a sentence Eddie says out loud. The rest are reported beside it
            // rather than folded in, so a growing tail stays visible.
            'drinks' => Drink::query()->where('is_countable', true)->count(),
            'uncountable' => Drink::query()->where('is_countable', false)->count(),
            'silent' => $silent,
            // Every book in this corpus yields at least one name, so a count of
            // books says nothing about where the tally came from. This does.
            'carrying' => DrinkCoverage::carrying($perBook),
        ];
    }

    /**
     * Assign each pending row the id of the drink it belongs to.
     *
     * Upserted against the canonical_key unique index rather than searched for,
     * which is what makes a second run produce the same rows rather than a
     * second set of them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function assignDrinks(array $rows): array
    {
        $ids = [];

        foreach ($rows as $index => $row) {
            $key = $row['canonical_key'];

            $ids[$key] ??= Drink::query()->updateOrCreate(
                ['canonical_key' => $key],
                [
                    // Provisional: recomputeAggregates() replaces both with the
                    // most frequent spelling once every book has been seen.
                    'canonical_name' => $this->normalizer->display($row['raw_heading']),
                    'slug' => Str::slug($key) ?: $key,
                    'extractor_version' => self::VERSION,
                    'normalizer_version' => DrinkNameNormalizer::VERSION,
                ],
            )->id;

            $rows[$index]['drink_id'] = $ids[$key];
            unset($rows[$index]['canonical_key']);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persistMentions(array $rows): void
    {
        $now = now();

        foreach (array_chunk($rows, 500) as $batch) {
            foreach ($batch as $index => $row) {
                // insert() bypasses the model, so the timestamps are set here.
                $batch[$index]['created_at'] = $now;
                $batch[$index]['updated_at'] = $now;
            }

            DrinkMention::insert($batch);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Book $book, BookChunk $chunk, PendingMention $mention, string $key): array
    {
        return [
            'canonical_key' => $key,
            'book_id' => $book->id,
            'book_chunk_id' => $chunk->id,
            'book_year' => $book->year,
            'raw_heading' => $mention->rawHeading,
            'heading_family' => $mention->family,
            'heading_number' => $mention->number,
            'chunk_kind' => $chunk->kind->value,
            'section_title' => $chunk->section_title,
            'page_from' => $chunk->page_from,
            'page_to' => $chunk->page_to,
            'printed_page_from' => $chunk->printed_page_from,
            'printed_page_to' => $chunk->printed_page_to,
            'printed_pages_estimated' => $chunk->printed_pages_estimated,
            'char_start' => $chunk->char_start + $mention->offsetInChunk,
            'extractor_version' => self::VERSION,
        ];
    }

    /**
     * @param  Collection<int, DrinkMention>  $mentions
     */
    private function earliestBookId(Collection $mentions): ?int
    {
        $earliest = $mentions->filter(fn (DrinkMention $mention): bool => $mention->book_year !== null)
            ->sortBy('book_year')
            ->first();

        return $earliest?->book_id;
    }

    /**
     * A slug no other drink already holds.
     *
     * Two drinks can fold to different keys and still title-case to the same
     * display name, and the slug carries a unique index because config
     * overrides are keyed by it.
     */
    private function uniqueSlug(string $display, Drink $drink): string
    {
        $base = Str::slug($display) ?: $drink->canonical_key;
        $slug = $base;
        $suffix = 2;

        while (Drink::query()->where('slug', $slug)->whereKeyNot($drink->id)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function strategyOf(Book $book): ChunkStrategy
    {
        $strategy = $book->metadata['chunking']['strategy'] ?? null;

        return ChunkStrategy::tryFrom((string) $strategy) ?? ChunkStrategy::Packing;
    }
}
