<?php

namespace App\Services\House;

use App\Models\HouseChunk;
use App\Services\Embedding\BatchEmbedder;
use App\Services\Embedding\EmbeddingReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns house chunks into vectors, through the same server the books use.
 *
 * The same TEI container and the same bge-m3 at the same 1024 dimensions,
 * because corpus and query vectors must come from one implementation --
 * see .ai/rules/retrieval.md. What differs is only the retrieval knobs, which
 * live in config/house.php and are not this class's business.
 *
 * One difference from ChunkEmbedder is worth knowing. Books detects a passage
 * that moved after it was embedded as embedded_at < updated_at, which --verify
 * reports as a failure an operator has to act on. Here the hash of the embedded
 * string is stored instead, and a chunk whose render has moved is simply
 * *pending* -- so bumping HouseRenderer::VERSION costs one `house:import` and
 * one `house:embed` rather than a conversation about a red verifier.
 */
class HouseEmbedder
{
    /**
     * Bumped when the string handed to the model changes shape.
     *
     * Note that this is not the same lever as HouseRenderer::VERSION. That one
     * changes what a chunk *says*; this one changes how the string is assembled
     * from what a chunk says. Either makes the corpus pending, by a different
     * route.
     */
    public const VERSION = 1;

    private readonly BatchEmbedder $batch;

    /**
     * Constructed rather than injected, for the same reason ChunkEmbedder does
     * it: BatchEmbedder is corpus-scoped by name, and a type-hinted parameter
     * would let the container hand this the books corpus's knobs.
     */
    public function __construct()
    {
        $this->batch = new BatchEmbedder('house');
    }

