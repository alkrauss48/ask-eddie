<?php

use App\Enums\HouseCollectionKind;
use App\Enums\HouseSourceType;
use App\Models\HouseChunk;
use App\Models\HouseCocktail;
use App\Models\HouseCocktailIngredient;
use App\Models\HouseCollection;
use App\Models\HouseIngredient;
use App\Models\HouseRecipe;
use App\Services\House\HouseImporter;
use Illuminate\Support\Facades\File;

/**
 * Copy the fixture export somewhere writable and point the house disk at it.
 *
 * Used by the tests that need to change the export and import again, which is
 * the only way to exercise "a drink came off the menu" without waiting for the
 * site to actually change.
 */
function mutableHouseExport(): string
{
    $directory = sys_get_temp_dir().'/house-export-'.bin2hex(random_bytes(4));

    File::copyDirectory(__DIR__.'/../Fixtures/House', $directory);
    useHouseFixture($directory);

    return $directory;
}

/**
 * Rewrite one dataset file in place, keeping the manifest honest about it.
 *
 * @param  callable(array<int, mixed>): array<int, mixed>  $mutate
 */
function rewriteHouseDataset(string $directory, string $name, callable $mutate): void
{
    $path = "{$directory}/{$name}.json";
    $records = json_decode((string) file_get_contents($path), true);

    file_put_contents($path, json_encode(
        $mutate($records),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    )."\n");
}

it('imports every record in the export', function (): void {
    importHouse();

    expect(HouseCocktail::count())->toBe(3)
        ->and(HouseIngredient::count())->toBe(5)
        ->and(HouseRecipe::count())->toBe(1)
        ->and(HouseCollection::where('kind', HouseCollectionKind::Menu)->count())->toBe(1)
        ->and(HouseCollection::where('kind', HouseCollectionKind::Path)->count())->toBe(1);
});

/**
 * The union in the source data is real, and both halves have to survive it.
 *
 * Flattening "8 basil leaves" into a fake ingredient row would invent a catalog
 * member the site does not have -- and every structured query would then be
 * able to find it.
 */
it('keeps free-text ingredient lines as text rather than inventing a catalog entry', function (): void {
    importHouse();

    $lines = HouseCocktail::firstWhere('slug', 'gin-basil-smash')->cocktailIngredients;

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->house_ingredient_id)->not->toBeNull()
        ->and($lines[0]->free_text)->toBeNull()
        ->and($lines[1]->house_ingredient_id)->toBeNull()
        ->and($lines[1]->free_text)->toBe('8 basil leaves')
        ->and(HouseIngredient::where('title', 'like', '%basil%')->exists())->toBeFalse();
});

it('preserves the order a drink is built in', function (): void {
    importHouse();

    $lines = HouseCocktail::firstWhere('slug', 'midnight-rambler')
        ->cocktailIngredients
        ->map(fn (HouseCocktailIngredient $line): string => $line->line())
        ->all();

    expect($lines)->toBe([
        '2oz Rye Whiskey',
        '.5oz Blackberry Syrup',
        'Garnish: Lemon twist',
    ]);
});

/**
 * The site's own label wins, because it is written for the build ("Garnish:
 * Lemon twist") rather than for the catalog ("Lemon Garnish").
 */
it('prefers the site label over the catalog title on a line that has one', function (): void {
    importHouse();

    $garnish = HouseCocktail::firstWhere('slug', 'midnight-rambler')->cocktailIngredients->last();

    expect($garnish->label)->toBe('Garnish: Lemon twist')
        ->and($garnish->ingredient->title)->toBe('Lemon Garnish')
        ->and($garnish->line())->toBe('Garnish: Lemon twist');
});

it('links an ingredient to the recipe that makes it', function (): void {
    importHouse();

    expect(HouseIngredient::firstWhere('slug', 'blackberry-syrup')->recipe->name)
        ->toBe('Blackberry Syrup');
});

/**
 * A tag's identity is its label plus its category, because the site gives it no
 * slug and nothing else can separate "Rum" the base spirit from any other use
 * of the word.
 */
