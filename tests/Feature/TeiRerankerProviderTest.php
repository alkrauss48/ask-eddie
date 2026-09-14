<?php

use App\Ai\Tei\TeiRerankerProvider;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

/**
 * TEI's /rerank shape: index and score, no model, no documents echoed back.
 *
 * @param  array<int, array{index: int, score: float}>  $results
 */
function fakeTeiRerank(array $results): void
{
    Http::fake([
        '*/rerank' => Http::response($results),
    ]);
}

it('resolves the extended driver as a reranking provider', function (): void {
    $provider = Ai::rerankingProvider('tei-rerank');

    expect($provider)->toBeInstanceOf(TeiRerankerProvider::class)
        ->and($provider->name())->toBe('tei-rerank')
        ->and($provider->driver())->toBe('tei-rerank')
        ->and($provider->defaultRerankingModel())->toBe('BAAI/bge-reranker-v2-m3');
});

it('is the default reranking provider', function (): void {
    expect(config('ai.default_for_reranking'))->toBe('tei-rerank');
});

it('maps a tei response into ranked documents', function (): void {
    fakeTeiRerank([
        ['index' => 2, 'score' => 0.91],
        ['index' => 0, 'score' => 0.42],
        ['index' => 1, 'score' => 0.05],
    ]);

    $response = Reranking::of(['first', 'second', 'third'])->rerank('a gin drink', 'tei-rerank');

    expect($response->results)->toHaveCount(3)
        ->and($response->results[0])->toBeInstanceOf(RankedDocument::class)
        ->and($response->results[0]->index)->toBe(2)
        ->and($response->results[0]->document)->toBe('third')
        ->and($response->results[0]->score)->toBe(0.91)
        ->and($response->documents()->all())->toBe(['third', 'first', 'second']);
});

/**
 * TEI returns its results sorted, but nothing in its contract promises it, and
 * an unsorted response would silently become the ranking.
 */
it('sorts an unsorted response by score', function (): void {
    fakeTeiRerank([
        ['index' => 0, 'score' => 0.10],
        ['index' => 1, 'score' => 0.90],
    ]);

    $response = Reranking::of(['low', 'high'])->rerank('anything', 'tei-rerank');

    expect($response->documents()->all())->toBe(['high', 'low']);
});

it('honours a limit', function (): void {
    fakeTeiRerank([
        ['index' => 1, 'score' => 0.90],
        ['index' => 0, 'score' => 0.50],
        ['index' => 2, 'score' => 0.10],
    ]);

    $response = Reranking::of(['a', 'b', 'c'])->limit(2)->rerank('anything', 'tei-rerank');

    expect($response->results)->toHaveCount(2)
        ->and($response->documents()->all())->toBe(['b', 'a']);
});

it('posts the query and texts to the tei endpoint', function (): void {
    fakeTeiRerank([['index' => 0, 'score' => 1.0]]);

    Reranking::of(['a passage'])->rerank('a bitter gin drink', 'tei-rerank');

    Http::assertSent(function ($request): bool {
        return str_ends_with($request->url(), '/rerank')
            && $request['query'] === 'a bitter gin drink'
            && $request['texts'] === ['a passage']
            // The caller maps results back by index, so echoing every passage
            // back would double the response for nothing.
            && $request['return_text'] === false;
    });
});

it('names the provider and model on the response meta', function (): void {
    fakeTeiRerank([['index' => 0, 'score' => 1.0]]);

    $response = Reranking::of(['a passage'])->rerank('anything', 'tei-rerank');

    expect($response->meta->provider)->toBe('tei-rerank')
        ->and($response->meta->model)->toBe('BAAI/bge-reranker-v2-m3');
});

/**
 * Being a real registered provider rather than a callback is what makes the
 * package's own fake work, which is what lets every other test avoid TEI.
 */
it('can be faked like any shipped provider', function (): void {
    Reranking::fake([[
        new RankedDocument(1, 'second', 0.99),
        new RankedDocument(0, 'first', 0.01),
    ]]);

    $response = Reranking::of(['first', 'second'])->rerank('anything', 'tei-rerank');

    expect($response->documents()->all())->toBe(['second', 'first']);
    Reranking::assertReranked(fn ($prompt): bool => $prompt->query === 'anything');
});
