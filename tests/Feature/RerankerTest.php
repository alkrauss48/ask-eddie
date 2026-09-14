<?php

use App\Models\Book;
use App\Models\BookChunk;
use App\Services\Retrieval\AiReranker;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use App\Services\Retrieval\RetrievedChunk;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\RankedDocument;

/**
 * Candidates in a known fused order, ids ascending.
 *
 * @return Collection<int, RetrievedChunk>
 */
function fusedCandidates(int $count = 5): Collection
{
    $book = Book::factory()->create(['title' => 'A Test Book', 'year' => 1900]);

    return BookChunk::factory()->count($count)->for($book)->embedded(unitVector(1))->create()
        ->values()
        ->map(fn (BookChunk $chunk, int $index): RetrievedChunk => new RetrievedChunk(
            $chunk,
            // Descending, so the fused order is the creation order.
            score: 1.0 - ($index / 100),
            ranks: ['dense' => $index + 1],
        ));
}

it('binds the null reranker when the stage is disabled', function (): void {
    config(['books.retrieval.rerank.enabled' => false]);

    expect(app(Reranker::class))->toBeInstanceOf(NullReranker::class);
});

it('binds the ai reranker when the stage is enabled', function (): void {
    config(['books.retrieval.rerank.enabled' => true]);

    expect(app(Reranker::class))->toBeInstanceOf(AiReranker::class);
});

it('preserves the fused order when reranking is disabled', function (): void {
    $candidates = fusedCandidates(5);

    $result = (new NullReranker)->rerank('anything', $candidates, 3);

    expect($result)->toHaveCount(3)
        ->and($result->map(fn (RetrievedChunk $r): int => $r->chunk->id)->all())
        ->toBe($candidates->take(3)->map(fn (RetrievedChunk $r): int => $r->chunk->id)->all())
        ->and($result[0]->rerankScore)->toBeNull();
});

it('reorders the candidates by the cross-encoder score', function (): void {
    $candidates = fusedCandidates(3);

    Http::fake(['*/rerank' => Http::response([
        ['index' => 2, 'score' => 0.95],
        ['index' => 0, 'score' => 0.40],
        ['index' => 1, 'score' => 0.11],
    ])]);

    $result = (new AiReranker)->rerank('a gin drink', $candidates, 3);

    expect($result->map(fn (RetrievedChunk $r): int => $r->chunk->id)->all())->toBe([
        $candidates[2]->chunk->id,
        $candidates[0]->chunk->id,
        $candidates[1]->chunk->id,
    ])
        ->and($result[0]->rerankScore)->toBe(0.95)
        // The fused score survives alongside it, so --sources can show both.
        ->and($result[0]->score)->toBe($candidates[2]->score);
});

it('caps the candidates it sends at the configured number', function (): void {
    config(['books.retrieval.rerank.candidates' => 2]);
    $candidates = fusedCandidates(5);

    Http::fake(['*/rerank' => Http::response([
        ['index' => 0, 'score' => 0.9],
        ['index' => 1, 'score' => 0.8],
    ])]);

    (new AiReranker)->rerank('anything', $candidates, 2);

    Http::assertSent(fn ($request): bool => count($request['texts']) === 2);
});

/**
 * The cross-encoder must score the same rendering of a passage the vector was
 * built from, or the two stages are reading different documents.
 */
it('sends the provenance-prefixed string rather than the bare text', function (): void {
    $candidates = fusedCandidates(1);

    Http::fake(['*/rerank' => Http::response([['index' => 0, 'score' => 0.9]])]);

    (new AiReranker)->rerank('anything', $candidates, 1);

    Http::assertSent(fn ($request): bool => str_starts_with($request['texts'][0], 'A Test Book (1900)'));
});

/**
 * The fail-closed path, and the one people get wrong. A cross-encoder outage
 * must cost ordering quality, never an exception in the middle of an answer --
 * hybrid plus RRF is most of the quality anyway.
 */
it('falls back to the fused order when the service is unreachable', function (): void {
    $candidates = fusedCandidates(4);

    Http::fake(fn (): never => throw new ConnectionException('Connection refused'));

    $result = (new AiReranker)->rerank('anything', $candidates, 2);

    expect($result)->toHaveCount(2)
        ->and($result->map(fn (RetrievedChunk $r): int => $r->chunk->id)->all())
        ->toBe($candidates->take(2)->map(fn (RetrievedChunk $r): int => $r->chunk->id)->all())
        ->and($result[0]->rerankScore)->toBeNull();
});

it('falls back when the service answers with an error', function (): void {
    $candidates = fusedCandidates(3);

    Http::fake(['*/rerank' => Http::response(['error' => 'model not loaded'], 500)]);

    $result = (new AiReranker)->rerank('anything', $candidates, 2);

    expect($result)->toHaveCount(2)
        ->and($result[0]->chunk->id)->toBe($candidates[0]->chunk->id);
});

/**
 * A misconfigured provider name resolves to something that is not a
 * RerankingProvider, and AiManager::rerankingProvider() throws a LogicException
 * outright for that. It must land in the same fallback as an outage.
 */
it('falls back when the configured provider cannot rerank', function (): void {
    config(['books.retrieval.rerank.provider' => 'openai']);
    $candidates = fusedCandidates(3);

    $result = (new AiReranker)->rerank('anything', $candidates, 2);

    expect($result)->toHaveCount(2)
        ->and($result[0]->chunk->id)->toBe($candidates[0]->chunk->id);
});

it('reranks nothing into nothing', function (): void {
    Http::fake();

    expect((new AiReranker)->rerank('anything', new Collection, 8))->toBeEmpty();
    Http::assertNothingSent();
});

/**
 * A response naming an index the request never sent must not become a null
 * entry in the passages handed to the model.
 */
it('drops a result whose index is out of range', function (): void {
    $candidates = fusedCandidates(2);

    Http::fake(['*/rerank' => Http::response([
        ['index' => 0, 'score' => 0.9],
        ['index' => 99, 'score' => 0.8],
    ])]);

    $result = (new AiReranker)->rerank('anything', $candidates, 8);

    expect($result)->toHaveCount(1)
        ->and($result[0]->chunk->id)->toBe($candidates[0]->chunk->id);
});

it('takes no more than the requested limit', function (): void {
    $candidates = fusedCandidates(5);

    Http::fake(['*/rerank' => Http::response(
        collect(range(0, 4))->map(fn (int $i): array => ['index' => $i, 'score' => 1.0 - $i / 10])->all()
    )]);

    expect((new AiReranker)->rerank('anything', $candidates, 2))->toHaveCount(2);
});

/**
 * RankedDocument is the package's own shape, so an application-level seam that
 * could not consume it would mean the provider was the wrong abstraction.
 */
it('speaks the packages own ranked document shape', function (): void {
    expect(new RankedDocument(0, 'a passage', 0.5))
        ->toBeInstanceOf(RankedDocument::class);
});
