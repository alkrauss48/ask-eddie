<?php

namespace App\Services\Books;

use App\Models\Book;
use App\Models\BookChunk;
use App\Services\Embedding\BatchEmbedder;
use App\Services\Embedding\EmbeddingReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns chunks into vectors, one batch at a time.
 *
 * Unlike BookChunker, nothing here is rebuilt wholesale: a vector belongs to
 * exactly one chunk and is written by an update keyed on that chunk's id, so a
 * run is resumable at batch granularity. Each batch commits in its own
 * transaction rather than the run holding one open, because the initial bulk
 * embed of 24,926 chunks runs for hours on CPU and a Ctrl-C should cost one
 * batch, not the lot.
 *
 * What is embedded is BookChunk::embeddingText(), not "text" -- the
 * provenance-prefixed string, so "a 1930s London gin cocktail" can match facts
 * the passage never states. The Python pipeline this replaces embedded the body
 * alone after lifting the heading out of it, which left "Blue Lady" unsearchable
 * in a book that is nothing but drink names. BooksEmbedCommandTest pins it.
 *
 * The request itself, and the guards that make it safe to write, belong to
 * BatchEmbedder and are shared with the house corpus. What stays here is what
 * is actually about books: which chunks are pending, how a book is paged
 * through, and the invariants --verify asks of the finished corpus.
 */
class ChunkEmbedder
{
    /**
     * Bumped when the string handed to the model changes shape.
     *
     * A row whose recorded version is below this is pending without --force,
     * the same way a chunk below BookChunker::VERSION is stale. Changing the
     * model or its dimensions has the same effect without a bump, because both
     * are recorded per row too.
     */
    public const VERSION = 1;

    private readonly BatchEmbedder $batch;

    /**
     * Constructed rather than injected, so that the container cannot hand this
     * the house corpus's knobs. BatchEmbedder is corpus-scoped by name, and a
     * type-hinted parameter would resolve to whichever one the container
     * happened to build -- which is the kind of mistake that produces a working
     * application and a wrongly embedded corpus.
     */
    public function __construct()
    {
        $this->batch = new BatchEmbedder('books');
    }

