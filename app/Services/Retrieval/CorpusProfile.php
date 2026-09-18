<?php

namespace App\Services\Retrieval;

use App\Models\BookChunk;
use App\Models\HouseChunk;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything HybridRetriever needs to know about which corpus it is reading.
 *
 * A value object rather than an abstract base class with hooks, and that shape
 * is the point. The books corpus and the house corpus differ only in *data* --
 * which model, which configuration prefix, whether an approximate index is
 * present -- so the retriever's query bodies can exist exactly once, with no
 * seam a subclass could open. An abstract base with a `protected function
 * denseQuery(): Builder` hook would make `where('is_indexable', true)` and
 * `whereNotNull('embedding')` overridable, and those two predicates are the
 * invariants the whole design is protecting.
 */
readonly class CorpusProfile
{
    /**
     * @param  string  $name  the corpus's own name, for a log line or an error
     * @param  class-string<Model&RetrievablePassage>  $model  the chunk table's model
     * @param  string  $config  the configuration file its knobs live in
     * @param  bool  $usesApproximateIndex  whether an HNSW index needs ef_search set per query
     */
    public function __construct(
        public string $name,
        public string $model,
        public string $config,
        public bool $usesApproximateIndex,
    ) {}

    /**
     * The books corpus: 24,926 OCR'd passages behind an HNSW index.
     */
    public static function books(): self
    {
        return new self(
            name: 'books',
            model: BookChunk::class,
            config: 'books',
            usesApproximateIndex: true,
        );
    }

    /**
     * The house corpus: 207 rendered records, scanned exactly.
     *
     * usesApproximateIndex is false because house_chunks carries no HNSW index,
     * which is a decision rather than an omission -- at this size an exact scan
     * is sub-millisecond with 100% recall, and pgvector post-filters, so an
     * is_indexable predicate over an approximate scan can quietly return a
     * short list. config('house') has no ef_search key to read, either.
     */
    public static function house(): self
    {
        return new self(
            name: 'house',
            model: HouseChunk::class,
            config: 'house',
            usesApproximateIndex: false,
        );
    }

    /**
     * One knob, read from this corpus's own configuration file.
     */
    public function get(string $key): mixed
    {
        return config("{$this->config}.{$key}");
    }
}
