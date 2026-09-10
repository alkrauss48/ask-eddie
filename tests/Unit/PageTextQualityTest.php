<?php

use App\Services\Books\PageTextQuality;

beforeEach(function (): void {
    $this->quality = new PageTextQuality;
});

/**
 * Verbatim from the embedded text layer of page 30 of the 1862 Jerry Thomas.
 */
function badTextLayerPage(): string
{
    return <<<'TEXT'
    26 KOCllESTKli rUKCll.
    1 pint of Jamaica rum.
    ^ do. Cura9oa.
    Juice of six lemons,
    li 11). white sugar.
    Mix thoroughly, and strain, as already described in the
    recipe for 'Punch d la Ford^' adding more sugar and
    lemon juice, if to taste. Bottle, and keep on ice for three
    or four days, and the punch will he ready for use.
    TEXT;
}

it('scores empty text as zero with a reason', function (): void {
    $score = $this->quality->score('    ');

    expect($score->score)->toBeNull()
        ->and($score->reason)->toBe('empty')
        ->and($score->passes())->toBeFalse();
});

it('declines to score a page with too little text to judge', function (): void {
    $score = $this->quality->score('Gin. 2 oz.');

    expect($score->score)->toBeNull()
        ->and($score->reason)->toBe('insufficient_text')
        ->and($score->passes())->toBeFalse();
});

it('scores clean prose highly', function (): void {
    $score = $this->quality->score(
        'Two dashes gum-syrup, two dashes Peyschaud bitters, one dash orange '
        .'bitters, half a jigger brandy, half a jigger French vermouth, a '
        .'mixing-glass half-full fine ice. Mix, strain into cocktail-glass.'
    );

    expect($score->score)->toBeGreaterThan(0.90);
});

it('scores scanner noise far below readable text', function (): void {
    $noise = $this->quality->score(
        "'\u{00A5}Ml LliRARV 1234 OpyqiAMT PMTBV rir.CtlVEC- AMONLNAYM "
        .'S:AHMONLNAY PLA4 VXc TWO COP[IL-U:'
    );

    expect($noise->score)->toBeLessThan(0.70);
});

/**
 * The archive.org text layer's signature failure is case flipping inside a
 * word, and that is what this scorer exists to detect.
 */
it('rejects tokens whose case flips mid-word', function (string $token): void {
    $page = "The following passage is provided for context here. {$token}";

    $withGarbage = $this->quality->score($page);
    $withoutGarbage = $this->quality->score('The following passage is provided for context here.');

    expect($withGarbage->breakdown['plausible_ratio'])
        ->toBeLessThan($withoutGarbage->breakdown['plausible_ratio'] ?? 1.0);
})->with(['KOCllESTKli', 'rUKCll', 'LliRARV']);

it('rejects letters welded to digits', function (): void {
    $score = $this->quality->score('Cura9oa i8n t8 PLA4 i34 pour into a glass and serve at once');

    expect($score->breakdown['plausible_ratio'])->toBeLessThan(0.7);
});

it('accepts real words the corpus is full of', function (string $word): void {
    $page = "A recipe calling for {$word} among its several other ingredients.";

    expect($this->quality->score($page)->breakdown['plausible_ratio'])->toBe(1.0);
})->with(['Curaçao', 'Sauterne', 'Angostura', 'strengths', 'Bar-Tender', "d'orange"]);

it('is deterministic', function (): void {
    $first = $this->quality->score(badTextLayerPage());
    $second = $this->quality->score(badTextLayerPage());

    expect($first->score)->toBe($second->score);
});

/**
 * This is the limitation the pipeline is built around, so it is pinned rather
 * than papered over. A page whose body is sound but whose heading is ruined
 * still scores well, which is why every page is OCR'd instead of gated on this.
 */
it('cannot detect a few ruined tokens on an otherwise sound page', function (): void {
    $score = $this->quality->score(badTextLayerPage());

    expect($score->score)->toBeGreaterThan(0.90);
});
