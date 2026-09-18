<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a cocktail's build.
 *
 * The site's ingredient list is a genuine union: most lines point at a catalog
 * ingredient and carry an amount, and some are bare strings -- "3.5oz Purified
 * Water", "Garnish: 3 coffee beans", "Served in a smoked glass". Both are kept
 * as themselves. Flattening the free-text half into fake ingredient rows would
 * invent catalog members the site does not have, and every structured query
 * would then be able to find them.
 *
 * Exactly one of house_ingredient_id and free_text is set. A row with neither
 * names nothing at all, which is what `house:import --verify` rejects.
 */
class HouseCocktailIngredient extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<HouseCocktail, $this>
     */
    public function cocktail(): BelongsTo
    {
        return $this->belongsTo(HouseCocktail::class, 'house_cocktail_id');
    }

    /**
     * @return BelongsTo<HouseIngredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(HouseIngredient::class, 'house_ingredient_id');
    }

    /**
     * The line as a bartender would read it off a card.
     *
     * The site's own label wins when it has one, because it is written for the
     * build ("Garnish: Lemon twist") rather than for the catalog ("Lemon
     * Garnish").
     */
    public function line(): string
    {
        if ($this->free_text !== null) {
            return $this->free_text;
        }

        $name = $this->label ?? $this->ingredient?->displayName() ?? '';

        return trim(($this->amount === null ? '' : $this->amount.' ').$name);
    }
}
