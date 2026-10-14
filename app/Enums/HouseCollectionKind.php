<?php

namespace App\Enums;

/**
 * The three shapes of curated list the house keeps.
 *
 * All are a titled, ordered list of cocktails with a slug, which is why they
 * share one table: a menu carries section titles and a featured list, a flight
 * carries a subtitle and a description, a collection carries a description and
 * optional group headings, and all of those are nullable columns rather than a
 * reason to write the same joins three times.
 *
 * "Collection" is overloaded here. The table, the model and this enum use the
 * word for any curated list; the Collection case is the site's own
 * /collections/<slug> page -- a set rather than a season or a walk, such as
 * every drink the house serves in a tiki mug.
 */
enum HouseCollectionKind: string
{
    case Menu = 'menu';
    case Path = 'path';
    case Collection = 'collection';

    public function label(): string
    {
        return match ($this) {
            self::Menu => 'Menu',
            self::Path => 'Flight',
            self::Collection => 'Collection',
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
            self::Collection => HouseSourceType::Collection,
        };
    }
}
