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
     * The style this bottle is grouped under, or null when it stands alone.
     *
     * "Jamaican Rum" for Coruba, and for Appleton Estate Signature beside it: the
     * site groups bottles of one style together, and a build that pours two of
     * them pours two different rums, not the same one twice. Null when the
     * catalog has no group, and when the group only repeats the title, because
     * "Rye Whiskey: Rye Whiskey" tells a guest nothing.
     */
    public function style(): ?string
    {
        if ($this->group === null || $this->group === $this->title) {
            return null;
        }

        return $this->group;
    }
}
