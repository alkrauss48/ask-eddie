<?php

use App\Services\Books\DrinkClassification;
use App\Services\Books\DrinkClassifier;
use App\Services\Books\DrinkEvidence;
use App\Services\Books\DrinkNameNormalizer;
use App\Services\Books\HeadingPatterns;

function classifier(): DrinkClassifier
{
    return new DrinkClassifier(new DrinkNameNormalizer(new HeadingPatterns));
}

function verdictFor(string $name, DrinkEvidence $evidence): DrinkClassification
{
    $key = (new DrinkNameNormalizer(new HeadingPatterns))->key($name);

    return classifier()->classify($name, $key, $evidence);
}

function printedIn(int $books, bool $numbered = false): DrinkEvidence
{
    return new DrinkEvidence(bookCount: $books, mentionCount: $books, numberedHeading: $numbered);
}

/**
 * The rule that carries the tail. 7,011 of the first real run's 9,437 rows were
 * printed in exactly one book, and that is where the OCR wreckage lives.
 */
it('sets aside a name only one book printed', function (): void {
    $verdict = verdictFor('Caucliois', printedIn(1));

    expect($verdict->isCountable)->toBeFalse()
        ->and($verdict->reason)->toBe('single_book');
});

it('counts a name two books printed independently', function (): void {
    $verdict = verdictFor('Shandy Gaff', printedIn(2));

    expect($verdict->isCountable)->toBeTrue()
        ->and($verdict->reason)->toBe('printed_in_books');
});

/**
 * Short and odd-looking is not the same as noise. These are the names a density
 * or word-shape heuristic would take first, and every one of them is a drink
 * this corpus prints in more than twenty books.
 */
it('counts short real names rather than judging them by shape', function (string $name): void {
    expect(verdictFor($name, printedIn(27))->isCountable)->toBeTrue();
})->with(['Bishop', 'Shandy Gaff', 'Stone Fence', 'Black Stripe', 'Flip']);

/**
 * A book that numbered its own recipes is the best evidence available that the
 * thing numbered was one, so it is tested before any rule about shape.
 */
it('counts a numbered recipe whatever its name looks like', function (): void {
    $verdict = verdictFor('Juice of one Lime', printedIn(3, numbered: true));

    expect($verdict->isCountable)->toBeTrue()
        ->and($verdict->reason)->toBe('numbered_recipe');
});

it('sets aside an ingredient line a title pattern caught', function (string $name): void {
    $verdict = verdictFor($name, printedIn(6));

    expect($verdict->isCountable)->toBeFalse()
        ->and($verdict->reason)->toBe('ingredient_line');
})->with(['White of one egg', 'Yolk of an egg', 'Juice of one Lime', 'One egg', 'Two lemons']);

/**
 * The anchor matters: these read as ingredient lines to a loose pattern and are
 * drinks this corpus prints.
 */
it('leaves a drink whose name merely contains an ingredient alone', function (string $name): void {
    expect(verdictFor($name, printedIn(6))->isCountable)->toBeTrue();
})->with(['Egg Phosphate', 'Brandy Egg Nogg', 'Lime Juice Cordial', 'Egg Nog']);

it('sets aside a sentence a heading pattern caught', function (): void {
    $verdict = verdictFor('Israel Hatch announced daily stages between the two', printedIn(2));

    expect($verdict->isCountable)->toBeFalse()
        ->and($verdict->reason)->toBe('sentence_fragment');
});

it('sets aside a name that is mostly not letters', function (): void {
    $verdict = verdictFor('13u 157 2', printedIn(3));

    expect($verdict->isCountable)->toBeFalse()
        ->and($verdict->reason)->toBe('ocr_artefact');
});

it('sets aside a division heading', function (): void {
    $verdict = verdictFor('PUNCHES', printedIn(20));

    expect($verdict->isCountable)->toBeFalse()
        ->and($verdict->reason)->toBe('division_heading');
});

/**
 * "This" is in six books and "Bishop" in twenty-seven, and nothing structural
 * separates them -- which is why the list is config and not a heuristic.
 */
it('sets aside a configured noise heading however many books printed it', function (): void {
    config()->set('books.drinks.classification.noise_headings', ['this']);

    $verdict = verdictFor('This', printedIn(6));

    expect($verdict->isCountable)->toBeFalse()
        ->and($verdict->reason)->toBe('noise_heading');
});

it('records the measurements behind every verdict', function (): void {
    $signals = verdictFor('Gin Fizz', new DrinkEvidence(
        bookCount: 28,
        mentionCount: 30,
        recipeShare: 0.89,
        capsPrefixShare: 0.1,
        headingFamilies: 4,
    ))->signals;

    expect($signals)->toHaveKeys([
        'book_count', 'mention_count', 'recipe_share', 'caps_prefix_share',
        'heading_families', 'numbered_heading', 'words', 'letter_ratio',
    ])->and($signals['recipe_share'])->toBe(0.89);
});

/**
 * Recorded and deliberately not acted on. Below a quarter of mentions in recipe
 * chunks sit "Gothic Punch", "Bilberry Cordial" and "Hock Cobbler" -- real
 * drinks this shelf happens to print only inside prose -- so a threshold here
 * costs more drinks than it buys.
 */
it('counts a drink this shelf prints only inside prose', function (): void {
    $verdict = verdictFor('Gothic Punch', new DrinkEvidence(
        bookCount: 3,
        mentionCount: 3,
        recipeShare: 0.0,
    ));

    expect($verdict->isCountable)->toBeTrue()
        ->and($verdict->signals['recipe_share'])->toBe(0.0);
});

/**
 * A proposal for review, never a verdict: the same shape catches "Kummel",
 * "Cooler" and "Tequila", which are drinks.
 */
it('proposes a single word seen only as a paragraph opening, and still counts it', function (): void {
    $opener = new DrinkEvidence(bookCount: 5, capsPrefixShare: 1.0);

    // Kummel is a drink and reads exactly like "There" by this measure, which
    // is the whole reason the shape proposes rather than decides.
    expect(classifier()->looksLikeSentenceOpener('Kummel', $opener))->toBeTrue()
        ->and(verdictFor('Kummel', $opener)->isCountable)->toBeTrue();
});

it('does not propose a name printed in more than one heading family', function (): void {
    $mixed = new DrinkEvidence(bookCount: 5, capsPrefixShare: 0.4);

    expect(classifier()->looksLikeSentenceOpener('Kummel', $mixed))->toBeFalse();
});

it('does not propose a name of more than one word', function (): void {
    $opener = new DrinkEvidence(bookCount: 5, capsPrefixShare: 1.0);

    expect(classifier()->looksLikeSentenceOpener('Gin Fizz', $opener))->toBeFalse();
});
