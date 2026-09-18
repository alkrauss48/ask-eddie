<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bartender the house credits a drink to.
 *
 * Ranges from the dead and famous (Antoine Peychaud, 1803-1883) to whoever
 * happened to be standing at the blender, which is why both year columns are
 * nullable and neither is treated as identity.
 */
class HouseBartender extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_year' => 'integer',
            'death_year' => 'integer',
        ];
    }

    /**
     * @return HasMany<HouseCocktail, $this>
     */
    public function cocktails(): HasMany
    {
        return $this->hasMany(HouseCocktail::class);
    }

    /**
     * The years as they would read under a name, or null when neither is known.
     */
    public function years(): ?string
    {
        if ($this->birth_year === null && $this->death_year === null) {
            return null;
        }

        return $this->death_year === null
            ? "b. {$this->birth_year}"
            : "{$this->birth_year}–{$this->death_year}";
    }
}
