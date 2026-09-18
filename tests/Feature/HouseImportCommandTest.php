<?php

use App\Models\HouseChunk;
use App\Models\HouseCocktail;
use App\Models\HouseCocktailIngredient;
use App\Models\HouseCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    useHouseFixture();
});

it('imports the export and reports what it wrote', function (): void {
    $this->artisan('house:import')
        ->expectsOutputToContain('chunk(s) at renderer v1')
        ->assertSuccessful();

    expect(HouseCocktail::count())->toBe(3)
        ->and(HouseChunk::count())->toBe(8);
});

it('writes nothing on a dry run', function (): void {
    $this->artisan('house:import', ['--dry-run' => true])
        ->expectsOutputToContain('rolled back')
        ->assertSuccessful();

    expect(HouseCocktail::count())->toBe(0)
        ->and(HouseChunk::count())->toBe(0);
});

it('says so plainly when a second run changes nothing', function (): void {
    $this->artisan('house:import')->assertSuccessful();

    $this->artisan('house:import')
        ->expectsOutputToContain('Nothing moved.')
        ->assertSuccessful();
});

/**
 * The export is a file on a disk that may not be mounted, and the failure has
 * to name the thing the operator can actually change.
 */
it('fails with the missing files and the variable to set', function (): void {
    useHouseFixture(sys_get_temp_dir().'/house-nothing-here');

    $this->artisan('house:import')
        ->expectsOutputToContain('cocktails.json')
        ->expectsOutputToContain('HOUSE_PATH')
        ->assertFailed();
});

it('verifies a sound catalog', function (): void {
    $this->artisan('house:import')->assertSuccessful();

    $this->artisan('house:import', ['--verify' => true])
        ->expectsOutputToContain('every invariant holds')
        ->assertSuccessful();
});

/**
 * The invariant that is specific to Sasha's job. A cocktail with no tags is
 * invisible to every structured filter -- unrecommendable by anyone, silently,
 * while looking perfectly fine in the table.
 */
it('fails verification for a drink no facet can find', function (): void {
    $this->artisan('house:import')->assertSuccessful();

    HouseCocktail::firstWhere('slug', 'gin-basil-smash')->tags()->detach();

    $this->artisan('house:import', ['--verify' => true])
        ->expectsOutputToContain('carry no tags')
        ->expectsOutputToContain('gin-basil-smash')
        ->assertFailed();
});

it('fails verification for a drink that cannot be built', function (): void {
    $this->artisan('house:import')->assertSuccessful();

    HouseCocktail::firstWhere('slug', 'gin-basil-smash')->cocktailIngredients()->delete();

    $this->artisan('house:import', ['--verify' => true])
        ->expectsOutputToContain('have no ingredients')
        ->assertFailed();
});

/**
 * Exactly one of the two must be set. A row with neither is a pour of nothing,
 * and it renders as a blank line in the middle of a recipe.
 */
it('fails verification for an ingredient line that names nothing', function (): void {
    $this->artisan('house:import')->assertSuccessful();

    HouseCocktailIngredient::query()
        ->whereNotNull('house_ingredient_id')
        ->first()
        ->update(['house_ingredient_id' => null, 'free_text' => null]);

    $this->artisan('house:import', ['--verify' => true])
        ->expectsOutputToContain('name neither an ingredient nor any text')
        ->assertFailed();
});

it('fails verification for a menu with nothing on it', function (): void {
    $this->artisan('house:import')->assertSuccessful();

    HouseCollection::firstWhere('slug', 'spring')->cocktails()->detach();

    $this->artisan('house:import', ['--verify' => true])
        ->expectsOutputToContain('list no cocktails')
        ->assertFailed();
});

/**
 * The checksum is taken over the raw bytes the exporter wrote, so an edit that
 * never went through `npm run export:data` is caught before it is believed.
 */
it('fails verification when the export files no longer match their manifest', function (): void {
    $directory = sys_get_temp_dir().'/house-export-'.bin2hex(random_bytes(4));
    File::copyDirectory(__DIR__.'/../Fixtures/House', $directory);
    useHouseFixture($directory);

    $this->artisan('house:import')->assertSuccessful();

    $recipes = json_decode((string) file_get_contents("{$directory}/recipes.json"), true);
    $recipes[0]['notes'] = 'Edited by hand, which is the thing this catches.';
    file_put_contents("{$directory}/recipes.json", json_encode($recipes, JSON_PRETTY_PRINT)."\n");

    $this->artisan('house:import', ['--verify' => true])
        ->expectsOutputToContain('the files on disk hash to')
        ->assertFailed();

    File::deleteDirectory($directory);
});

/**
 * A re-render that nobody imported is a chunk citing a URL for text the site no
 * longer has. There is no byte offset to check it against, so the check is that
 * a re-render reproduces the stored hash.
 */
it('fails verification when a chunk no longer matches a re-render', function (): void {
    $this->artisan('house:import')->assertSuccessful();

    HouseChunk::firstWhere('source_slug', 'midnight-rambler')
        ->update(['text' => 'Something the renderer would never produce.']);

    $this->artisan('house:import', ['--verify' => true])
        ->expectsOutputToContain('no longer match a re-render')
        ->assertFailed();
});

/**
 * The stored hash is what house:embed reads to decide whether a vector is
 * current, so a row whose hash no longer describes its own text is one that
 * will never be re-embedded however far its passage has drifted.
 */
it('fails verification when a chunk\'s hash no longer describes its own text', function (): void {
    $this->artisan('house:import')->assertSuccessful();

    HouseChunk::firstWhere('source_slug', 'midnight-rambler')
        ->update(['content_hash' => hash('sha256', 'a hash of something else')]);

    $this->artisan('house:import', ['--verify' => true])
        ->expectsOutputToContain('does not describe their own text')
        ->assertFailed();
});

it('turns away a second concurrent run', function (): void {
    $lock = Cache::lock('house:import', 60);
    $lock->get();

    $this->artisan('house:import')
        ->expectsOutputToContain('already in progress')
        ->assertFailed();

    $lock->release();
});
