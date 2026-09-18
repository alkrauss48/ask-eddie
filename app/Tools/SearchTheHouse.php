<?php

namespace App\Tools;

use App\Services\Retrieval\HouseRetriever;
use App\Services\Retrieval\RetrievedChunk;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * How Sasha reads the house's own pages.
 *
 * SearchTheBooks's counterpart, over a corpus three orders of magnitude
 * smaller: the 138 cocktails, 29 syrup and liqueur recipes, 27 bartender bios,
 * 3 menus and 10 flights the house actually publishes. It answers "what is this
 * drink", "how do we make that syrup", "who is that named for" and "what's the
 * shape of that flight" -- anything where the question is about a record's
 * content rather than about which drinks qualify.
 *
 * Each element is exactly HouseChunk::toArray(): five keys, kind, title, text,
 * url and citation. No score, no rank, no id, and no keywords -- that column is
 * an index artefact whose content already reaches the model as prose inside the
 * text, and handing the flattened tag vocabulary over as well would put a
 * comma-separated dump in a bartender's context for her to read back out.
 * SearchTheHouseToolTest asserts the key count, not a subset.
 *
 * It fails closed the way SurveyTheBooks does. The house corpus needs TEI to
 * embed a query, and a container that is down must cost Sasha a capability
 * rather than land an exception in the middle of a conversation.
 */
class SearchTheHouse implements Tool
{
    public function __construct(private readonly HouseRetriever $retriever) {}

    public function description(): Stringable|string
    {
        return 'Read the house\'s own pages: a cocktail and how it is built, a syrup or liqueur '
            .'recipe, a bartender the house names a drink for, a menu, or one of the flights. '
            .'Returns each page with a link you can give the guest. Use this when the question is '
            .'about a particular drink or recipe; use the menu tool when the question is which '
            .'drink to pour.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request->string('query'));

        if ($query === '') {
            return 'No search query was given.';
        }

        try {
            $results = $this->retriever->retrieve($query);
        } catch (Throwable $exception) {
            report($exception);

            return 'The house pages are unavailable just now. Say plainly that you cannot look '
                .'them up at the moment, and do not name a drink you have not confirmed.';
        }

        if ($results->isEmpty()) {
            return 'Nothing on the house pages matches that. Say so rather than inventing a drink '
                .'the house does not pour.';
        }

        return "Pages from the house, most relevant first:\n\n".$this->payload($results);
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
                ->description('What to look for: a drink name, an ingredient, a syrup, a bartender, a menu, a flight, or a description of the kind of drink wanted.')
                ->required(),
        ];
    }
}
