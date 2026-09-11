<?php

use App\Enums\ChunkKind;
use App\Services\Books\ChunkClassification;
use App\Services\Books\ChunkClassifier;
use App\Services\Books\ClassificationContext;
use App\Services\Books\PageTextQuality;

beforeEach(function (): void {
    $this->classifier = new ChunkClassifier(new PageTextQuality);
});

function classify(string $text, ClassificationContext $context = new ClassificationContext): ChunkClassification
{
    return test()->classifier->classify($text, $context);
}

/**
 * The Python original discarded any chunk whose lines were 80% short as "a
 * list", which would delete every page of Cafe Royal, whose median block is 20
 * characters. A recipe is recognised positively here instead.
 */
it('keeps a short-line recipe block as an indexable recipe', function (): void {
    $text = rtrim((string) file_get_contents(__DIR__.'/../Fixtures/Books/cafe-royal-cocktail-book-1937/page-40.txt'), "\n");

    $classification = classify($text, new ClassificationContext(headingPresent: true, recipeHeadings: 5, lineCount: 26));

    expect($classification->kind)->toBe(ChunkKind::RecipeList)
        ->and($classification->isIndexable())->toBeTrue();
});

it('classifies a dot-leader index page without deleting it', function (): void {
    $text = rtrim((string) file_get_contents(__DIR__.'/../Fixtures/Books/the-worlds-drinks-and-how-to-mix-them-1908/page-11.txt'), "\n");

    $classification = classify($text, new ClassificationContext(pageFrom: 11, pageTo: 11, lineCount: 40));

    expect($classification->kind)->toBe(ChunkKind::Index)
        ->and($classification->isIndexable())->toBeFalse();
});

/**
 * An index page of a book of drinks is full of drink names, and one of them
 * carried an OCR-mangled heading and a stray verb -- which read a whole index as
 * a recipe until the index test was moved ahead of the recipe test.
 */
it('does not read an index as a recipe just because it has a heading', function (): void {
    $text = "NKW\nWine Lemonade 89\nVespetro 310\nSherbet 90\nWischniak 519\nVin Brule 221\nShake 118";

    $classification = classify($text, new ClassificationContext(headingPresent: true, lineCount: 7));

    expect($classification->kind)->toBe(ChunkKind::Index);
});

/**
 * One signal is not enough: a punch recipe can mention a price, and the known
 * contamination is two pages out of 5,099.
 */
it('needs two signals before calling a block an advertisement', function (): void {
    $text = rtrim((string) file_get_contents(__DIR__.'/../Fixtures/Books/the-flowing-bowl-1892/page-301.txt'), "\n");

    expect(classify($text, new ClassificationContext(pageFrom: 301, pageTo: 301, lineCount: 20))->kind)
        ->toBe(ChunkKind::Advertisement);
});

it('does not call a recipe an advertisement for mentioning a price', function (): void {
    $text = "PUNCH FOR A PARTY.\nThe price of this punch is no object.\nOne quart of brandy, published by no one.\nShake and strain into a bowl.";

    expect(classify($text, new ClassificationContext(headingPresent: true, lineCount: 4))->kind)
        ->toBe(ChunkKind::Recipe);
});

/**
 * Real content, mangled by the scanner. The Python's twenty-character minimum
 * and consonant-cluster rules ate lines like this.
 */
it('does not mistake mangled recipe text for noise', function (): void {
    $classification = classify('~ 1 small lump of ice.');

    expect($classification->kind)->not->toBe(ChunkKind::Noise)
        ->and($classification->isIndexable())->toBeTrue();
});

it('classifies a scanner artefact as noise but still keeps it', function (string $text): void {
    expect(classify($text)->kind)->toBe(ChunkKind::Noise);
})->with(['3%', '.~ °°', '~~“']);

/**
 * Fewer than eight tokens cannot be scored, and an unscorable chunk is not
 * garbage: a three-line recipe is exactly this shape.
 */
it('keeps a chunk too short to score', function (): void {
    $classification = classify("GIN TODDY.\n1 teaspoonful of sugar.\nStir.", new ClassificationContext(headingPresent: true, lineCount: 3));

    expect($classification->isIndexable())->toBeTrue();
});

/**
 * Five of the 28 books are French, Spanish or Italian. English keyword lists
 * would classify their real text on a coincidence.
 */
it('does not apply english advertisement keywords to a french book', function (): void {
    $text = rtrim((string) file_get_contents(__DIR__.'/../Fixtures/Books/bariana-1896/page-20.txt'), "\n");

    $classification = classify($text, new ClassificationContext(language: 'fra', lineCount: 14));

    expect($classification->kind)->not->toBe(ChunkKind::Advertisement)
        ->and($classification->kind)->not->toBe(ChunkKind::FrontMatter)
        ->and($classification->isIndexable())->toBeTrue();
});

it('recognises prose', function (): void {
    $text = rtrim((string) file_get_contents(__DIR__.'/../Fixtures/Books/old-waldorf-bar-days-1931/page-120.txt'), "\n");

    expect(classify($text, new ClassificationContext(pageFrom: 120, pageTo: 120, lineCount: 24))->kind)
        ->toBe(ChunkKind::Prose);
});

/**
 * The inverse of the Python's gauntlet, which discarded anything it could not
 * vouch for and lost real recipes doing it.
 */
it('falls open to indexable when nothing matches', function (): void {
    $classification = classify('Qwerty asdf zxcv hjkl bnm ghjk wert yuio.');

    expect($classification->kind)->toBe(ChunkKind::Unknown)
        ->and($classification->isIndexable())->toBeTrue();
});

it('records the measurements behind its verdict', function (): void {
    $classification = classify('Sangaree, Ale ... . ........ . ... .. 370');

    expect($classification->signals)->toHaveKeys([
        'chars', 'lines', 'letter_ratio', 'dot_leader_ratio', 'trailing_number_ratio', 'reason',
    ]);
});

it('marks matter before the body as front matter', function (): void {
    $text = "Copyright 1908 by the author.\nAll rights reserved.\nEntered according to Act of Congress in the year 1890.";

    expect(classify($text, new ClassificationContext(pageFrom: 4, pageTo: 4, firstBodyPage: 9, lastBodyPage: 150, lineCount: 3))->kind)
        ->toBe(ChunkKind::FrontMatter);
});
