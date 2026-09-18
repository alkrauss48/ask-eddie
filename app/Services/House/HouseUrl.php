<?php

namespace App\Services\House;

/**
 * The site's own paths, made into links a guest can open.
 *
 * The export gives each record its path ("/cocktails/mai-tai") rather than a
 * full URL, because the site owns its URL shapes and reconstructing them from a
 * route pattern here would turn a route change over there into a broken
 * citation here with nothing to report it. The origin is configuration.
 *
 * This lives in one place rather than at each call site because the catalog
 * tables store the raw path and the chunks store the absolute form: two copies
 * of the rule would drift the first time HOUSE_SITE_URL gained a path segment,
 * and a citation with a relative href reads as a link and is not one.
 */
final class HouseUrl
{
    public static function absolute(string $path): string
    {
        return str_starts_with($path, 'http')
            ? $path
            : (string) config('house.site_url').'/'.ltrim($path, '/');
    }
}
