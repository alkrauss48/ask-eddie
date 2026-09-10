<?php

namespace App\Services\Books;

use Illuminate\Support\Str;

/**
 * Derives a book's title, author and year from its filename.
 *
 * The corpus uses two conventions inconsistently -- a leading year, or a
 * trailing year in parentheses -- and several filenames contain decoys for
 * both, so the order the two are tried in matters:
 *
 *   "1000 Misture by Elvezio Grassi (1936)"   leading digits are a title word
 *   "1917 Recipes... (second edition)"        trailing parens are not a year
 *   "1937 U.K.B.G. Approved Cocktails (United Kingdom Bartender's Guild)"
 *
 * Anything this gets wrong ends up in a citation, so `config('books.catalog')`
 * can override any field per filename; this parser supplies the default.
 */
class BookMetadataParser
{
    private const EARLIEST_YEAR = 1600;

    private const LATEST_YEAR = 1999;

    /**
     * @return array{title: string, author: string|null, year: int|null, edition: string|null, language: string, slug: string}
     */
    public function parse(string $filename): array
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        [$stem, $year] = $this->extractYear($stem);
        [$title, $author] = $this->splitTitleAndAuthor($stem);
        [$author, $edition] = $this->extractEdition($author);

        $overrides = $this->overridesFor($filename);

        $title = $overrides['title'] ?? $title;
        $author = $overrides['author'] ?? $author;
        $year = $overrides['year'] ?? $year;

        return [
            'title' => $title,
            'author' => $author,
            'year' => $year,
            'edition' => $overrides['edition'] ?? $edition,
            'language' => $overrides['language'] ?? (string) config('books.ocr.default_language'),
            'slug' => $this->slug($title, $year),
        ];
    }

    /**
     * Peel a trailing parenthetical off an author name.
     *
     * "Hugo R Ensslin (second edition)" is an author plus an edition note, not
     * a person. Only author strings are treated this way -- a parenthetical
     * left in a title, as in "U.K.B.G. Approved Cocktails (United Kingdom
     * Bartender's Guild)", is part of how the book is actually known.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function extractEdition(?string $author): array
    {
        if ($author === null) {
            return [null, null];
        }

        if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/u', $author, $matches) === 1) {
            return [trim($matches[1]) ?: null, trim($matches[2])];
        }

        return [$author, null];
    }

    /**
     * Find the publication year and strip it from the stem.
     *
     * A trailing parenthetical wins, but only when it is exactly a plausible
     * four-digit year -- which is what keeps "(second edition)" and
     * "(United Kingdom Bartender's Guild)" from being mistaken for one. A
     * leading token is only accepted within the same range, so the "1000" of
     * "1000 Misture" stays part of the title where it belongs.
     *
     * @return array{0: string, 1: int|null}
     */
    private function extractYear(string $stem): array
    {
        if (preg_match('/^(.*?)\s*\((\d{4})\)\s*$/u', $stem, $matches) === 1
            && $this->isPlausibleYear((int) $matches[2])) {
            return [trim($matches[1]), (int) $matches[2]];
        }

        if (preg_match('/^(\d{4})\s+(.+)$/u', $stem, $matches) === 1
            && $this->isPlausibleYear((int) $matches[1])) {
            return [trim($matches[2]), (int) $matches[1]];
        }

        return [trim($stem), null];
    }

    private function isPlausibleYear(int $year): bool
    {
        return $year >= self::EARLIEST_YEAR && $year <= self::LATEST_YEAR;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitTitleAndAuthor(string $stem): array
    {
        if (preg_match('/^(.+?)\s+by\s+(.+)$/iu', $stem, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [trim($stem), null];
    }

    /**
     * Slugs carry the year because three books in this corpus share the title
     * "Harry Johnson's Bartenders' Manual" and differ only by edition.
     */
    private function slug(string $title, ?int $year): string
    {
        $slug = Str::slug($title);

        return $year === null ? $slug : $slug.'-'.$year;
    }

    /**
     * @return array<string, mixed>
     */
    private function overridesFor(string $filename): array
    {
        /** @var array<string, array<string, mixed>> $catalog */
        $catalog = config('books.catalog', []);

        return $catalog[$filename] ?? $catalog[pathinfo($filename, PATHINFO_FILENAME)] ?? [];
    }
}
