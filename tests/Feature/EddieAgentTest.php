<?php

use App\Agents\EddieAgent;
use App\Tools\SearchTheBooks;
use App\Tools\SurveyTheBooks;

it('keeps both the search and the tally behind the bar', function (): void {
    $tools = collect(app(EddieAgent::class)->tools())
        ->map(fn (object $tool): string => $tool::class)
        ->all();

    expect($tools)->toEqualCanonicalizing([SearchTheBooks::class, SurveyTheBooks::class]);
});

/**
 * The tally can support a claim about how often a drink was printed and can
 * never support one about how good anyone thought it was, because no book in
 * this corpus rates a drink. The instructions have to draw that line, or Eddie
 * will put a judgement in a book's mouth as fluently as he does everything else.
 */
it('tells Eddie the tally counts printings rather than opinions', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('# Your tally')
        ->and($instructions)->toContain('not one of these books rates a drink')
        ->and($instructions)->toContain('as your own opinion');
});

/**
 * Coverage is the difference between a true sentence and a false one: the
 * narrative books print no drink headings, so a tally is a claim about the
 * books it could count.
 */
it('tells Eddie to believe the coverage the tally reports', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('"most of my books" is fair and "all my books" is not');
});

it('still forbids a citation that did not come back from a tool', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('from a search or a tally you just ran')
        ->and($instructions)->toContain('An invented citation is worse than no citation');
});
