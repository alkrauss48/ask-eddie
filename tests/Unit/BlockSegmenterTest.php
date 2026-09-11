<?php

use App\Services\Books\BlockSegmenter;
use App\Services\Books\BookTextStream;
use App\Services\Books\HeadingPatterns;
use App\Services\Books\PageSpan;
use App\Services\Books\TextBlock;
use App\Services\Books\TokenEstimator;

beforeEach(function (): void {
    $this->tokens = new TokenEstimator;
    $this->segmenter = new BlockSegmenter(new HeadingPatterns, $this->tokens);
});

/**
 * @return list<TextBlock>
 */
function segment(string $text): array
{
    $stream = new BookTextStream($text, [new PageSpan(1, 0, strlen($text))], 'x');

    return test()->segmenter->segment($stream);
}

it('splits on blank lines', function (): void {
    $blocks = segment("First paragraph.\n\nSecond paragraph.\n\nThird paragraph.");

    expect($blocks)->toHaveCount(3)
        ->and($blocks[1]->text)->toBe('Second paragraph.');
});

it('reports offsets that slice the original text back out', function (): void {
    $text = "First paragraph.\n\nSecond paragraph.";
    $blocks = segment($text);

    foreach ($blocks as $block) {
        expect(substr($text, $block->start, $block->length()))->toBe($block->text);
    }
});

/**
 * The heading stays inside the block. The Python original lifted it out and
 * then embedded the body alone, so no drink name ever reached a vector.
 */
it('captures a heading without removing it from the text', function (): void {
    $blocks = segment("BLUE LADY 1/2 Blue Curasao (Gamier).\nShake and strain.");

    expect($blocks[0]->heading?->text)->toBe('BLUE LADY')
        ->and($blocks[0]->text)->toStartWith('BLUE LADY 1/2 Blue Curasao')
        ->and($blocks[0]->opensRecipe())->toBeTrue();
});

it('captures a numbered recipe heading and its mangled number', function (): void {
    $blocks = segment("13u. Porter Sangaree.\n\n(Use large bar glass.)");

    expect($blocks[0]->heading?->number)->toBe('13u')
        ->and($blocks[0]->text)->toContain('13u. Porter Sangaree.');
});

it('marks a shouted numbered line as opening a section, not a recipe', function (): void {
    $blocks = segment('131. TODDIES AND SLINGS');

    expect($blocks[0]->opensSection())->toBeTrue()
        ->and($blocks[0]->opensRecipe())->toBeFalse();
});

it('leaves a block that already fits alone', function (): void {
    $blocks = segment('A short paragraph that comfortably fits inside a chunk.');

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]->hardCut)->toBeFalse();
});

it('splits an oversized paragraph at a sentence boundary', function (): void {
    $sentence = 'The bartender mixed the drink and set it down upon the polished bar. ';
    $blocks = segment(trim(str_repeat($sentence, 60)));

    expect(count($blocks))->toBeGreaterThan(1);

    foreach ($blocks as $block) {
        expect(strlen($block->text))->toBeLessThanOrEqual($this->tokens->contentCharacterCeiling())
            ->and($block->hardCut)->toBeFalse();
        // Every part begins a sentence rather than continuing one.
        expect($block->text)->toStartWith('The bartender');
    }
});

/**
 * The abbreviation guard the Python original lacked: it split on every period,
 * so "No. 134" and "Mr. Thomas" became sentence boundaries.
 */
it('does not treat an abbreviation as the end of a sentence', function (): void {
    $filler = 'and the gentleman drank it down without a word of complaint at all. ';
    $text = trim(str_repeat($filler, 20).'See No. 134 for the gin toddy. '.str_repeat($filler, 20));

    foreach (segment($text) as $block) {
        expect($block->text)->not->toStartWith('134');
    }
});

it('splits a paragraph of unbreakable lines at a line boundary', function (): void {
    $blocks = segment(trim(str_repeat("Sangaree, Ale ... 370\n", 120)));

    expect(count($blocks))->toBeGreaterThan(1);

    foreach ($blocks as $block) {
        expect($block->hardCut)->toBeFalse();
    }
});

/**
 * Real prose never does this, but OCR occasionally emits a solid run of
 * characters, and the ceiling still has to hold.
 */
it('hard cuts text with no boundaries in it and says so', function (): void {
    $blocks = segment(str_repeat('abcdefghij', 400));

    expect(count($blocks))->toBeGreaterThan(1)
        ->and($blocks[0]->hardCut)->toBeTrue();

    foreach ($blocks as $block) {
        expect(strlen($block->text))->toBeLessThanOrEqual($this->tokens->contentCharacterCeiling());
    }
});

/**
 * A cut inside a multibyte character produces invalid UTF-8, which Postgres
 * rejects outright -- and this corpus is full of "Curaçao".
 */
it('never cuts inside a character, even on a hard cut', function (): void {
    $blocks = segment(str_repeat('Curaçao', 400));

    foreach ($blocks as $block) {
        expect(mb_check_encoding($block->text, 'UTF-8'))->toBeTrue();
    }
});

/**
 * A part can be denser than the paragraph average it was sized by, so every
 * part is re-checked rather than trusted.
 */
it('keeps every part of a token-dense paragraph inside the token ceiling', function (): void {
    $blocks = segment(trim(str_repeat('1/2 Curaçao 1/4 Bénédictine 3/8 Gin 1/8 Absinthe. ', 120)));

    foreach ($blocks as $block) {
        expect($this->tokens->estimate($block->text))
            ->toBeLessThanOrEqual($this->tokens->contentTokenCeiling());
    }
});

it('only gives the heading to the first part of a split paragraph', function (): void {
    $blocks = segment("BRANDY PUNCH FOR A PARTY 1/2 pint of brandy.\n"
        .trim(str_repeat('Stir the mixture gently and then serve it out to the company at once. ', 40)));

    expect($blocks[0]->heading)->not->toBeNull();

    foreach (array_slice($blocks, 1) as $block) {
        expect($block->heading)->toBeNull();
    }
});
