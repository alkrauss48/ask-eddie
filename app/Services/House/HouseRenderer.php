<?php

namespace App\Services\House;

use App\Enums\HouseSourceType;
use App\Models\HouseBartender;
use App\Models\HouseCocktail;
use App\Models\HouseCocktailIngredient;
use App\Models\HouseCollection;
use App\Models\HouseRecipe;
use App\Services\Books\TokenEstimator;
use RuntimeException;

/**
 * Turns a house record into the one passage that represents it.
 *
 * Nothing here resembles the book chunker, and that is the point: there is no
 * stream to assemble, no boundary to find and no offset to resolve, because the
 * records arrive already separated and every one of them is far under the
 * ceiling. A cocktail is one chunk. What this class actually decides is *what a
 * drink sounds like when it is written down*, which is what the embedding
 * model sees and therefore what a guest's sentence has to match.
 *
 * The ceiling is asserted rather than trimmed, the same standing max_chars has
 * in ChunkPacker::assertWithinCeiling(). Everything fits today, so the throw
 * looks like dead code; it is not. A flight of twenty cocktails added next year
 * would otherwise render long, be embedded truncated, and retrieve on text
 * nobody chose -- silently.
 */
class HouseRenderer
{
    /**
     * Bumped when the rendered shape changes.
     *
     * A chunk below this version is re-rendered by the next `house:import`,
     * which changes its content_hash, which makes it pending for `house:embed`.
     * That chain is why bumping this is cheap: nothing has to be purged by hand.
     */
    public const VERSION = 2;

    public function __construct(private readonly TokenEstimator $tokens) {}

    public function renderCocktail(HouseCocktail $cocktail): RenderedChunk
    {
        $lines = [$cocktail->title];

        if ($cocktail->subtitle !== null) {
            $lines[] = $cocktail->subtitle;
        }

        if ($cocktail->description !== null) {
            $lines[] = '';
            $lines[] = $cocktail->description;
        }

        $build = $cocktail->cocktailIngredients
            ->map(fn (HouseCocktailIngredient $line): string => $line->line())
            ->filter(fn (string $line): bool => $line !== '')
            ->all();

        if ($build !== []) {
            $lines[] = '';
            $lines = [...$lines, ...$build];
        }

        // The build names bottles; this says which of them are the same style,
        // so "Jamaican Rum" is still in the text a guest's sentence is matched
        // against once the line reads "1oz Coruba".
        foreach ($cocktail->bottlesByStyle() as $style => $bottles) {
            $lines[] = $style.': '.implode(', ', $bottles).'.';
        }

        $lines[] = '';
        $lines[] = $this->buildSentence($cocktail);

        if ($cocktail->servings !== null) {
            $lines[] = "Serves {$cocktail->servings}.";
        }

        if ($cocktail->bartender !== null) {
            $lines[] = "Credited to {$cocktail->bartender->name}.";
        }

        $tags = $cocktail->tags->pluck('label')->all();

        if ($tags !== []) {
            $lines[] = implode(', ', $tags).'.';
        }

        $membership = $this->membershipSentence($cocktail);

        if ($membership !== null) {
            $lines[] = $membership;
        }

        if ($cocktail->notes !== null) {
            $lines[] = '';
            $lines[] = $cocktail->notes;
        }

        foreach ($this->variationLines($cocktail) as $line) {
            $lines[] = $line;
        }

        return $this->chunk(
            HouseSourceType::Cocktail,
            $cocktail,
            $cocktail->title,
            $cocktail->subtitle,
            $lines,
            $this->cocktailKeywords($cocktail),
        );
    }

