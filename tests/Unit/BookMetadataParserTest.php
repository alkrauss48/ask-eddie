<?php

use App\Services\Books\BookMetadataParser;

beforeEach(function (): void {
    $this->parser = new BookMetadataParser;
});

it('reads a year from a trailing parenthetical', function (): void {
    $result = $this->parser->parse('Oxford Night Caps by Richard Cook (1827).pdf');

    expect($result['year'])->toBe(1827)
        ->and($result['title'])->toBe('Oxford Night Caps')
        ->and($result['author'])->toBe('Richard Cook');
});

it('reads a year from the start of the filename', function (): void {
    $result = $this->parser->parse('1908 The World\'s Drinks and How to Mix Them by Hon Wm Boothby.pdf');

    expect($result['year'])->toBe(1908)
        ->and($result['title'])->toBe("The World's Drinks and How to Mix Them")
        ->and($result['author'])->toBe('Hon Wm Boothby');
});

/**
 * "1000" is the number of recipes, not the year, and the real year is in the
 * trailing parenthetical. Preferring the parenthetical is what gets this right.
 */
it('does not mistake leading digits outside the plausible range for a year', function (): void {
    $result = $this->parser->parse('1000 Misture by Elvezio Grassi (1936).pdf');

    expect($result['year'])->toBe(1936)
        ->and($result['title'])->toBe('1000 Misture');
});

it('ignores a trailing parenthetical that is not a year', function (): void {
    $result = $this->parser->parse('1937 U.K.B.G. Approved Cocktails (United Kingdom Bartender\'s Guild).pdf');

    expect($result['year'])->toBe(1937)
        ->and($result['title'])->toBe("U.K.B.G. Approved Cocktails (United Kingdom Bartender's Guild)");
});

it('separates an edition note from the author', function (): void {
    $result = $this->parser->parse('1917 Recipes for Mixed Drinks by Hugo R Ensslin (second edition).pdf');

    expect($result['year'])->toBe(1917)
        ->and($result['author'])->toBe('Hugo R Ensslin')
        ->and($result['edition'])->toBe('second edition');
});

it('handles a title with no author at all', function (): void {
    $result = $this->parser->parse("1903 Daly's bartenders' encyclopedia.pdf");

    expect($result['author'])->toBeNull()
        ->and($result['title'])->toBe("Daly's bartenders' encyclopedia");
});

/**
 * Three editions of this book share a title and differ only by year, so the
 * year has to be part of the slug or the unique constraint collides on import.
 */
it('gives each edition of a repeated title a distinct slug', function (): void {
    $slugs = collect([1882, 1888, 1900])
        ->map(fn (int $year): string => $this->parser->parse("Harry Johnson\u{2019}s Bartenders\u{2019} Manual ({$year}).pdf")['slug'])
        ->all();

    expect($slugs)->toBe([
        'harry-johnsons-bartenders-manual-1882',
        'harry-johnsons-bartenders-manual-1888',
        'harry-johnsons-bartenders-manual-1900',
    ]);
});

it('slugifies curly apostrophes and accents cleanly', function (string $filename, string $slug): void {
    expect($this->parser->parse($filename)['slug'])->toBe($slug);
})->with([
    ['Manual del Cantinero by León Pujol and Oscar Muñiz (1924).pdf', 'manual-del-cantinero-1924'],
    ["Caf\u{00E9} Royal Cocktail Book by William J Tarling (1937).pdf", 'cafe-royal-cocktail-book-1937'],
    ["When It\u{2019}s Cocktail Time in Cuba by Basil Woon (1928).pdf", 'when-its-cocktail-time-in-cuba-1928'],
]);

it('falls back to no year when the filename carries none', function (): void {
    $result = $this->parser->parse('An Untitled Manuscript.pdf');

    expect($result['year'])->toBeNull()
        ->and($result['slug'])->toBe('an-untitled-manuscript');
});

it('lets the catalog override what the filename says', function (): void {
    config(['books.catalog' => [
        'Bariana by Louis Fouquet (1896)' => ['language' => 'fra', 'author' => 'Louis Fouquet'],
    ]]);

    $result = $this->parser->parse('Bariana by Louis Fouquet (1896).pdf');

    expect($result['language'])->toBe('fra');
});
