<?php

namespace App\Services\Retrieval;

use App\Models\HouseCocktail;
use App\Models\HouseCocktailIngredient;
use App\Models\HouseCollection;
use App\Models\HouseTag;
use App\Services\House\HouseUrl;

/**
 * One house cocktail as the language model is handed it.
 *
 * A value object rather than the model, following DrinkSummary rather than
 * BookChunk. BookChunk::toArray() had to *become* its payload because
 * laravel/ai's SimilaritySearch serializes the model out of the application's
 * reach; nothing does that here, so HouseCocktail::toArray() never reaches a
 * prompt at all and no future $hidden omission can leak a column into one --
 * including the raw exported record in "source", which is the whole site object
 * and would be a small essay of image URLs in every answer.
 *
 * Nine keys, asserted by count rather than by subset, which is the discipline
 * BookChunkCitationTest, SearchTheBooksToolTest and DrinkSummaryTest all hold.
 * No id, no slug, no content hash, no cost per ounce.
 *
 * "url" is the link, and it is in here because it is the thing that makes this
 * tool's claim checkable: Sasha may name a drink because the house pours it,
 * and a guest can open the page and see that it does.
 */
readonly class CocktailSummary
{
    /**
     * @param  list<string>  $build  the ingredient lines, in the order they are poured
     * @param  array<string, list<string>>  $bottles  the bottles in the build, grouped by style
     * @param  list<string>  $tags  the site's own facet labels
     * @param  list<string>  $collections  the menus and flights this drink is on
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $build,
        public array $bottles,
        public string $served,
        public array $tags,
        public array $collections,
        public ?string $notes,
        public string $url,
    ) {}

    /**
     * Built from a cocktail whose ingredients, tags and collections are loaded.
     *
     * The relations are asserted rather than lazily reached for: a browse of
     * eight drinks that N+1s its ingredient lines costs twenty-five queries to
     * answer "what's good", and MenuBrowser eager loads all three.
     */
    public static function fromCocktail(HouseCocktail $cocktail): self
    {
        return new self(
            name: $cocktail->title,
            description: $cocktail->description,
            build: $cocktail->cocktailIngredients
                ->map(fn (HouseCocktailIngredient $line): string => $line->line())
                ->values()
                ->all(),
            bottles: $cocktail->bottlesByStyle(),
            served: self::served($cocktail),
            // Sorted by category then label so that a repeated question gives a
            // repeated answer: a payload that reshuffles between askings reads
            // to a model as a menu that changed.
            tags: $cocktail->tags
                ->sortBy([['category_label', 'asc'], ['order', 'asc'], ['label', 'asc']])
                ->map(fn (HouseTag $tag): string => $tag->label)
                ->values()
                ->all(),
            collections: $cocktail->collections
                ->sortBy([['kind', 'asc'], ['position', 'asc'], ['title', 'asc']])
                ->map(fn (HouseCollection $collection): string => $collection->title)
                ->unique()
                ->values()
                ->all(),
            notes: $cocktail->notes,
            // The catalog stores the site's own path; a chunk stores the
            // absolute form because a citation is a link. This payload is a
            // citation too, so it gets the same treatment -- a guest handed
            // "/cocktails/mai-tai" cannot check anything.
            url: HouseUrl::absolute($cocktail->url),
        );
    }

    /**
     * How it is made and what it arrives in, as one line.
     *
     * Three nullable columns joined here rather than exposed as three keys,
     * because a bartender says "stirred, up in a Nick & Nora" and never reads
     * out a field list. Empty parts are dropped rather than rendered as null:
     * the 137th cocktail has no glassware on the site, and "served in null"
     * is a sentence a model will repeat.
     */
    private static function served(HouseCocktail $cocktail): string
    {
        $parts = array_filter([
            $cocktail->method,
            $cocktail->served_in === null ? null : 'in a '.$cocktail->served_in,
            $cocktail->ice === null || $cocktail->ice === 'None' ? null : $cocktail->ice.' ice',
            $cocktail->has_straw ? 'with a straw' : null,
            $cocktail->servings === null ? null : 'serves '.$cocktail->servings,
        ], fn (?string $part): bool => $part !== null && $part !== '');

        return implode(', ', $parts);
    }

    /**
     * Exactly what the language model is handed.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'build' => $this->build,
            'bottles' => $this->bottles,
            'served' => $this->served,
            'tags' => $this->tags,
            'on' => $this->collections,
            'notes' => $this->notes,
            'url' => $this->url,
        ];
    }
}
