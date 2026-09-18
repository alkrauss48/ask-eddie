<?php

namespace App\Services\Embedding;

use Laravel\Ai\Embeddings;
use RuntimeException;

/**
 * One request to the embedding provider, with the guards that make it safe.
 *
 * Extracted from ChunkEmbedder when the house corpus arrived, rather than
 * transposed by hand into a second class. What lives here is the careful part:
 * the batch-count check, the per-vector width check, and caching turned off.
 * Two of those fail *invisibly* when they are missing -- a short batch attaches
 * every vector to its neighbour and produces a corpus that looks entirely fine
 * and retrieves the wrong passage for every query -- so a second copy of them
 * that drifted by one line would be a defect nobody could see from the outside.
 *
 * The corpus name picks which configuration block to read. The two blocks are
 * required to agree on provider, model and dimensions -- one TEI container
 * serves one model -- and `house:embed --verify` asserts that they do; what
 * they are allowed to differ on is batch size and timeout.
 */
class BatchEmbedder
{
    public function __construct(private readonly string $corpus = 'books') {}

    /**
     * Embed one batch, and refuse to return anything that cannot be trusted.
     *
     * @param  list<string>  $inputs
     */
    public function embed(array $inputs): EmbeddedBatch
    {
        $dimensions = $this->dimensions();

        $response = Embeddings::for($inputs)
            ->dimensions($dimensions)
            // Caching 24,926 vectors into the database store would write about
            // 100 MB for zero reuse: each input is embedded exactly once.
            ->cache(0)
            ->timeout($this->timeout())
            ->generate($this->provider(), $this->model());

        // With caching off, nothing in the package checks this. A short or long
        // batch would attach every vector to its neighbour, producing a corpus
        // that looks entirely fine and retrieves the wrong passage for every
        // query -- the one failure here that is invisible from the outside.
        if (count($response->embeddings) !== count($inputs)) {
            throw new RuntimeException(sprintf(
                'The embedding provider returned %d vector(s) for %d input(s); refusing to write a mis-indexed batch.',
                count($response->embeddings),
                count($inputs),
            ));
        }

        foreach ($response->embeddings as $index => $vector) {
            if (count($vector) !== $dimensions) {
                throw new RuntimeException(sprintf(
                    'Input %d was embedded at %d dimensions, but the column holds %d.',
                    $index,
                    count($vector),
                    $dimensions,
                ));
            }
        }

        return new EmbeddedBatch($response->embeddings, $response->tokens, $this->model(), $dimensions);
    }

    public function provider(): string
    {
        return (string) config("{$this->corpus}.embedding.provider");
    }

    public function model(): string
    {
        return (string) config("{$this->corpus}.embedding.model");
    }

    public function dimensions(): int
    {
        return (int) config("{$this->corpus}.embedding.dimensions");
    }

    public function timeout(): int
    {
        return (int) config("{$this->corpus}.embedding.timeout");
    }

    public function batchSize(): int
    {
        return max(1, (int) config("{$this->corpus}.embedding.batch_size"));
    }
}
