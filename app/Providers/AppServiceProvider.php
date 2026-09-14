<?php

namespace App\Providers;

use App\Ai\Tei\TeiRerankerProvider;
use App\Services\Retrieval\AiReranker;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Reranking is a mode, not a dependency.
         *
         * NullReranker is bound whenever the stage is turned off, and AiReranker
         * itself falls back to it when the service cannot be reached, so a
         * cross-encoder outage costs ordering quality rather than an exception
         * in the middle of answering a guest. The binding must not resolve the
         * provider eagerly to decide: AiManager::rerankingProvider() throws a
         * LogicException outright for a provider that is not a RerankingProvider,
         * which would turn a configuration typo into a crash at boot.
         */
        $this->app->bind(Reranker::class, fn (): Reranker => config('books.retrieval.rerank.enabled')
            ? new AiReranker
            : new NullReranker);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Text Embeddings Inference serves a cross-encoder over an endpoint the
         * package knows nothing about, so the driver is registered here rather
         * than shipped. AiManager extends MultipleInstanceManager, which checks
         * $customCreators before its own create*Driver methods, so this is the
         * supported extension point and not a fork.
         */
        Ai::extend('tei-rerank', fn ($app, array $config): TeiRerankerProvider => new TeiRerankerProvider(
            $config,
            $app->make(Dispatcher::class),
        ));
    }
}