it('identifies a tag by its label and its category together', function (): void {
    importHouse();

    $tags = HouseCocktail::firstWhere('slug', 'midnight-rambler')
        ->tags
        ->map(fn ($tag): string => $tag->category_label.'/'.$tag->label)
        ->sort()
        ->values()
        ->all();

    expect($tags)->toBe(['Base Alcohol/Whiskey', 'Flavor Profile/Fruity', 'Technique/Stirred']);
});

/**
 * A menu drink can sit in a section and in the featured list at once, and both
 * facts are true -- which is why is_featured is part of the pivot's unique key.
 */
it('lets one drink be both sectioned and featured on the same menu', function (): void {
    importHouse();

    $menu = HouseCollection::firstWhere('slug', 'spring');

    $rambler = $menu->cocktails->where('slug', 'midnight-rambler');

    expect($rambler)->toHaveCount(2)
        ->and($rambler->pluck('pivot.is_featured')->sort()->values()->all())->toBe([false, true])
        ->and($rambler->firstWhere('pivot.is_featured', false)->pivot->section_title)->toBe('Dark');
});

it('keeps menus and flights in one table', function (): void {
    importHouse();

    $onBoth = HouseCocktail::firstWhere('slug', 'midnight-rambler')
        ->collections
        ->map(fn (HouseCollection $collection): string => $collection->kind->value.':'.$collection->slug)
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($onBoth)->toBe(['menu:spring', 'path:the-ramble']);
});

it('renders one chunk per record and none for an ingredient', function (): void {
    importHouse();

    $counts = HouseChunk::query()
        ->selectRaw('source_type, count(*) as total')
        ->groupBy('source_type')
        ->pluck('total', 'source_type')
        ->all();

    ksort($counts);

    expect($counts)->toBe([
        'bartender' => 2,
        'cocktail' => 3,
        'menu' => 1,
        'path' => 1,
        'recipe' => 1,
    ])
        ->and(HouseChunk::count())->toBe(8)
        ->and(HouseChunk::where('source_type', 'ingredient')->exists())->toBeFalse();
});

/**
 * A recipe is chunked; the 28 ingredients that point at one are not. The recipe
 * covers them, and they have no URL of their own to cite.
 */
it('chunks a recipe but not the ingredient it produces', function (): void {
    importHouse();

    expect(HouseChunk::where('source_slug', 'blackberry-syrup')->where('source_type', 'recipe')->exists())
        ->toBeTrue()
        ->and(HouseChunk::where('source_slug', 'rye-whiskey')->exists())->toBeFalse();
});

it('cites an absolute URL on the public site', function (): void {
    importHouse();

    expect(HouseChunk::firstWhere('source_slug', 'mai-tai'))->toBeNull()
        ->and(HouseChunk::firstWhere('source_slug', 'midnight-rambler')->url)
        ->toBe('https://thekrausshaus.com/cocktails/midnight-rambler');
});

/**
 * The whole point of a second run: it must write nothing at all.
 *
 * Without this, "did anything change" is unanswerable, and a `house:import`
 * that reports a change means nothing because every run reports one.
 */
it('writes nothing on a second run', function (): void {
    importHouse();

    $second = importHouse();

    expect($second->wroteAnything())->toBeFalse()
        ->and($second->total('created'))->toBe(0)
        ->and($second->total('updated'))->toBe(0)
        ->and($second->total('deleted'))->toBe(0)
        ->and($second->total('unchanged'))->toBeGreaterThan(0);
});

it('rewrites everything when forced', function (): void {
    importHouse();

    expect(importHouse(force: true)->wroteAnything())->toBeTrue();
});

/**
 * A chunk's text depends on more than its own record. A cocktail added to a
 * menu has not changed, and its passage has -- which is why staleness is
 * measured on the rendered chunk rather than on the source record's hash.
 */
