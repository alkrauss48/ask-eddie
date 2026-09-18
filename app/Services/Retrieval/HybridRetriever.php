<?php

namespace App\Services\Retrieval;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

/**
 * Hybrid retrieval over one corpus: a dense channel, a lexical channel, fused
 * and reranked.
 *
 * Both channels are needed, and the two verification queries in the README show
 * why. "a bitter gin drink with orange" shares no keyword with the recipe that
 * answers it, so only the vector finds it. "Blue Lady" is a proper noun that an
 * embedding will happily place next to every other blue drink in the corpus,
 * so only the tsvector pins it.
 *
 * The whole thing is four queries: one embed of the query string, one dense
 * select, one lexical select, one hydrate of the fused ids.
 *
 * This does not use SimilaritySearch::usingModel(). That helper is dense-only,
 * passes the query through as a *string* so the model that embeds it is decided
 * by invisible global configuration, offers no hook for `set hnsw.ef_search`,
 * and truncates to its own limit before any fusion could see both lists.
 * SimilaritySearch's public closure constructor is the package's own escape
 * hatch for exactly this, so the tools wrap this class rather than forking
 * anything.
 *
 * Which corpus is read is the only thing a subclass decides, and it decides it
 * by handing a CorpusProfile up rather than by overriding a method. Everything
 * below -- the array passed to whereVectorSimilarTo, is_indexable on both
 * channels, whereNotNull('embedding') on the lexical one so both draw from a
 * single universe, websearch_to_tsquery, ts_rank_cd(..., 32), the single-query
 * hydrate -- exists once, with no seam in it.
 */
abstract class HybridRetriever
{
    public const DENSE = 'dense';

    public const LEXICAL = 'lexical';

    public function __construct(
        protected readonly CorpusProfile $corpus,
        private readonly ReciprocalRankFusion $fusion,
        private readonly Reranker $reranker,
    ) {}

    /**
     * Retrieve the passages most likely to answer a question.
     *
     * @return Collection<int, RetrievedChunk>
     */
    public function retrieve(string $query, ?int $limit = null): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return new Collection;
        }

        $limit ??= (int) $this->corpus->get('retrieval.limit');

        $channels = [
            self::DENSE => $this->dense($query),
            self::LEXICAL => $this->lexical($query),
        ];

        $fused = $this->fusion->fuse(
            $channels,
            [
                self::DENSE => (float) $this->corpus->get('retrieval.weights.dense'),
                self::LEXICAL => (float) $this->corpus->get('retrieval.weights.lexical'),
            ],
            (int) $this->corpus->get('retrieval.rrf_k'),
        );

        if ($fused === []) {
            return new Collection;
        }

        $candidates = $this->hydrate($fused);

        return $this->reranker->rerank($query, $candidates, $limit);
    }

    /**
     * The dense channel: nearest neighbours of the query's own vector.
     *
     * The query is embedded here, explicitly, with the configured provider and
     * model -- not handed to whereVectorSimilarTo() as a string. That overload
     * auto-embeds through ai.default_for_embeddings with no model argument, so
     * a misconfigured default would embed queries with one model and the corpus
     * with another and never raise. Passing an array short-circuits it.
     *
     * @return list<int> chunk ids, nearest first
     */
    public function dense(string $query): array
    {
        $candidates = (int) $this->corpus->get('retrieval.dense_candidates');

        if ($this->corpus->usesApproximateIndex) {
            // HNSW visits ef_search nodes and returns what it finds among them,
            // so an ef_search below the candidate count silently returns a short
            // list -- which looks like a thin corpus rather than a mis-set knob.
            // "local" scopes it to this transaction-less statement's session use
            // only. A corpus with no approximate index emits nothing here, and
            // has no ef_search key to read.
            DB::statement('set local hnsw.ef_search = '.max(
                $candidates,
                (int) $this->corpus->get('retrieval.ef_search'),
            ));
        }

        return $this->corpus->model::query()
            ->where('is_indexable', true)
            ->whereVectorSimilarTo(
                'embedding',
                $this->embed($query),
                (float) $this->corpus->get('retrieval.min_similarity'),
            )
            ->limit($candidates)
            ->pluck('id')
            ->all();
    }

    /**
     * The lexical channel: full-text rank over the stored tsvector.
     *
     * websearch_to_tsquery rather than plainto_tsquery so a guest can quote
     * "blue lady" and get the phrase, and write -sweet to exclude a term. It
     * also never throws on malformed input, which plainly matters for a string
     * a stranger typed.
     *
     * ts_rank_cd's flag 32 divides the rank by itself plus one, normalizing for
     * length. Without it a 1,400-character prose chunk that mentions gin four
     * times outranks the 200-character recipe that is actually made of gin.
     *
     * The `embedding is not null` predicate makes both channels draw from an
     * identical universe: fusing a lexical list that can see unembedded chunks
     * with a dense list that cannot would let a chunk rank purely because the
     * other channel was structurally unable to consider it.
     *
     * @return list<int> chunk ids, best first
     */
    public function lexical(string $query): array
    {
        $config = (string) $this->corpus->get('retrieval.text_search_config');

        return $this->corpus->model::query()
            ->where('is_indexable', true)
            ->whereNotNull('embedding')
            ->whereRaw('search_vector @@ websearch_to_tsquery(?, ?)', [$config, $query])
            ->orderByRaw(
                'ts_rank_cd(search_vector, websearch_to_tsquery(?, ?), 32) desc, id asc',
                [$config, $query],
            )
            ->limit((int) $this->corpus->get('retrieval.lexical_candidates'))
            ->pluck('id')
            ->all();
    }

    /**
     * Embed one query string with the corpus's own provider and model.
     *
     * @return list<float>
     */
    public function embed(string $query): array
    {
        $response = Embeddings::for([$query])
            ->dimensions((int) $this->corpus->get('embedding.dimensions'))
            ->timeout((int) $this->corpus->get('embedding.timeout'))
            ->generate(
                (string) $this->corpus->get('embedding.provider'),
                (string) $this->corpus->get('embedding.model'),
            );

        return $response->embeddings[0];
    }

    /**
     * Load the fused ids in one query, in fused order.
     *
     * forRetrieval() keeps the 4 KB vector and the stored tsvector out of the
     * hydrated rows: neither is read in PHP, and the payload never shows them.
     *
     * @param  list<FusedChunk>  $fused
     * @return Collection<int, RetrievedChunk>
     */
    private function hydrate(array $fused): Collection
    {
        $ids = array_map(fn (FusedChunk $chunk): int => $chunk->id, $fused);

        $chunks = $this->corpus->model::query()
            ->forRetrieval()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return (new Collection($fused))
            ->map(fn (FusedChunk $entry): ?RetrievedChunk => $chunks->has($entry->id)
                ? RetrievedChunk::fromFused($chunks->get($entry->id), $entry)
                : null)
            ->filter()
            ->values();
    }
}
