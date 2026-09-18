<?php

namespace App\Enums;

/**
 * The two shapes of curated list the house keeps.
 *
 * Both are a titled, ordered list of cocktails with a slug, which is why they
 * share one table: a menu carries section titles and a featured list, a flight
 * carries a subtitle and a description, and all of those are nullable columns
 * rather than a reason to write the same joins twice.
 */
enum HouseCollectionKind: string
{
    case Menu = 'menu';
    case Path = 'path';

    public function label(): string
    {
        return match ($this) {
            self::Menu => 'Menu',
            self::Path => 'Flight',
        };
    }

    /**
     * The chunk source type a collection of this kind renders as.
     */
    public function sourceType(): HouseSourceType
    {
        return match ($this) {
            self::Menu => HouseSourceType::Menu,
            self::Path => HouseSourceType::Path,
        };
    }
}