    public function renderRecipe(HouseRecipe $recipe): RenderedChunk
    {
        $lines = [$recipe->name];

        if ($recipe->description !== null) {
            $lines[] = $recipe->description;
        }

        $ingredients = array_values(array_filter(
            array_map(strval(...), $recipe->ingredients ?? []),
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($ingredients !== []) {
            $lines[] = '';
            $lines = [...$lines, ...$ingredients];
        }

        if ($recipe->instructions !== null) {
            $lines[] = '';
            $lines[] = $recipe->instructions;
        }

        if ($recipe->notes !== null) {
            $lines[] = '';
            $lines[] = $recipe->notes;
        }

        $keywords = array_filter([$recipe->category, 'house-made']);

        // The catalog entries this recipe produces, so that a question about
        // the bottle finds the recipe that makes it.
        foreach ($recipe->ingredients()->get() as $ingredient) {
            $keywords[] = $ingredient->title;
            $keywords[] = $ingredient->group;
            $keywords[] = $ingredient->category_label;
        }

        return $this->chunk(
            HouseSourceType::Recipe,
            $recipe,
            $recipe->name,
            $recipe->category,
            $lines,
            $keywords,
        );
    }

    public function renderBartender(HouseBartender $bartender): RenderedChunk
    {
        $lines = [$bartender->name];

        $years = $bartender->years();

        if ($years !== null) {
            $lines[] = $years;
        }

        if ($bartender->description !== null) {
            $lines[] = '';
            $lines[] = $bartender->description;
        }

        $titles = $bartender->cocktails()->orderBy('title')->pluck('title')->all();

        if ($titles !== []) {
            $lines[] = '';
            $lines[] = 'On the menus: '.implode(', ', $titles).'.';
        }

        return $this->chunk(
            HouseSourceType::Bartender,
            $bartender,
            $bartender->name,
            $years,
            $lines,
            [$bartender->name, ...$titles],
        );
    }

    public function renderCollection(HouseCollection $collection): RenderedChunk
    {
        $lines = [$collection->title];

        if ($collection->subtitle !== null) {
            $lines[] = $collection->subtitle;
        }

        if ($collection->description !== null) {
            $lines[] = '';
            $lines[] = $collection->description;
        }

        $cocktails = $collection->cocktails;
        $titles = [];

        $sections = $cocktails
            ->reject(fn (HouseCocktail $cocktail): bool => (bool) $cocktail->pivot->is_featured)
            ->groupBy(fn (HouseCocktail $cocktail): string => (string) $cocktail->pivot->section_title);

        $lines[] = '';

        foreach ($sections as $title => $group) {
            $names = $group->pluck('title')->all();
            $titles = [...$titles, ...$names];

            $lines[] = $title === ''
                ? implode(', ', $names).'.'
                : $title.': '.implode(', ', $names).'.';
        }

        $featured = $cocktails
            ->filter(fn (HouseCocktail $cocktail): bool => (bool) $cocktail->pivot->is_featured)
            ->pluck('title')
            ->all();

        if ($featured !== []) {
            $titles = [...$titles, ...$featured];
            $lines[] = 'Featured: '.implode(', ', $featured).'.';
        }

        return $this->chunk(
            $collection->kind->sourceType(),
            $collection,
            $collection->title,
            $collection->subtitle,
            $lines,
            [$collection->title, $collection->kind->label(), ...$titles],
        );
    }

    /**
     * How the drink is built, as one sentence.
     *
     * The facets are spelled out in words rather than listed as key-value pairs
     * because this string is embedded: "shaken", "over crushed ice" and "in a
     * tiki mug" are things a guest says, and "ice: Crushed" is not.
     */
    private function buildSentence(HouseCocktail $cocktail): string
    {
        $parts = array_filter([
            $cocktail->method,
            $this->glassPhrase($cocktail->served_in),
            $this->icePhrase($cocktail->ice),
            $cocktail->has_straw ? 'with a straw' : null,
        ]);

        return $parts === [] ? '' : implode(', ', $parts).'.';
    }

    private function glassPhrase(?string $servedIn): ?string
    {
        return $servedIn === null || $servedIn === 'None'
            ? null
            : 'served in a '.$servedIn;
    }

    private function icePhrase(?string $ice): ?string
    {
        return match ($ice) {
            null => null,
            'None' => 'with no ice',
            'Hot' => 'served hot',
            'Crushed' => 'over crushed ice',
            'Large Cube' => 'over a large cube',
            'Small Cubes' => 'over small cubes',
            default => 'over '.mb_strtolower($ice).' ice',
        };
    }

    /**
     * Which menus and flights this drink is on.
     *
     * Stated in the body as well as recorded as a keyword, because "what's on
     * the summer menu" is a question about the drink as much as about the menu,
     * and the dense channel can only match what the passage says.
     */
    private function membershipSentence(HouseCocktail $cocktail): ?string
    {
        $names = $cocktail->collections
            ->map(fn (HouseCollection $collection): string => $collection->kind->label() === 'Menu'
                ? $collection->title
                : $collection->title.' flight')
            ->unique()
            ->values()
            ->all();

        return $names === [] ? null : 'On the '.implode(', ', $names).'.';
    }

    /**
     * @return list<string>
     */
    private function variationLines(HouseCocktail $cocktail): array
    {
        $lines = [];

        foreach ($cocktail->variations ?? [] as $variation) {
            $entries = array_values(array_filter(array_map(
                fn (array $entry): string => trim((string) ($entry['label'] ?? $entry['text'] ?? '')),
                $variation['ingredients'] ?? [],
            ), fn (string $entry): bool => $entry !== ''));

            $lines[] = '';
            $lines[] = trim(((string) ($variation['name'] ?? 'Variation')).': '.implode(' ', $entries));
        }

        return $lines;
    }

    /**
     * The structured vocabulary this drink should be findable by.
     *
     * This is where the 131 ingredients earn their keep without being chunked:
     * a cocktail naming "Smith and Cross" also carries "Jamaican Rum" and "Base
     * Spirits" here, so "something with a funky Jamaican rum" reaches the drink
     * rather than reaching nothing.
     *
     * @return list<string|null>
     */
    private function cocktailKeywords(HouseCocktail $cocktail): array
    {
        $keywords = [
            $cocktail->title,
            $cocktail->method,
            $cocktail->served_in,
            $cocktail->ice,
            $cocktail->bartender?->name,
        ];

        foreach ($cocktail->tags as $tag) {
            $keywords[] = $tag->label;
        }

        foreach ($cocktail->cocktailIngredients as $line) {
            $ingredient = $line->ingredient;

            if ($ingredient === null) {
                continue;
            }

            $keywords[] = $ingredient->title;
            $keywords[] = $ingredient->group;
            $keywords[] = $ingredient->category_label;
            $keywords[] = $ingredient->subcategory_label;
        }

        foreach ($cocktail->collections as $collection) {
            $keywords[] = $collection->title;
        }

        return $keywords;
    }

    /**
     * Assemble, measure and assert.
     *
     * @param  list<string>  $lines
     * @param  list<string|null>  $keywords
     */
    private function chunk(
        HouseSourceType $type,
        object $record,
        string $title,
        ?string $subtitle,
        array $lines,
        array $keywords,
    ): RenderedChunk {
        $text = $this->join($lines);
        $ceiling = (int) config('house.rendering.max_chars');

        if (mb_strlen($text) > $ceiling) {
            throw new RuntimeException(sprintf(
                'The %s [%s] renders to %d characters, over the %d-character ceiling; it would be truncated by the embedding model.',
                $type->value,
                $record->slug,
                mb_strlen($text),
                $ceiling,
            ));
        }

        return new RenderedChunk(
            type: $type,
            slug: (string) $record->slug,
            sourceId: (int) $record->id,
            title: $title,
            subtitle: $subtitle,
            text: $text,
            keywords: $this->normaliseKeywords($keywords),
            url: (string) $record->url,
        );
    }

    /**
     * @param  list<string>  $lines
     */
    private function join(array $lines): string
    {
        $text = implode("\n", array_map(fn (string $line): string => rtrim($line), $lines));

        // Blank lines are added unconditionally above so each block can be
        // written without knowing what precedes it; the collapse happens here,
        // once, rather than as a condition at every append.
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * @param  list<string|null>  $keywords
     * @return list<string>
     */
    private function normaliseKeywords(array $keywords): array
    {
        $cleaned = [];

        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);

            // "None" is how the site says a drink is served in nothing in
            // particular. Indexed, it would make every one of those drinks
            // match a guest who typed the word.
            if ($keyword === '' || $keyword === 'None' || $keyword === 'Default') {
                continue;
            }

            $cleaned[$keyword] = true;
        }

        return array_keys($cleaned);
    }

    /**
     * The measurements every chunk carries.
     *
     * @return array{char_count: int, word_count: int, token_estimate: int}
     */
    public function measure(RenderedChunk $chunk): array
    {
        return [
            'char_count' => mb_strlen($chunk->text),
            'word_count' => count(preg_split('/\s+/u', trim($chunk->text), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            // Of the embedded string rather than of the body, because the
            // provenance prefix spends part of the same context window.
            'token_estimate' => $this->tokens->estimate($chunk->embeddingText()),
        ];
    }
}
