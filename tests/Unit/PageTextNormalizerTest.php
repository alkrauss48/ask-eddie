<?php

use App\Services\Books\PageTextNormalizer;

beforeEach(function (): void {
    $this->normalizer = new PageTextNormalizer;
});

it('replaces typographic ligatures with their component letters', function (): void {
    $result = $this->normalizer->normalize("The \u{FB01}rst \u{FB02}ip was \u{FB00}ered su\u{FB03}ciently to the gue\u{017F}t.");

    expect($result->text)->toBe('The first flip was ffered sufficiently to the guest.');
});

it('removes invisible characters that would otherwise skew similarity', function (): void {
    $result = $this->normalizer->normalize("Gin\u{200B} and\u{00AD} bitters\u{FEFF}, shaken\u{0001}.");

    expect($result->text)->toBe('Gin and bitters, shaken.');
});

it('folds unicode whitespace down to a plain space', function (): void {
    $result = $this->normalizer->normalize("one\u{00A0}jigger\u{2009}of\u{3000}rum");

    expect($result->text)->toBe('one jigger of rum');
});

it('composes decomposed accents so a word is always the same bytes', function (): void {
    $decomposed = "Curac\u{0327}ao and cre\u{0300}me de menthe";

    $result = $this->normalizer->normalize($decomposed);

    expect($result->text)->toBe('Curaçao and crème de menthe')
        ->and(Normalizer::isNormalized($result->text, Normalizer::FORM_C))->toBeTrue();
});

it('rejoins a lowercase word split across a line break', function (): void {
    $result = $this->normalizer->normalize("Pour the cock-\ntail into a chilled glass.");

    expect($result->text)->toBe('Pour the cocktail into a chilled glass.');
});

it('leaves genuine hyphenated compounds alone', function (): void {
    $result = $this->normalizer->normalize("An Anglo-\nAmerican tradition.");

    expect($result->text)->toBe("An Anglo-\nAmerican tradition.");
});

it('captures the printed page number rather than discarding it', function (): void {
    $result = $this->normalizer->normalize("42\nThe Bar-Tender's Guide\n\nTake one jigger of rum.");

    expect($result->printedPageLabel)->toBe('42')
        ->and($result->text)->not->toContain('42');
});

it('reads a printed page number from the foot of the page', function (): void {
    $result = $this->normalizer->normalize("Take one jigger of rum.\n- 118 -");

    expect($result->printedPageLabel)->toBe('118');
});

it('reads roman numerals from the front matter', function (): void {
    $result = $this->normalizer->normalize("xiv\nPREFACE\n\nThis little volume.");

    expect($result->printedPageLabel)->toBe('xiv');
});

it('leaves a number that is part of the text', function (): void {
    $result = $this->normalizer->normalize('Take 2 dashes of bitters and 1 jigger of rye.');

    expect($result->printedPageLabel)->toBeNull()
        ->and($result->text)->toBe('Take 2 dashes of bitters and 1 jigger of rye.');
});

it('collapses runs of spaces and blank lines', function (): void {
    $result = $this->normalizer->normalize("Shake     well.\n\n\n\n\nStrain.");

    expect($result->text)->toBe("Shake well.\n\nStrain.");
});

/**
 * The Python pipeline this replaces ran a regex five times over that welded any
 * single letter to the word after it. These pin the decision not to port it.
 */
it('does not weld single letters onto the following word', function (string $input): void {
    expect($this->normalizer->normalize($input)->text)->toBe($input);
})->with([
    'Add a dash of Angostura bitters.',
    'I shall take a julep.',
    'Fill a wine-glass with shaved ice.',
]);

/**
 * The Python also dropped short lines, lines below an alphanumeric ratio, and
 * lines with several apostrophes -- which between them delete ingredients and
 * most French text. Normalization here is not allowed to lose content.
 */
it('never deletes a line of content', function (string $line): void {
    $result = $this->normalizer->normalize("A recipe follows.\n{$line}\nStir well.");

    expect($result->text)->toContain($line);
})->with([
    'Gin.',
    'Ice',
    '1/2',
    "de l'eau d'orange et d'absinthe",
    '— — —',
]);

it('is idempotent', function (): void {
    $text = "The \u{FB01}rst cock-\ntail.\n\n\n42\nCurac\u{0327}ao   and   rum.";

    $once = $this->normalizer->normalize($text)->text;
    $twice = $this->normalizer->normalize($once)->text;

    expect($twice)->toBe($once);
});

it('counts characters and words of the normalized text', function (): void {
    $result = $this->normalizer->normalize('One jigger of rum.');

    expect($result->wordCount())->toBe(4)
        ->and($result->characterCount())->toBe(18);
});

it('lifts a page number that shares its line with the running head', function (string $line, string $label, string $head): void {
    $result = $this->normalizer->normalize("{$line}\n\nTake one jigger of rum.");

    expect($result->printedPageLabel)->toBe($label)
        ->and($result->text)->toStartWith($head);
})->with([
    ['26      ROCHESTER PUNCH.', '26', 'ROCHESTER PUNCH.'],
    ['MODERN AMERICAN DRINKS.      39', '39', 'MODERN AMERICAN DRINKS.'],
    ['118   THE FLOWING BOWL', '118', 'THE FLOWING BOWL'],
]);

/**
 * A recipe line starting with a quantity must not be mistaken for a folio.
 */
it('does not mistake a leading quantity for a page number', function (string $line): void {
    $result = $this->normalizer->normalize("{$line}\nStir and strain.");

    expect($result->printedPageLabel)->toBeNull()
        ->and($result->text)->toStartWith($line);
})->with([
    '2 dashes of Angostura bitters',
    '1 pint of Jamaica rum.',
    '3 bottles of champagne, iced.',
]);

it('only looks at the outermost lines for a page number', function (): void {
    $result = $this->normalizer->normalize("Take a tumbler.\n42\nFill with ice.");

    expect($result->printedPageLabel)->toBeNull()
        ->and($result->text)->toContain('42');
});
