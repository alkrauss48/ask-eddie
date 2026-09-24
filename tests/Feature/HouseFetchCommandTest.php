<?php

use App\Services\House\HouseExport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Serve the fixture export as the site would, from /data.
 *
 * @param  array<string, string>  $overrides  file contents keyed by dataset name
 */
function fakeHouseSite(array $overrides = []): void
{
    $responses = [];

    foreach ([...HouseExport::DATASETS, 'manifest'] as $name) {
        $responses["thekrausshaus.test/data/{$name}.json"] = Http::response(
            $overrides[$name] ?? (string) file_get_contents(__DIR__."/../Fixtures/House/{$name}.json"),
        );
    }

    Http::fake($responses);
}

beforeEach(function (): void {
    config()->set('house.fetch_url', 'https://thekrausshaus.test/data');

    $this->directory = sys_get_temp_dir().'/house-fetch-'.bin2hex(random_bytes(4));
    useHouseFixture($this->directory);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('downloads the export onto the house disk', function (): void {
    fakeHouseSite();

    $this->artisan('house:fetch')
        ->expectsOutputToContain('Fetched 8 files')
        ->assertSuccessful();

    expect(app(HouseExport::class)->isAvailable())->toBeTrue()
        ->and(file_get_contents($this->directory.'/cocktails.json'))
        ->toBe(file_get_contents(__DIR__.'/../Fixtures/House/cocktails.json'));

    $this->artisan('house:import')->assertSuccessful();
});

it('skips the download when the local export already matches the site', function (): void {
    fakeHouseSite();

    $this->artisan('house:fetch')->assertSuccessful();

    $this->artisan('house:fetch')
        ->expectsOutputToContain('already matches the site')
        ->assertSuccessful();

    Http::assertSentCount(count(HouseExport::DATASETS) + 1 + 1);
});

/**
 * A download caught mid-deploy mixes two exports, and importing it would
 * produce a catalog that neither of them describes.
 */
it('writes nothing when the files do not reproduce the manifest checksum', function (): void {
    fakeHouseSite(['cocktails' => '[]']);

    $this->artisan('house:fetch')
        ->expectsOutputToContain('do not reproduce the manifest checksum')
        ->assertFailed();

    expect(is_dir($this->directory))->toBeFalse();
});

it('fails when the site does not serve a file', function (): void {
    Http::fake(['*' => Http::response('Not Found', 404)]);

    $this->artisan('house:fetch')
        ->expectsOutputToContain('404')
        ->assertFailed();
});
