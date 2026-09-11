<?php

use App\Enums\ChunkStrategy;
use App\Enums\SectionKind;
use App\Services\Books\BlockSegmenter;
use App\Services\Books\BookTextStream;
use App\Services\Books\ChunkPacker;
use App\Services\Books\DetectedSection;
use App\Services\Books\DetectedStructure;
use App\Services\Books\HeadEdge;
use App\Services\Books\HeadingPatterns;
use App\Services\Books\PageSpan;
use App\Services\Books\PendingChunk;
use App\Services\Books\TokenEstimator;

beforeEach(function (): void {
    $this->tokens = new TokenEstimator;
    $this->segmenter = new BlockSegmenter(new HeadingPatterns, $this->tokens);
    $this->packer = new ChunkPacker($this->tokens);
});

function streamOf(string $text): BookTextStream
{
    return new BookTextStream($text, [new PageSpan(1, 0, strlen($text))], hash('sha256', $text));
}

function structureOf(ChunkStrategy $strategy): DetectedStructure
{
    return new DetectedStructure(
        [new DetectedSection(null, SectionKind::Body, 1, 99)],
        [],
        HeadEdge::None,
        $strategy,
    );
}

/**
 * @return list<PendingChunk>
 */
function packText(string $text, ChunkStrategy $strategy = ChunkStrategy::Packing): array
{
    $stream = streamOf($text);

    return test()->packer->pack($stream, test()->segmenter->segment($stream), $strategy, structureOf($strategy));
}

function recipeBook(int $count): string
{
    $names = ['BLUE LADY', 'BLUE PETER', 'BLUE RIBAND', 'BOBBY BURNS', 'BRANDY SMASH',
        'CORPSE REVIVER', 'GIN SLING', 'MINT JULEP', 'PORTER SANGAREE', 'WHISKEY TODDY'];
    $recipes = [];

    for ($i = 0; $i < $count; $i++) {
        $name = $names[$i % count($names)].' '.str_repeat('X', intdiv($i, count($names)) + 1);
        $recipes[] = "{$name} 1/2 Booth's Dry Gin.\n1/4 Blue Curasao, Bols.\n1/4 Orange Bitters.\nShake and strain.";
    }

    return implode("\n\n", $recipes);
}

function proseBook(int $paragraphs): string
{
    $text = [];

    for ($i = 0; $i < $paragraphs; $i++) {
        $text[] = "This is paragraph number {$i} of a narrative about the old bar. It runs on for a while, "
            .'describing the men who drank there and the drinks they called for, and it does so in complete '
            .'sentences so that the sentence boundary logic has something real to find.';
    }

    return implode("\n\n", $text);
}

/**
 * The Python original's MAX_CHARS was a packing threshold, so a single long
 * "sentence" produced an oversized chunk that the embedding model then
 * truncated -- silently, with the chunk still indexed on the strength of text
 * nobody had read.
 */
it('never emits a chunk over the character ceiling', function (): void {
    $chunks = packText(proseBook(40));
    $ceiling = (int) config('books.chunking.max_chars');

    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect(strlen($chunk->text))->toBeLessThanOrEqual($ceiling);
    }
});

it('never emits a chunk over the token ceiling', function (): void {
    $chunks = packText(recipeBook(120));

    foreach ($chunks as $chunk) {
        expect($this->tokens->estimate($chunk->text))
            ->toBeLessThanOrEqual($this->tokens->tokenCeiling());
    }
});

/**
 * Text with no whitespace at all cannot be split at a boundary, and the ceiling
 * still has to hold.
 */
it('holds the ceiling even against text with no boundaries in it', function (): void {
    $chunks = packText(str_repeat('abcdefghij', 600));
    $ceiling = (int) config('books.chunking.max_chars');

    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect(strlen($chunk->text))->toBeLessThanOrEqual($ceiling);
    }
});

it('always advances, so packing terminates', function (): void {
    $chunks = packText(proseBook(30));

    for ($i = 1; $i < count($chunks); $i++) {
        expect($chunks[$i]->start)->toBeGreaterThan($chunks[$i - 1]->start);
    }
});

