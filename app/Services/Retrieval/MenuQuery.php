<?php

namespace App\Services\Retrieval;

/**
 * Which drinks a guest would and would not have.
 *
 * Every field is optional, because "what's good?" has to be answerable with no
 * arguments at all -- the same shape DrinkQuery has, for the same reason.
 *
 * The negating fields are the reason this class exists. "I don't like whiskey"
 * is the question dense retrieval is worst at: an embedding of "not whiskey"
 * sits among the whiskey drinks, so a vector answers it plausibly and wrongly.
 * Every cocktail carries its base spirit as a row, so a WHERE clause answers it
 * exactly.
 *
 * The category names below are the site's own display labels rather than keys,
 * because a tag has no slug: identity is the label plus its category. They are
 * the nine axes the menus are already organised along, which is what makes the
 * deterministic tool possible without inventing a vocabulary.
 */
readonly class MenuQuery
{
    public const BASE_ALCOHOL = 'Base Alcohol';

    public const FLAVOR = 'Flavor Profile';

    public const STYLE = 'Style';

    public const ORIGIN = 'Origin';

    public const ALCOHOL_LEVEL = 'Alcohol Level';

    public const TECHNIQUE = 'Technique';

    public const TEMPERATURE = 'Temperature';

    public const PREP_TIME = 'Prep Time';

    /**
     * @param  list<string>  $baseSpirits
     * @param  list<string>  $withoutBaseSpirits
     * @param  list<string>  $flavors
     * @param  list<string>  $styles
     * @param  list<string>  $origins
     * @param  list<string>  $withIngredients  ingredient slugs or titles
     * @param  list<string>  $withoutIngredients  ingredient slugs or titles
     */
    public function __construct(
        public array $baseSpirits = [],
        public array $withoutBaseSpirits = [],
        public array $flavors = [],
        public array $styles = [],
        public array $origins = [],
        public array $withIngredients = [],
        public array $withoutIngredients = [],
        public ?string $alcoholLevel = null,
        public ?string $technique = null,
        public ?string $temperature = null,
        public ?string $prepTime = null,
        public ?string $menu = null,
        public int $limit = 8,
    ) {}

    /**
     * The tag values this question asks for, by the category each sits in.
     *
     * Single-valued facets are carried as one-element lists so that filtering
     * has one shape rather than two: "any of these labels" with a list of one
     * is the same query as "this label".
     *
     * @return array<string, list<string>>
     */
    public function required(): array
    {
        return array_filter([
            self::BASE_ALCOHOL => $this->baseSpirits,
            self::FLAVOR => $this->flavors,
            self::STYLE => $this->styles,
            self::ORIGIN => $this->origins,
            self::ALCOHOL_LEVEL => $this->one($this->alcoholLevel),
            self::TECHNIQUE => $this->one($this->technique),
            self::TEMPERATURE => $this->one($this->temperature),
            self::PREP_TIME => $this->one($this->prepTime),
        ], fn (array $labels): bool => $labels !== []);
    }

    /**
     * The tag values this question rules out, by category.
     *
     * Only base alcohol can be excluded. A guest rules out a spirit -- "no
     * whiskey", "nothing with mezcal" -- and does not rule out a technique or a
     * glass, so the other eight axes carry no negating parameter. Offering one
     * would be a knob wired to a question nobody asks.
     *
     * @return array<string, list<string>>
     */
    public function excluded(): array
    {
        return array_filter([
            self::BASE_ALCOHOL => $this->withoutBaseSpirits,
        ], fn (array $labels): bool => $labels !== []);
    }

    /**
     * Whether anything at all was asked for.
     *
     * An unfiltered browse is a legitimate question -- "what's good?" -- and the
     * answer to it is a claim about the whole list rather than about a match, so
     * the preamble has to say which one it is giving.
     */
    public function isUnfiltered(): bool
    {
        return $this->required() === []
            && $this->excluded() === []
            && $this->withIngredients === []
            && $this->withoutIngredients === []
            && $this->menu === null;
    }

    /**
     * @return list<string>
     */
    private function one(?string $label): array
    {
        return $label === null || trim($label) === '' ? [] : [trim($label)];
    }
}
