<?php

use App\Enums\ChunkKind;
use App\Models\BookChunk;
use App\Services\Books\DrinkHeadingScanner;
use App\Services\Books\HeadingPatterns;

function scanner(): DrinkHeadingScanner
{
    return app(DrinkHeadingScanner::class);
}

/**
 * Built unsaved, the way the chunking unit tests do, so the scanner can be
 * exercised without a database.
 */
function scannableChunk(string $text, int $overlap = 0, int $charStart = 0): BookChunk
{
    return new BookChunk([
        'kind' => ChunkKind::Recipe,
        'is_indexable' => true,
        'text' => $text,
        'overlap_chars' => $overlap,
        'char_start' => $charStart,
    ]);
}

it('finds a shouted name sharing its line with the first ingredient', function (): void {
    $mentions = scanner()->scan(scannableChunk(
        "BLUE LADY 1/2 Blue Curaçao (Garnier).\n1/4 Booth's Gin.\nShake and strain."
    ));

    expect($mentions)->toHaveCount(1)
        ->and($mentions[0]->rawHeading)->toBe('BLUE LADY')
        ->and($mentions[0]->family)->toBe(HeadingPatterns::CAPS_PREFIX);
});

it('finds a numbered recipe and keeps its number', function (): void {
    $mentions = scanner()->scan(scannableChunk(
        "128. Gin Sangaree.\n1 wine-glass of gin.\nShake and strain."
    ));

    expect($mentions)->toHaveCount(1)
        ->and($mentions[0]->rawHeading)->toBe('Gin Sangaree.')
        ->and($mentions[0]->family)->toBe(HeadingPatterns::NUMBERED)
        ->and($mentions[0]->number)->toBe('128');
});

it('rejects a numbered division heading', function (): void {
    $mentions = scanner()->scan(scannableChunk(
        "131. TODDIES AND SLINGS\nThese are made as follows."
    ));

    expect($mentions)->toBeEmpty();
});

/**
 * The regression test for the reason opensARecipe() was made public.
 * matchCapsLine() flags every caps line sectionLike, so in a book that shouts
 * its drink names the flag cannot tell a division from a drink -- only what
 * follows the line can.
 */
it('tells a shouted drink name from a shouted division by what follows it', function (): void {
    $division = scanner()->scan(scannableChunk(
        "PUNCHES.\nThe punches in this chapter are all of the old school."
    ));

    $drink = scanner()->scan(scannableChunk(
        "GIN SLING.\n1 wine-glass of gin.\nGrate nutmeg on top."
    ));

    expect($division)->toBeEmpty()
        ->and($drink)->toHaveCount(1)
        ->and($drink[0]->rawHeading)->toBe('GIN SLING')
        ->and($drink[0]->family)->toBe(HeadingPatterns::CAPS_LINE);
});

/**
 * A prose chunk opens with whole sentences borrowed from the chunk before it.
 * A heading inside that tail belongs to the previous chunk's citation, so
 * counting it here would file a drink on a page it was not printed on.
 */
it('ignores a heading inside the borrowed overlap', function (): void {
    $overlap = "GIN SLING.\n1 wine-glass of gin.\n";

    $mentions = scanner()->scan(scannableChunk(
        $overlap."BRANDY SMASH.\n1 wine-glass of brandy.",
        strlen($overlap),
    ));

    expect($mentions)->toHaveCount(1)
        ->and($mentions[0]->rawHeading)->toBe('BRANDY SMASH');
});

/**
 * The invariant --verify asserts, checked here at the source: a mention's
 * offset has to land on the string it claims, because proving the offset is
 * what proves the page range the citation is rendered from.
 */
it('anchors every mention to the byte offset its name was printed at', function (): void {
    $text = "128. Gin Sangaree.\n1 wine-glass of gin.\n\nBLUE LADY 1/2 Blue Curaçao.\n1/4 Gin.";
    $chunk = scannableChunk($text, charStart: 4_000);

    foreach (scanner()->scan($chunk) as $mention) {
        expect(substr($text, $mention->offsetInChunk, strlen($mention->rawHeading)))
            ->toBe($mention->rawHeading);
    }
});

/**
 * Guards the trim() fix in HeadingPatterns: a character list containing an em
 * dash is matched byte by byte and can shear one byte off an unrelated
 * multibyte character, which is how a heading ending in half of a "ç" reached
 * a 500-row insert and had it rejected.
 */
it('returns valid utf-8 for an accented heading', function (): void {
    $mentions = scanner()->scan(scannableChunk(
        "Crème de Menthe Frappé\n1 wine-glass of crème de menthe."
    ));

    foreach ($mentions as $mention) {
        expect(mb_check_encoding($mention->rawHeading, 'UTF-8'))->toBeTrue();
    }
});
