<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry in the house's ingredient catalog.
 *
 * Catalog only: an ingredient never becomes a chunk. It has no page of its own
 * to cite and almost no prose to embed, and a corpus full of near-identical
 * one-line rum entries would crowd the actual cocktails out of every result.
 * What it is for is the deterministic half of the house -- "without rum",
 * "with lime" -- and for supplying the keyword vocabulary that makes the
 * cocktails using it findable.
 */
class HouseIngredient extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<HouseRecipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(HouseRecipe::class, 'house_recipe_id');
    }

    /**
     * @return HasMany<HouseCocktailIngredient, $this>
     */
    public function cocktailIngredients(): HasMany
    {
        return $this->hasMany(HouseCocktailIngredient::class);
    }

    /**
     * The bottle if one is named, otherwise the generic title.
     *
     * "Smith and Cross" reads better in a recipe than "Jamaican Rum" does, and
     * the site prints the group for exactly that reason.
     */
    public function displayName(): string
    {
        return $this->group ?? $this->title;
    }
}
