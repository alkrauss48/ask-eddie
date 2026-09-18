<?php

use App\Models\HouseChunk;
use App\Services\House\HouseEmbedder;
use Symfony\Component\Console\Exception\InvalidOptionException;

beforeEach(function (): void {
    useHouseFixture();
});

it('says what to run when nothing is imported', function (): void {
    $this->artisan('house:status')
        ->expectsOutputToContain('Run `house:import`')
        ->assertFailed();
});

it('reports the catalog against the export and the chunks against the embeddings', function (): void {
    importHouse();

    $this->artisan('house:status')
        ->expectsOutputToContain('8 chunk(s) at renderer v1')
        ->assertSuccessful();

    // Asserted off the embedder rather than off the output, because the count
    // the command prints and the count house:embed acts on have to be the same
    // number -- which is the thing worth pinning.
    expect(app(HouseEmbedder::class)->pending()->count())->toBe(8);
});

/**
 * Deliberately no --verify of its own: a third verifier unioning the other two
 * would drift out of sync the first time an invariant was added to only one,
 * and the drift would be a green run that checked less than it used to.
 */
it('points at the two real verifiers rather than repeating them', function (): void {
    importHouse();

    $this->artisan('house:status')
        ->expectsOutputToContain('`house:import --verify` and `house:embed --verify`')
        ->assertSuccessful();

    expect(fn () => $this->artisan('house:status', ['--verify' => true]))
        ->toThrow(InvalidOptionException::class);
});

it('flags chunks left behind by an older renderer', function (): void {
    importHouse();

    HouseChunk::firstWhere('source_slug', 'midnight-rambler')->update(['renderer_version' => 0]);

    $this->artisan('house:status')
        ->expectsOutputToContain('rendered by an older renderer')
        ->assertSuccessful();
});