    /**
     * Every chunk that needs a vector it does not have.
     *
     * The predicate is the definition of "pending" and is shared by the
     * command, the progress count and --verify, so those three cannot drift
     * apart and report different totals for the same corpus.
     *
     * @param  list<string>  $slugs
     * @return Builder<BookChunk>
     */
    public function pending(array $slugs = [], bool $force = false): Builder
    {
        $model = $this->model();
        $dimensions = $this->dimensions();

        return BookChunk::query()
            ->where('is_indexable', true)
            ->when($slugs !== [], fn (Builder $query) => $query->whereHas(
                'book', fn ($book) => $book->whereIn('slug', $slugs)
            ))
            ->when(! $force, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('embedding')
                ->orWhere('embedding_model', '!=', $model)
                ->orWhere('embedding_dimensions', '!=', $dimensions)
                ->orWhere('embedder_version', '<', self::VERSION)));
    }

    /**
     * Embed one book's pending chunks.
     *
     * @param  (callable(int): void)|null  $progress  called with each batch's size
     */
    public function embedBook(Book $book, bool $force = false, ?int $batchSize = null, ?callable $progress = null): EmbeddingReport
    {
        $batchSize = $batchSize ?? (int) config('books.embedding.batch_size');
        $batchSize = max(1, $batchSize);

        $report = new EmbeddingReport(
            indexableCount: $book->chunks()->where('is_indexable', true)->count(),
            pendingCount: $this->pending([$book->slug], $force)->count(),
        );

        $started = microtime(true);

        // Paged by id rather than by offset because every batch removes its own
        // rows from the pending set, which would make an offset skip them.
        // forRetrieval() keeps the 4 KB vector out of the hydrated rows: with
        // --force they all have one, and none of them is read here.
        $this->pending([$book->slug], $force)
            ->forRetrieval()
            ->chunkById($batchSize, function (Collection $chunks) use ($report, $progress): void {
                $report->tokens += $this->embedBatch($chunks);
                $report->embeddedCount += $chunks->count();
                $report->batchCount++;

                if ($progress !== null) {
                    $progress($chunks->count());
                }
            });

        $report->seconds = microtime(true) - $started;

        return $report;
    }

    /**
     * Embed one batch and write its vectors, atomically.
     *
     * @param  Collection<int, BookChunk>  $chunks
     * @return int tokens reported by the provider
     */
    public function embedBatch(Collection $chunks): int
    {
        $inputs = $chunks->map(fn (BookChunk $chunk): string => $chunk->embeddingText())->values()->all();

        // The batch's count and every vector's width are checked in there, and
        // it throws rather than returning something that cannot be trusted --
        // so reaching this line means the vectors line up with the chunks.
        $batch = $this->batch->embed($inputs);

        $now = now();

        DB::transaction(function () use ($chunks, $batch, $now): void {
            foreach ($chunks->values() as $index => $chunk) {
                BookChunk::query()->whereKey($chunk->id)->update([
                    'embedding' => $batch->literal($index),
                    'embedding_model' => $batch->model,
                    'embedding_dimensions' => $batch->dimensions,
                    'embedder_version' => self::VERSION,
                    'embedded_at' => $now,
                    // Written explicitly so that embedded_at is never behind
                    // updated_at by a tick, which --verify reads as a chunk
                    // whose text moved after it was embedded.
                    'updated_at' => $now,
                ]);
            }
        });

        return $batch->tokens;
    }

    /**
     * Whether this book has indexable chunks that are not currently embedded.
     */
    public function isStale(Book $book): bool
    {
        return $this->pending([$book->slug])->exists();
    }

    /**
     * Every way the embedded corpus can be wrong, asked as a query.
     *
     * These are invariants rather than statistics: each one, if it holds, is
     * something retrieval is allowed to assume. A corpus that fails any of them
     * still answers questions, just with the wrong passages, which is why they
     * are checked rather than hoped for.
     *
     * @return list<string> the failures, empty when the corpus is sound
     */
    public function verify(): array
    {
        $failures = [];
        $model = $this->model();
        $dimensions = $this->dimensions();

        $unembedded = BookChunk::query()
            ->where('is_indexable', true)
            ->whereNull('embedding')
            ->count();

        if ($unembedded > 0) {
            $failures[] = "{$unembedded} indexable chunk(s) have no embedding";
        }

        // The mirror image, and the one people forget: a vector on a chunk the
        // classifier excluded means retrieval can return an index page.
        $excluded = BookChunk::query()
            ->where('is_indexable', false)
            ->whereNotNull('embedding')
            ->count();

        if ($excluded > 0) {
            $failures[] = "{$excluded} non-indexable chunk(s) carry an embedding";
        }

        $triples = BookChunk::query()
            ->whereNotNull('embedding')
            ->select('embedding_model', 'embedding_dimensions', 'embedder_version')
            ->distinct()
            ->get();

        if ($triples->count() > 1) {
            $failures[] = sprintf(
                'the corpus holds %d different (model, dimensions, version) triples: %s',
                $triples->count(),
                $triples->map(fn (BookChunk $row): string => sprintf(
                    '%s/%d/v%d', $row->embedding_model, $row->embedding_dimensions, $row->embedder_version,
                ))->implode(', '),
            );
        }

        $triple = $triples->first();

        if ($triple !== null && ($triple->embedding_model !== $model
            || (int) $triple->embedding_dimensions !== $dimensions
            || (int) $triple->embedder_version !== self::VERSION)) {
            $failures[] = sprintf(
                'the corpus was embedded by %s/%d/v%d but configuration asks for %s/%d/v%d',
                $triple->embedding_model, $triple->embedding_dimensions, $triple->embedder_version,
                $model, $dimensions, self::VERSION,
            );
        }

        $misSized = (int) DB::scalar(
            'select count(*) from book_chunks where embedding is not null and vector_dims(embedding) != embedding_dimensions'
        );

        if ($misSized > 0) {
            $failures[] = "{$misSized} chunk(s) store a vector that is not the width they claim";
        }

        // A zero vector has no direction, so cosine distance against it is
        // undefined and pgvector returns NaN. One of these poisons the ordering
        // of every query that reaches it.
        $zeroNorm = (int) DB::scalar(
            'select count(*) from book_chunks where embedding is not null and vector_norm(embedding) = 0'
        );

        if ($zeroNorm > 0) {
            $failures[] = "{$zeroNorm} chunk(s) store a zero-norm vector";
        }

        // The text moved after the vector was made, so the vector describes a
        // passage that no longer exists. books:renormalize and books:chunk both
        // do this without touching any version number.
        $drifted = BookChunk::query()
            ->whereNotNull('embedding')
            ->whereColumn('embedded_at', '<', 'updated_at')
            ->count();

        if ($drifted > 0) {
            $failures[] = "{$drifted} chunk(s) were modified after they were embedded";
        }

        return $failures;
    }

    public function provider(): string
    {
        return $this->batch->provider();
    }

    public function model(): string
    {
        return $this->batch->model();
    }

    public function dimensions(): int
    {
        return $this->batch->dimensions();
    }
}
