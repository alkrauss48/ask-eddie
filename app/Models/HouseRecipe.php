<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the house makes rather than buys: a syrup, an infusion, a cordial.
 *
 * "name" rather than "title" because that is what the site calls it, and the
 * exception is kept rather than smoothed over so that this table reads the same
 * as the export it came from.
 *
 * ingredients is free text, one line each, and deliberately unlinked to
 * house_ingredients -- see the migration for why that link must not be built.
 */
class HouseRecipe extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ingredients' => 'array',
        ];
    }

    /**
     * @return HasMany<HouseIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(HouseIngredient::class);
    }
}
