<?php

namespace App\Ai\Tei;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\RerankingResponse;

/**
 * One HTTP call: TEI's /rerank endpoint.
 *
 * TEI's shape is its own rather than Cohere's or Jina's -- {query, texts} in,
 * a list of {index, score} out, already sorted by score descending. The model
 * is not named in the request because a TEI instance serves exactly one, which
 * is why books:doctor checks that the served model matches configuration: the
 * mismatch is otherwise invisible.
 */
class TeiRerankingGateway implements RerankingGateway
{
    use CreatesClient;
    use HandlesFailoverErrors;

    /**
     * @param  array<int, string>  $documents
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null
    ): RerankingResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->post('/rerank', [
                'query' => $query,
                'texts' => array_values($documents),
                // The caller already holds the documents and maps results back
                // by index, so echoing every passage back doubles the response
                // for nothing.
                'return_text' => false,
            ]),
        );

        $results = (new Collection($response->json()))
            // TEI returns its results sorted, but nothing in its contract says
            // so, and an unsorted response would silently become a ranking.
            ->sortByDesc(fn (array $result): float => (float) $result['score'])
            ->values()
            ->when(
                $limit !== null,
                fn (Collection $results): Collection => $results->take($limit),
            )
            ->map(fn (array $result): RankedDocument => new RankedDocument(
                index: (int) $result['index'],
                document: $documents[$result['index']] ?? '',
                score: (float) $result['score'],
            ))
            ->all();

        return new RerankingResponse($results, new Meta($provider->name(), $model));
    }

    private function client(RerankingProvider $provider, ?int $timeout = null): PendingRequest
    {
        $configuration = $provider->additionalConfiguration();
        $key = $provider->providerCredentials()['key'] ?? null;

        return $this->createClient(
            rtrim($configuration['url'] ?? 'http://tei-rerank:80', '/'),
            array_filter([
                'Content-Type' => 'application/json',
                'Authorization' => blank($key) ? null : 'Bearer '.$key,
            ]),
            $configuration['headers'] ?? [],
            $timeout ?? (int) config('books.retrieval.rerank.timeout', 30),
        );
    }
}