    /**
     * Every chunk that needs a vector it does not have.
     *
     * The predicate is the definition of "pending" and is shared by the command,
     * the progress count and --verify, so those three cannot drift apart and
     * report different totals for the same corpus.
     *
     * The last clause is the house's own addition: a chunk whose render has
     * moved since it was embedded is pending rather than broken. "is distinct
     * from" rather than "!=" because the column is null on a chunk that has
     * never been embedded, and null != anything is null, which would quietly
     * drop every one of them from the set.
     *
     * @return Builder<HouseChunk>
     */
    public function pending(bool $force = false): Builder
    {
        $model = $this->model();
        $dimensions = $this->dimensions();

        return HouseChunk::query()
            ->where('is_indexable', true)
            ->when(! $force, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('embedding')
                ->orWhere('embedding_model', '!=', $model)
                ->orWhere('embedding_dimensions', '!=', $dimensions)
                ->orWhere('embedder_version', '<', self::VERSION)
                ->orWhereRaw('embedded_content_hash is distinct from content_hash')));
    }

    /**
     * Embed the whole house.
     *
     * One method rather than books' per-book loop, because there is nothing to
     * loop over: 207 chunks is a handful of batches and a few seconds of
     * wall clock even on emulated hardware.
     *
     * @param  (callable(int): void)|null  $progress  called with each batch's size
     */
    public function embed(bool $force = false, ?int $batchSize = null, ?callable $progress = null): EmbeddingReport
    {
        $batchSize = max(1, $batchSize ?? $this->batch->batchSize());

        $report = new EmbeddingReport(
            indexableCount: HouseChunk::query()->where('is_indexable', true)->count(),
            pendingCount: $this->pending($force)->count(),
        );

        $started = microtime(true);

        // Paged by id rather than by offset because every batch removes its own
        // rows from the pending set, which would make an offset skip them.
        // forRetrieval() keeps the 4 KB vector out of the hydrated rows.
        $this->pending($force)
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
     * @param  Collection<int, HouseChunk>  $chunks
     * @return int tokens reported by the provider
     */
    public function embedBatch(Collection $chunks): int
    {
        $inputs = $chunks->map(fn (HouseChunk $chunk): string => $chunk->embeddingText())->values()->all();

        $batch = $this->batch->embed($inputs);
        $now = now();

        DB::transaction(function () use ($chunks, $batch, $now): void {
            foreach ($chunks->values() as $index => $chunk) {
                HouseChunk::query()->whereKey($chunk->id)->update([
                    'embedding' => $batch->literal($index),
                    'embedding_model' => $batch->model,
                    'embedding_dimensions' => $batch->dimensions,
                    'embedder_version' => self::VERSION,
                    // The stored content_hash as it was at embed time, not a
                    // hash recomputed here. The pending predicate compares
                    // these two columns in SQL, so writing anything else would
                    // make them incomparable the moment they disagreed -- and a
                    // row that is permanently pending is as bad as one that is
                    // never pending. house:import --verify is what guarantees
                    // content_hash describes the row's own text.
                    'embedded_content_hash' => $chunk->content_hash,
                    'embedded_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return $batch->tokens;
    }

    public function isStale(): bool
    {
        return $this->pending()->exists();
    }

    /**
     * Every way the embedded house corpus can be wrong, asked as a query.
     *
     * The books invariants, scoped to this table, plus one that is not about
     * this table at all: that the two corpora are still embedded by the same
     * model. That one is first because it is the only failure here that cannot
     * be seen in any row -- every vector would be the right width, the right
     * count and perfectly self-consistent, and every query would be answered by
     * a model that never read this corpus.
     *
     * @return list<string> the failures, empty when the corpus is sound
     */
    public function verify(): array
    {
        $failures = [];
        $model = $this->model();
        $dimensions = $this->dimensions();

        if ($model !== (string) config('books.embedding.model')) {
            $failures[] = sprintf(
                'the house is configured to embed with %s but the books use %s; one server serves one model, so every house query would be embedded by a different model than the house corpus',
                $model,
                (string) config('books.embedding.model'),
            );
        }

        if ($dimensions !== (int) config('books.embedding.dimensions')) {
            $failures[] = sprintf(
                'the house embeds at %d dimensions but the books embed at %d',
                $dimensions,
                (int) config('books.embedding.dimensions'),
            );
        }

        $unembedded = HouseChunk::query()
            ->where('is_indexable', true)
            ->whereNull('embedding')
            ->count();

        if ($unembedded > 0) {
            $failures[] = "{$unembedded} indexable chunk(s) have no embedding";
        }

        // The mirror image, and the one people forget: a vector on a chunk that
        // was excluded means retrieval can return something nobody vouched for.
        $excluded = HouseChunk::query()
            ->where('is_indexable', false)
            ->whereNotNull('embedding')
            ->count();

        if ($excluded > 0) {
            $failures[] = "{$excluded} non-indexable chunk(s) carry an embedding";
        }

        $triples = HouseChunk::query()
            ->whereNotNull('embedding')
            ->select('embedding_model', 'embedding_dimensions', 'embedder_version')
            ->distinct()
            ->get();

        if ($triples->count() > 1) {
            $failures[] = sprintf(
                'the corpus holds %d different (model, dimensions, version) triples: %s',
                $triples->count(),
                $triples->map(fn (HouseChunk $row): string => sprintf(
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
            'select count(*) from house_chunks where embedding is not null and vector_dims(embedding) != embedding_dimensions'
        );

        if ($misSized > 0) {
            $failures[] = "{$misSized} chunk(s) store a vector that is not the width they claim";
        }

        // A zero vector has no direction, so cosine distance against it is
        // undefined and pgvector returns NaN. One of these poisons the ordering
        // of every query that reaches it.
        $zeroNorm = (int) DB::scalar(
            'select count(*) from house_chunks where embedding is not null and vector_norm(embedding) = 0'
        );

        if ($zeroNorm > 0) {
            $failures[] = "{$zeroNorm} chunk(s) store a zero-norm vector";
        }

        $drifted = HouseChunk::query()
            ->whereNotNull('embedding')
            ->whereRaw('embedded_content_hash is distinct from content_hash')
            ->count();

        if ($drifted > 0) {
            $failures[] = "{$drifted} chunk(s) were re-rendered after they were embedded";
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

    public function batchSize(): int
    {
        return $this->batch->batchSize();
    }
}
