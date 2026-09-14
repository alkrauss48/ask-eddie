<?php

namespace App\Tools;

use App\Services\Retrieval\ChunkRetriever;
use App\Services\Retrieval\RetrievedChunk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The only way Eddie is allowed to learn anything about a drink.
 *
 * This is laravel/ai's SimilaritySearch in shape but not in substance: that
 * helper is dense-only and decides the query's embedding model from global
 * configuration, whereas retrieval here is hybrid and pins the model
 * explicitly. What it keeps is the contract -- a JSON list of passages, handed
 * to the model verbatim.
 *
 * Each element is exactly BookChunk::toArray(): eight keys, book_title, author,
 * year, section_title, heading, pages, citation and text. No score, no rank, no
 * distance, no id. Retrieval metadata in the payload would read to the model as
 * content it may repeat, and "relevance 0.87" in a bartender's answer is both
 * meaningless to a guest and a break in character. SearchTheBooksToolTest
 * asserts the key count, not a subset.
 */
class SearchTheBooks implements Tool
{
    public function __construct(private readonly ChunkRetriever $retriever) {}

    public function description(): Stringable|string
    {
        return 'Search the bartending books for passages about a drink, an ingredient, a technique or a period. '
            .'Returns passages with the book, author, year and page they came from. '
            .'Use this before answering any question about a drink or its history, and cite what it returns.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request->string('query'));

        if ($query === '') {
            return 'No search query was given.';
        }

        $results = $this->retriever->retrieve($query);

        if ($results->isEmpty()) {
            return 'No passages in the books match that. Say so rather than inventing one.';
        }

        return "Passages from the books, most relevant first:\n\n".$this->payload($results);
    }

    /**
     * @param  Collection<int, RetrievedChunk>  $results
     */
    private function payload(Collection $results): string
    {
        return $results
            ->map(fn (RetrievedChunk $result): array => $result->payload())
            ->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('What to look for: a drink name, an ingredient, a technique, a bar, or a description of the kind of drink wanted.')
                ->required(),
        ];
    }
}
