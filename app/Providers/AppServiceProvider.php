<?php

namespace App\Providers;

use App\Ai\Bar\ConsultDesk;
use App\Ai\Tei\TeiRerankerProvider;
use App\Http\Middleware\VerifyBarKey;
use App\Services\Retrieval\AiReranker;
use App\Services\Retrieval\HouseRetriever;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
         * One desk, or no guard at all.
         *
         * The depth flag and the per-answer count are state, and state shared
         * between two tools that never see each other only works if they are
         * handed the same object. A fresh ConsultDesk per injection would let
         * Eddie's AskSasha and Sasha's AskEddie each believe nothing was open,
         * which is exactly the recursion the class exists to stop.
         */
        $this->app->singleton(ConsultDesk::class);

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
        $this->app->bind(Reranker::class, fn (): Reranker => $this->reranker('books'));

        /*
         * The house reranks on its own settings, or not at all.
         *
         * The binding above is global, and a HouseRetriever resolving it would
         * be handed a reranker reading config('books.retrieval.rerank.*') --
         * its enabled flag, its candidate ceiling, its model. On an Apple
         * Silicon dev machine BOOKS_RERANK_ENABLED is false, so the two corpora
         * would rerank together or not at all, which is wrong in both
         * directions and visible in neither.
         */
        $this->app->when(HouseRetriever::class)
            ->needs(Reranker::class)
            ->give(fn (): Reranker => $this->reranker('house'));
    }

    /**
     * The reranking mode one corpus is configured for.
     */
    private function reranker(string $corpus): Reranker
    {
        return config("{$corpus}.retrieval.rerank.enabled")
            ? new AiReranker(corpus: $corpus)
            : new NullReranker;
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

        /*
         * The cap on how often, not on how much. There is no RouteServiceProvider
         * in this application, so the named limiter is registered here instead.
         *
         * Keyed by the presented API key rather than counted globally: a global
         * counter would let one noisy caller exhaust the whole endpoint's
         * allowance and lock out everybody else holding a perfectly good key. A
         * caller VerifyBarKey would refuse anyway (no header, or the wrong one)
         * still needs a bucket to be thrown in, so it falls back to the request's
         * IP -- which only matters for the brief window before that request is
         * turned away, since an unauthorized request never reaches the model.
         */
        RateLimiter::for('bar-ask', fn (Request $request): Limit => Limit::perMinute(
            (int) config('bar.api.rate_limit'),
        )->by($request->header(VerifyBarKey::HEADER) ?: $request->ip()));
    }
}
