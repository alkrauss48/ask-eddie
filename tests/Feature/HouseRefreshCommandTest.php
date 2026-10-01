<?php

use App\Models\HouseChunk;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('house.fetch_url', 'https://thekrausshaus.test/data');

    $this->directory = sys_get_temp_dir().'/house-refresh-'.bin2hex(random_bytes(4));
    useHouseFixture($this->directory);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('fetches, imports, verifies and embeds the house export', function (): void {
    fakeHouseSite();
    fakeEmbeddings();

    $this->artisan('house:refresh')
        ->expectsOutputToContain('Fetched 8 files')
        ->expectsOutputToContain('Verified: every invariant holds.')
        ->expectsOutputToContain('The house catalog is current and embedded.')
        ->assertSuccessful();

    expect(HouseChunk::where('is_indexable', true)->count())->toBeGreaterThan(0)
        ->and(HouseChunk::where('is_indexable', true)->whereNull('embedding')->count())->toBe(0);
});

it('stops before importing when the fetch fails', function (): void {
    Http::fake(['*' => Http::response('Not Found', 404)]);
    fakeEmbeddings();

    $this->artisan('house:refresh')
        ->expectsOutputToContain('house:refresh stopped at `house:fetch`')
        ->assertFailed();

    expect(HouseChunk::count())->toBe(0);
});

it('downloads from the given base URL', function (): void {
    config()->set('house.fetch_url', 'https://elsewhere.test/data');
    fakeHouseSite();
    fakeEmbeddings();

    $this->artisan('house:refresh', ['--from' => 'https://thekrausshaus.test/data/'])->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://thekrausshaus.test/data/manifest.json');
});