/**
 * The core promise of the phase, in its machine-checkable form: chunk content,
 * with overlap excluded, accounts for every non-whitespace character.
 */
it('accounts for every content character of its input', function (): void {
    $text = proseBook(25);
    $chunks = packText($text);

    $covered = '';
    $cursor = 0;

    foreach ($chunks as $chunk) {
        $start = max($chunk->contentStart(), $cursor);

        if ($chunk->end > $start) {
            $covered .= substr($text, $start, $chunk->end - $start);
            $cursor = $chunk->end;
        }
    }

    $strip = fn (string $value): string => preg_replace('/\s+/u', '', $value) ?? $value;

    expect($strip($covered))->toBe($strip($text));
});

/**
 * A tail borrowed from the previous chunk would put a neighbouring drink's
 * ingredients under this chunk's citation, which is the precise failure this
 * phase exists to prevent.
 */
it('gives recipe chunks no overlap so a drink is never half-quoted', function (): void {
    $chunks = packText(recipeBook(60), ChunkStrategy::Headings);

    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        if ($chunk->recipeHeadings > 0) {
            expect($chunk->overlapChars)->toBe(0);
        }
    }
});

it('overlaps prose chunks with the text immediately before them', function (): void {
    $text = proseBook(30);
    $chunks = packText($text);

    $overlapping = array_values(array_filter($chunks, fn (PendingChunk $c): bool => $c->overlapChars > 0));

    expect($overlapping)->not->toBeEmpty();

    foreach ($overlapping as $chunk) {
        // The overlap is a slice of the stream at this chunk's own start
        // offset, so splicing in text from elsewhere in the book -- which the
        // Python original could do, by taking its overlap from the last
        // *accepted* chunk -- is not expressible here.
        expect(substr($chunk->text, 0, $chunk->overlapChars))
            ->toBe(substr($text, $chunk->start, $chunk->overlapChars));
    }
});

it('never reaches back past the previous chunk when overlapping', function (): void {
    $chunks = packText(proseBook(30));

    for ($i = 1; $i < count($chunks); $i++) {
        expect($chunks[$i]->contentStart())->toBeGreaterThan($chunks[$i - 1]->contentStart());
    }
});

/**
 * Under the heading strategy a chunk may only begin at a heading, which is what
 * keeps a recipe whole.
 */
it('starts every chunk at a heading under the heading strategy', function (): void {
    $chunks = packText(recipeBook(60), ChunkStrategy::Headings);

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect($chunk->heading)->not->toBeNull();
    }
});

it('packs several whole recipes into one chunk', function (): void {
    $chunks = packText(recipeBook(60), ChunkStrategy::Headings);

    expect($chunks[0]->recipeHeadings)->toBeGreaterThan(1)
        ->and($chunks[0]->headings)->toHaveCount($chunks[0]->recipeHeadings);
});

it('prefers a paragraph boundary to a word boundary', function (): void {
    $chunks = packText(proseBook(30));

    // Every chunk's own content should begin at the start of a sentence rather
    // than in the middle of a clause.
    foreach ($chunks as $chunk) {
        $content = ltrim(substr($chunk->text, $chunk->overlapChars));

        if ($content !== '') {
            expect($content)->toMatch('/^(This is paragraph|[A-Z])/');
        }
    }
});

/**
 * The Python discarded any chunk under twenty characters, which in this corpus
 * means discarding "Gin." and "1/2".
 */
it('keeps a fragment too small to merge rather than dropping it', function (): void {
    $chunks = packText('Gin.');

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->text)->toBe('Gin.');
});

it('folds a short trailing fragment into its neighbour', function (): void {
    $chunks = packText(proseBook(2)."\n\nGin.");

    $last = $chunks[count($chunks) - 1];

    expect($last->text)->toEndWith('Gin.')
        ->and(strlen($last->text))->toBeGreaterThan(4);
});
