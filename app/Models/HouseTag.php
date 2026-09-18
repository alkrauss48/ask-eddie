<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One facet value, in one category.
 *
 * A tag has no slug on the site, so identity is the label plus its category:
 * "Rum" under Base Alcohol is not the same tag as "Rum" would be under Flavor
 * Profile, and nothing but the pair can say so.
 *
 * The nine categories are the axes a guest actually recommends along -- base
 * spirit, flavour, style, origin, alcohol level, technique, temperature,
 * glassware, prep time -- which is what makes the deterministic tool possible
 * at all.
 */
class HouseTag extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<HouseCocktail, $this>
     */
    public function cocktails(): BelongsToMany
    {
        return $this->belongsToMany(HouseCocktail::class, 'house_cocktail_tags');
    }
}
