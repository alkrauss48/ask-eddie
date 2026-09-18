<?php

namespace App\Enums;

/**
 * What kind of house record a chunk was rendered from.
 *
 * There is no Ingredient case, and its absence is the single largest cut in the
 * house corpus: 131 of the 338 candidate records. A rendered ingredient reads
 * "Smith and Cross. Jamaican Rum, a base spirit." -- near-zero prose, and dozens
 * of them are mutually near-identical under bge-m3, so embedding them means a
 * query for "rum" comes back as thirty nearly-tied ingredient chunks with every
 * actual cocktail pushed out of the window. They also have no URL of their own
 * to cite: /ingredients is an index page with no [slug] route. Ingredients stay
 * a first-class catalog table for structured filtering, and every ingredient
 * title still reaches retrieval as a keyword on the cocktails that use it.
 */
enum HouseSourceType: string
{
    case Cocktail = 'cocktail';
    case Recipe = 'recipe';
    case Bartender = 'bartender';
    case Menu = 'menu';
    case Path = 'path';

    public function label(): string
    {
        return match ($this) {
            self::Cocktail => 'Cocktail',
            self::Recipe => 'Recipe',
            self::Bartender => 'Bartender',
            self::Menu => 'Menu',
            self::Path => 'Flight',
        };
    }
}
