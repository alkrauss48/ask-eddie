<?php

namespace App\Http\Controllers;

use App\Ai\Bar\Bartenders;
use App\Services\Retrieval\HybridRetriever;
use App\Services\Retrieval\RetrievedChunk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * What the search actually found, with no bartender and no bill attached.
 *
 * Every other address behind bar.key eventually calls an agent, and AnswerStream
 * is the one place a tool's eight-key passage payload is allowed to reach a
 * sink. This route is the deliberate exception: it calls the retriever the
 * config row would hand an agent and returns exactly what it found, so search
 * quality can be checked without spending on a language model to narrate it.
 *
 * That is also why it is gated on config('app.debug') as well as bar.key, and
 * answers 404 rather than the bar's usual 401 when debug is off. A key rotates
 * and a key can leak; app.debug is read from the environment a deployment sets
 * once, so a production box left in debug mode is a mistake this route cannot
 * make worse, and a production box correctly out of debug mode never exposes
 * retrieval internals no matter what key a caller presents.
 *
 * The retriever is resolved from the same config('bar.bartenders.{key}.retriever')
 * row Bartenders and bar:ask already read, rather than a second mapping living
 * here -- the same discipline .ai/rules/config.md states for --sources: one row
 * decides the agent and the retriever together, so a corpus can never be paired
 * with the wrong bartender's key by accident.
 */
final class SearchController extends Controller
{
    public function __invoke(Request $request, Bartenders $bartenders): JsonResponse
    {
        abort_unless((bool) config('app.debug'), 404);

        $validated = $request->validate([
            'question' => ['required', 'string'],
            'bartender' => ['required', 'string', Rule::in($bartenders->keys())],
        ]);

        $profile = $bartenders->find((string) $validated['bartender']);

        /** @var HybridRetriever $retriever */
        $retriever = app((string) $profile['retriever']);

        $results = $retriever->retrieve((string) $validated['question']);

        return response()->json([
            'passages' => $this->payload($results),
        ]);
    }

    /**
     * @param  Collection<int, RetrievedChunk>  $results
     * @return list<array<string, mixed>>
     */
    private function payload(Collection $results): array
    {
        return $results
            ->map(fn (RetrievedChunk $result): array => $result->payload())
            ->all();
    }
}
