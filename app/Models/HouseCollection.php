<?php

namespace App\Models;

use App\Enums\HouseCollectionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A curated list of cocktails: one of the three menus, or one of the ten flights.
 *
 * Both kinds live here because they are the same structure. A menu groups its
 * drinks into sections and keeps a short featured list; a flight is an ordered
 * walk with a subtitle and a description. Section title, featured flag and
 * subtitle are nullable columns rather than a second pair of tables, which is
 * what keeps "which lists is this drink on" to one query.
 */
class HouseCollection extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => HouseCollectionKind::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<HouseCocktail, $this>
     */
    public function cocktails(): BelongsToMany
    {
        return $this->belongsToMany(HouseCocktail::class, 'house_collection_cocktails')
            ->withPivot(['position', 'section_title', 'is_featured'])
            ->orderBy('house_collection_cocktails.position');
    }
}