it('re-renders a drink whose menu membership changed even though the drink did not', function (): void {
    $directory = mutableHouseExport();
    app(HouseImporter::class)->import();

    $before = HouseChunk::query()
        ->where('source_type', HouseSourceType::Cocktail)
        ->where('source_slug', 'hot-buttered-rum')
        ->value('content_hash');

    rewriteHouseDataset($directory, 'menus', function (array $menus): array {
        $menus[0]['sections'][] = ['title' => 'Warm', 'cocktail_slugs' => ['hot-buttered-rum']];

        return $menus;
    });

    $report = app(HouseImporter::class)->import();

    $chunk = HouseChunk::query()
        ->where('source_type', HouseSourceType::Cocktail)
        ->where('source_slug', 'hot-buttered-rum')
        ->first();

    expect($report->wroteAnything())->toBeTrue()
        ->and($chunk->content_hash)->not->toBe($before)
        ->and($chunk->text)->toContain('Spring Menu')
        // The drink's own record never moved.
        ->and(HouseCocktail::firstWhere('slug', 'hot-buttered-rum')->wasChanged())->toBeFalse();

    File::deleteDirectory($directory);
});

it('removes a drink that came off the site, and its chunk with it', function (): void {
    $directory = mutableHouseExport();
    app(HouseImporter::class)->import();

    rewriteHouseDataset($directory, 'cocktails', fn (array $cocktails): array => array_values(
        array_filter($cocktails, fn (array $cocktail): bool => $cocktail['slug'] !== 'hot-buttered-rum')
    ));

    app(HouseImporter::class)->import();

    expect(HouseCocktail::where('slug', 'hot-buttered-rum')->exists())->toBeFalse()
        ->and(HouseChunk::where('source_slug', 'hot-buttered-rum')->exists())->toBeFalse()
        ->and(HouseCocktail::count())->toBe(2);

    File::deleteDirectory($directory);
});

/**
 * A dangling reference is refused rather than imported as a gap. A menu that
 * lists a drink the export does not contain is a menu Sasha would read a round
 * short of, with nothing to say so.
 */
it('refuses an export whose menu lists a drink that does not exist', function (): void {
    $directory = mutableHouseExport();

    rewriteHouseDataset($directory, 'menus', function (array $menus): array {
        $menus[0]['sections'][0]['cocktail_slugs'][] = 'a-drink-we-never-made';

        return $menus;
    });

    expect(fn () => app(HouseImporter::class)->import())
        ->toThrow(RuntimeException::class, 'a-drink-we-never-made');

    File::deleteDirectory($directory);
});

it('refuses an export whose drink is credited to a bartender that does not exist', function (): void {
    $directory = mutableHouseExport();

    rewriteHouseDataset($directory, 'cocktails', function (array $cocktails): array {
        $cocktails[0]['bartender_slug'] = 'nobody-at-all';

        return $cocktails;
    });

    expect(fn () => app(HouseImporter::class)->import())
        ->toThrow(RuntimeException::class, 'nobody-at-all');

    File::deleteDirectory($directory);
});

/**
 * The whole run is one transaction, so a reference that fails halfway leaves no
 * half-applied catalog behind.
 */
it('leaves nothing behind when an import fails partway', function (): void {
    $directory = mutableHouseExport();

    rewriteHouseDataset($directory, 'menus', function (array $menus): array {
        $menus[0]['sections'][0]['cocktail_slugs'][] = 'a-drink-we-never-made';

        return $menus;
    });

    try {
        app(HouseImporter::class)->import();
    } catch (RuntimeException) {
        // The point of the test is what is in the database afterwards.
    }

    expect(HouseCocktail::count())->toBe(0)
        ->and(HouseIngredient::count())->toBe(0)
        ->and(HouseChunk::count())->toBe(0);

    File::deleteDirectory($directory);
});

it('writes nothing on a dry run, and reports the counts a real run would', function (): void {
    useHouseFixture();

    $planned = app(HouseImporter::class)->plan();

    expect(HouseCocktail::count())->toBe(0)
        ->and(HouseChunk::count())->toBe(0)
        ->and($planned->total('created'))->toBe(importHouse()->total('created'));
});
