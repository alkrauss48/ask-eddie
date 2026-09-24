<?php

namespace App\Tools;

use App\Services\Retrieval\CocktailSummary;
use App\Services\Retrieval\MenuBrowser;
use App\Services\Retrieval\MenuQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * The only way Sasha is allowed to learn which drinks the house pours.
 *
 * SearchTheHouse answers "what is this drink" with six passages, which is the
 * wrong instrument for "I don't like whiskey, what's good?" -- that is a
 * negation, and negation is the thing dense retrieval is worst at. An embedding
 * of "not whiskey" sits among the whiskey drinks, so the vector comes back with
 * a Sazerac and a Manhattan and a model handed those will name one. This filters
 * instead of retrieving, and every row it returns is a drink that genuinely
 * carries the facets asked for.
 *
 * Each row is exactly CocktailSummary::payload(): nine keys, name,
 * description, build, bottles, served, tags, on, notes and url. No id, no slug, no
 * content hash, no cost per ounce. The reasoning is the one
 * .ai/rules/retrieval.md gives for the eight-key passage payload and the
 * six-key survey payload: bookkeeping in a payload reads to the model as
 * content it may repeat, and a guest has no use for a slug.
 *
 * Three distinct returns, for the reason SurveyTheBooks has three: an empty
 * house, a filter nothing matched and an outage are different facts, and a bare
 * "[]" reads to a model as "the house pours nothing like that", which is a
 * false claim about the menus.
 */
class BrowseTheMenus implements Tool
{
    public function __construct(private readonly MenuBrowser $browser) {}

