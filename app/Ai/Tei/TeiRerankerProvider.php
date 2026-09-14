<?php

namespace App\Ai\Tei;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Providers\Concerns;
use Laravel\Ai\Providers\Provider;

/**
 * Text Embeddings Inference as a first-class reranking provider.
 *
 * laravel/ai ships reranking for Bedrock, Jina, Cohere and VoyageAI only, and
 * Collection::rerank() always dispatches through Reranking::of() -- its
 * `Closure $by` argument resolves a field to score, it is not a scorer. So a
 * local cross-encoder needs a provider, not a callback.
 *
 * It is a cheap thing to add: AiManager extends MultipleInstanceManager, which
 * resolves a config entry's driver through $customCreators first, so
 * Ai::extend('tei-rerank', ...) in AppServiceProvider registers this cleanly
 * with nothing forked. Because it is a real provider, Reranking::of(),
 * Reranking::fake() and Ai::fakeableRerankingProvider() all work unchanged --
 * the only difference from using Cohere is the name in configuration.
 *
 * Provider's own constructor takes a gateway first; this one does not, because
 * the gateway is resolved lazily below and useRerankingGateway() replaces it
 * when reranking is faked.
 */
class TeiRerankerProvider extends Provider implements RerankingProvider
{
    use Concerns\HasRerankingGateway;
    use Concerns\Reranks;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    /**
     * TEI needs no credentials; it is on the private network, not the internet.
     */
    #[\Override]
    public function providerCredentials(): array
    {
        return ['key' => $this->config['key'] ?? null];
    }

    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'BAAI/bge-reranker-v2-m3';
    }

    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= new TeiRerankingGateway;
    }
}
