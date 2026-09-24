<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A drink the house actually pours.
 *
 * This table is the answer to "does the house have one of those", and the
 * reason Sasha may not name a drink that is not in it. Like Drink and unlike
 * BookChunk, its toArray() is not a payload and must not become one: nothing in
 * laravel/ai serializes this model out of the application's reach, so the
 * safer construction is available and a value object carries whatever reaches a
 * prompt.
 */
class HouseCocktail extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_straw' => 'boolean',
            'servings' => 'integer',
            'variations' => 'array',
            'source' => 'array',
        ];
    }

    /**
     * @return BelongsTo<HouseBartender, $this>
     */
    public function bartender(): BelongsTo
    {
        return $this->belongsTo(HouseBartender::class, 'house_bartender_id');
    }

    /**
     * @return HasMany<HouseCocktailIngredient, $this>
     */
    public function cocktailIngredients(): HasMany
    {
        return $this->hasMany(HouseCocktailIngredient::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<HouseIngredient, $this>
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(HouseIngredient::class, 'house_cocktail_ingredients')
            ->withPivot(['position', 'amount', 'label'])
            ->orderBy('house_cocktail_ingredients.position');
    }

    /**
     * @return BelongsToMany<HouseTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(HouseTag::class, 'house_cocktail_tags');
    }

    /**
     * Every menu and flight this drink appears on.
     *
     * One query rather than a union over two tables, which is the whole reason
     * menus and flights share one table.
     *
     * @return BelongsToMany<HouseCollection, $this>
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(HouseCollection::class, 'house_collection_cocktails')
            ->withPivot(['position', 'section_title', 'is_featured']);
    }

    /**
     * The bottles this drink pours, grouped under the style each belongs to.
     *
     * ["Jamaican Rum" => ["Coruba", "Appleton Estate Signature"]]. The build
     * names bottles and this says which of them are the same kind of thing, so a
     * bartender reading both can say "two Jamaican rums, Coruba and Appleton"
     * rather than either losing the bottles or losing the family. Styles and
     * bottles keep the order they are poured in. Reads the loaded ingredient
     * lines, so callers eager load `cocktailIngredients.ingredient`.
     *
     * @return array<string, list<string>>
     */
    public function bottlesByStyle(): array
    {
        $styles = [];

        foreach ($this->cocktailIngredients as $line) {
            $style = $line->ingredient?->style();

            if ($style === null || in_array($line->ingredient->title, $styles[$style] ?? [], true)) {
                continue;
            }

            $styles[$style][] = $line->ingredient->title;
        }

        return $styles;
    }
}