    public function description(): Stringable|string
    {
        return 'Browse the drinks the house actually pours, filtered exactly: by base spirit, '
            .'flavour, style, origin, technique, temperature, strength, prep time, an ingredient, '
            .'or a menu or flight. Every parameter is optional, so it answers "what\'s good?" with '
            .'no arguments. Use the "without" parameters whenever a guest rules something out -- '
            .'"nothing with whiskey" is answered exactly here and only guessed at by a search. '
            .'Every cocktail you name to a guest must have come back from this tool or the house '
            .'search.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $query = $this->queryFrom($request);

            // An empty catalog and a filter that matched nothing must not
            // collapse into one sentence, or Sasha reports an absence from the
            // menus when what happened is that nobody imported them.
            if (! $this->browser->poursAnything()) {
                return 'The house menus have not been loaded yet. Say plainly that you cannot '
                    .'check what is being poured just now, and do not name a drink.';
            }

            $unrecognised = $this->browser->unrecognised($query);
            $interpreted = $this->interpretation($this->browser->interpreted($query));
            $results = $this->browser->browse($query);

            if ($results->isEmpty()) {
                return $this->nothingMatched($unrecognised).$interpreted;
            }

            return $this->preamble($query, $results, $unrecognised).$interpreted."\n\n".$this->payload($results);
        } catch (Throwable $exception) {
            // The reranker's precedent, and SurveyTheBooks's: a catalog outage
            // costs a capability, never an exception mid-answer.
            report($exception);

            return 'The menus are unavailable just now. Say you cannot check what the house is '
                .'pouring at the moment, and do not name a drink you have not confirmed.';
        }
    }

    private function queryFrom(Request $request): MenuQuery
    {
        return new MenuQuery(
            baseSpirits: $this->strings($request, 'base_spirit'),
            withoutBaseSpirits: $this->strings($request, 'without_base_spirit'),
            flavors: $this->strings($request, 'flavor'),
            styles: $this->strings($request, 'style'),
            origins: $this->strings($request, 'origin'),
            withIngredients: $this->strings($request, 'with_ingredient'),
            withoutIngredients: $this->strings($request, 'without_ingredient'),
            alcoholLevel: $this->string($request, 'alcohol_level'),
            technique: $this->string($request, 'technique'),
            temperature: $this->string($request, 'temperature'),
            prepTime: $this->string($request, 'prep_time'),
            menu: $this->string($request, 'menu'),
            limit: (int) ($request->integer('limit') ?: config('bar.menus.limit')),
        );
    }

    /**
     * What the answer is a claim about.
     *
     * The count travels with the rows for the reason DrinkCoverage's sentence
     * does: "here are eight" reads as the whole list unless something says
     * there were thirty-one, and a bartender who says "that's everything I've
     * got" when it is not has told a guest something false about the bar.
     *
     * @param  Collection<int, CocktailSummary>  $results
     * @param  list<string>  $unrecognised
     */
    private function preamble(MenuQuery $query, Collection $results, array $unrecognised): string
    {
        $total = $this->browser->matching($query);

        $sentence = $query->isUnfiltered()
            ? "The house pours {$total} drinks; here are ".$results->count().' of them.'
            : "{$total} of the house's drinks fit that, and here are ".$results->count().' of them.';

        if ($total > $results->count()) {
            $sentence .= ' Say that there are more if the guest wants to keep looking.';
        }

        return $sentence.$this->caveat($unrecognised);
    }

    /**
     * @param  list<string>  $unrecognised
     */
    private function nothingMatched(array $unrecognised): string
    {
        return 'Nothing the house pours fits that. Say so plainly, and if you want to describe '
            .'what you would build instead, be clear that it is not on the menu.'
            .$this->caveat($unrecognised);
    }

    /**
     * Name the words the house does not know.
     *
     * Without this, asking for scotch returns an empty list and Sasha tells a
     * guest the house has nothing like it -- a true sentence about the wrong
     * thing, since there is no Scotch facet at all and the house does pour
     * whiskey. The filter cannot distinguish "no such drink" from "no such
     * word", so it says which one it was.
     *
     * @param  list<string>  $unrecognised
     */
    private function caveat(array $unrecognised): string
    {
        if ($unrecognised === []) {
            return '';
        }

        return ' The house has nothing filed under '.$this->list($unrecognised)
            .', so that part of the question was not applied — say so rather than treating it as '
            .'an answer, and try a word the menus use.';
    }

    /**
     * Say which bottle a partial ingredient word was read as.
     *
     * A guest asking about "curaçao" is answered with the drinks that pour Dry
     * Curaçao, and Sasha should name that bottle rather than a curaçao the house
     * does not stock. A broad word -- "orange", "bitters" -- is spelled out as
     * every entry it caught, so the reading is hers to correct.
     *
     * @param  array<string, list<string>>  $interpreted
     */
    private function interpretation(array $interpreted): string
    {
        $sentences = [];

        foreach ($interpreted as $word => $titles) {
            $sentences[] = ' Read "'.$word.'" as '.$this->list($titles, quoted: false).'.';
        }

        return implode('', $sentences);
    }

    /**
     * @param  list<string>  $values
     */
    private function list(array $values, bool $quoted = true): string
    {
        $items = $quoted ? array_map(fn (string $value): string => '"'.$value.'"', $values) : $values;

        return count($items) === 1
            ? $items[0]
            : implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }

    /**
     * @param  Collection<int, CocktailSummary>  $results
     */
    private function payload(Collection $results): string
    {
        return $results
            ->map(fn (CocktailSummary $summary): array => $summary->payload())
            ->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<string>
     */
    private function strings(Request $request, string $key): array
    {
        $values = $request->array($key);

        return array_values(array_filter(
            array_map(
                fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
                $values,
            ),
            fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * One scalar facet, in whichever shape the model sent it.
     *
     * Five of these parameters are scalars sitting beside six that are arrays,
     * and a model that has just filled in without_base_spirit: ["Whiskey"] will
     * sometimes send temperature: ["Frozen"] to match. Casting that array to a
     * string raises "Array to string conversion", which HandleExceptions
     * promotes to an ErrorException -- so the tool's catch-all fires and a
     * guest is told the menus are unavailable when nothing is wrong with them.
     * The silent half is worse: without that promotion the value becomes the
     * literal "Array", matches no facet, and the answer becomes "nothing the
     * house pours fits that" -- a false claim wearing a true one's clothes,
     * which is the failure the three distinct returns exist to prevent.
     *
     * Normalising through strings() makes the two shapes the same question.
     * Request::array() casts a scalar to a one-element array, so this costs
     * nothing for a model that sent the declared shape.
     */
    private function string(Request $request, string $key): ?string
    {
        return $this->strings($request, $key)[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        // Nothing is required: "what's good tonight?" has to be answerable with
        // no arguments at all.
        return [
            'base_spirit' => $schema
                ->array()
                ->items($schema->string())
                ->description('Only drinks built on one of these: Rum, Whiskey, Gin, Brandy, Tequila, Mezcal, Vodka, Beer, Wine.'),
            'without_base_spirit' => $schema
                ->array()
                ->items($schema->string())
                ->description('Rule out a base spirit entirely. This is the parameter for "I don\'t like whiskey" — use it rather than searching, because a search cannot exclude anything.'),
            'flavor' => $schema
                ->array()
                ->items($schema->string())
                ->description('Bubbly, Spiced, Fruity, Creamy, Herbal, Citrus, Bitter.'),
            'style' => $schema
                ->array()
                ->items($schema->string())
                ->description('Tiki, Sour, Spirit Forward, Highball.'),
            'origin' => $schema
                ->array()
                ->items($schema->string())
                ->description('Original for the house\'s own, Classic, Modern, or Folk.'),
            'with_ingredient' => $schema
                ->array()
                ->items($schema->string())
                ->description('Only drinks pouring this ingredient, by name or by the bottle: "Smith and Cross", "Jamaican Rum", "lime-juice".'),
            'without_ingredient' => $schema
                ->array()
                ->items($schema->string())
                ->description('Rule out an ingredient — an allergy, or something a guest cannot stand.'),
            'alcohol_level' => $schema
                ->string()
                ->description('Higher Alcohol or Lower Alcohol, for a guest who wants something stiff or something long.'),
            'technique' => $schema
                ->string()
                ->description('Shaken, Stirred, Built in Glass, Swizzled, Flash Blended, Blended, Batched.'),
            'temperature' => $schema
                ->string()
                ->description('Frozen or Hot.'),
            'prep_time' => $schema
                ->string()
                ->description('Simple Prep or Complex Prep, for how much work a drink is.'),
            'menu' => $schema
                ->string()
                ->description('Only drinks on a particular menu or flight, by its name or slug.'),
            'limit' => $schema
                ->integer()
                ->description('How many drinks to return. Eight is plenty for a conversation.'),
        ];
    }
}
