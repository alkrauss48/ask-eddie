<?php

namespace App\Tools;

use App\Services\Retrieval\DrinkQuery;
use App\Services\Retrieval\DrinkSummary;
use App\Services\Retrieval\DrinkSurveyor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * The only way Eddie is allowed to count anything.
 *
 * SearchTheBooks answers "what is in this drink" with eight passages, which is
 * the wrong instrument for "which drink turns up most" -- eight passages cannot
 * support a claim about the shelf, and a model asked to make one from them will
 * answer from memory instead. This counts instead of retrieving.
 *
 * Each row is exactly DrinkSummary::payload(): six keys, name, books, mentions,
 * years, also_printed_as and citations. No id, no slug, no folded key, no
 * score. The reasoning is the same one .ai/rules/retrieval.md gives for the
 * eight-key passage payload: bookkeeping in the payload reads to the model as
 * content it may repeat, and a guest has no use for a canonical key.
 */
class SurveyTheBooks implements Tool
{
    public function __construct(private readonly DrinkSurveyor $surveyor) {}

    public function description(): Stringable|string
    {
        return 'Count and compare drinks across the whole shelf of bartending books, rather than '
            .'looking up one passage. Answers which drinks the most books print, which are rare, '
            .'which came first or last, and what a period was fond of. Returns each drink with the '
            .'number of books it appears in, the span of years, and citations you can name. '
            .'Use this for a question about the books as a whole; use the search tool for a recipe, '
            .'a technique or a story.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $coverage = $this->surveyor->coverage();

            // A tally that has never been run and a filter that matched nothing
            // are different facts, and they must not collapse into one sentence
            // -- otherwise Eddie reports an absence from the books when what
            // happened is that nobody counted them.
            if ($coverage->isEmpty()) {
                return 'The shelf has not been tallied yet. Say plainly that you cannot count across '
                    .'the books just now, and use the search tool instead. Do not guess a number.';
            }

            $results = $this->surveyor->survey($this->queryFrom($request));

            if ($results->isEmpty()) {
                return 'No drink on the shelf matches that. Say so rather than inventing a tally.';
            }

            return $coverage->sentence()."\n\n".$this->payload($results);
        } catch (Throwable $e) {
            // The reranker's precedent: a counting outage costs a capability,
            // never an exception in the middle of answering a guest.
            report($e);

            return 'The tally is unavailable just now. Say you cannot count across the books at the '
                .'moment, and use the search tool instead. Do not guess a number.';
        }
    }

    private function queryFrom(Request $request): DrinkQuery
    {
        $order = (string) $request->string('order', 'books');

        return new DrinkQuery(
            name: trim((string) $request->string('name')) ?: null,
            order: in_array($order, DrinkQuery::ORDERS, true) ? $order : 'books',
            limit: (int) ($request->integer('limit') ?: config('books.drinks.survey.limit')),
            fromYear: $request->integer('from_year') ?: null,
            toYear: $request->integer('to_year') ?: null,
            minBooks: $request->integer('min_books') ?: null,
        );
    }

    /**
     * @param  Collection<int, DrinkSummary>  $results
     */
    private function payload(Collection $results): string
    {
        return $results
            ->map(fn (DrinkSummary $summary): array => $summary->payload())
            ->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        // Nothing is required: "what keeps turning up in your books?" has to be
        // answerable with no arguments at all.
        return [
            'name' => $schema
                ->string()
                ->description('A single drink to look up by name, when the question is about one drink rather than the shelf as a whole.'),
            'order' => $schema
                ->string()
                ->enum(DrinkQuery::ORDERS)
                ->description('How to rank: "books" for what the most books print, which is the usual answer to "what comes up again and again"; "mentions" for sheer frequency; "earliest" or "latest" by the year a drink was first or last printed.'),
            'limit' => $schema
                ->integer()
                ->description('How many drinks to return. Ten is plenty for a conversation.'),
            'from_year' => $schema
                ->integer()
                ->description('Only count printings in books published in or after this year.'),
            'to_year' => $schema
                ->integer()
                ->description('Only count printings in books published in or before this year.'),
            'min_books' => $schema
                ->integer()
                ->description('Only drinks printed in at least this many different books.'),
        ];
    }
}
