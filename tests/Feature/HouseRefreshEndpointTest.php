<?php

use App\Http\Middleware\VerifyBarKey;
use App\Jobs\RefreshHouse;
use App\Models\HouseChunk;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

/**
 * The site's redeploy hook: a 202 straight away, and the refresh after it.
 *
 * The requests below are not Bus-faked unless a test says so, so the job runs
 * for real when the test kernel terminates -- the same afterResponse path it
 * takes in production, against the faked site and the fixture export.
 */
beforeEach(function (): void {
    config()->set('bar.api.keys', ['the-house-key']);
    config()->set('house.fetch_url', 'https://thekrausshaus.test/data');
    config()->set('house.refresh.wait_attempts', 3);

    $this->directory = sys_get_temp_dir().'/house-refresh-endpoint-'.bin2hex(random_bytes(4));
    useHouseFixture($this->directory);

    $this->checksum = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/House/manifest.json'), true)['checksum'];

    Sleep::fake();
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

function requestHouseRefresh(array $payload = []): TestResponse
{
    return test()->postJson('/api/house/refresh', $payload, [VerifyBarKey::HEADER => 'the-house-key']);
}

function embeddedHouseChunks(): int
{
    return HouseChunk::where('is_indexable', true)->whereNotNull('embedding')->count();
}

it('answers 202 at once and leaves the refresh until after the response', function (): void {
    Bus::fake();

    requestHouseRefresh(['checksum' => $this->checksum])
        ->assertAccepted()
        ->assertExactJson(['message' => 'Refreshing the house catalog.']);

    Bus::assertDispatchedAfterResponse(
        RefreshHouse::class,
        fn (RefreshHouse $job): bool => $job->checksum === $this->checksum,
    );
});

it('fetches, imports and embeds the house export once the response is sent', function (): void {
    fakeHouseSite();
    fakeEmbeddings();

    requestHouseRefresh(['checksum' => $this->checksum])->assertAccepted();

    expect(embeddedHouseChunks())->toBeGreaterThan(0)
        ->and(HouseChunk::where('is_indexable', true)->whereNull('embedding')->count())->toBe(0);

    Sleep::assertNeverSlept();
});

it('refreshes straight away when no checksum is given', function (): void {
    fakeHouseSite();
    fakeEmbeddings();

    requestHouseRefresh()->assertAccepted();

    expect(embeddedHouseChunks())->toBeGreaterThan(0);
    Sleep::assertNeverSlept();
});

/**
 * The reason the checksum exists. The site calls as it boots, while the public
 * URL can still reach the pod being replaced -- so the old export is served
 * first, and the refresh must not fetch until the new one is.
 */
it('waits for the site to serve the checksum it was told about', function (): void {
    Http::fake([
        'thekrausshaus.test/data/manifest.json' => Http::sequence()
            ->push(['checksum' => str_repeat('0', 64)])
            ->whenEmpty(Http::response((string) file_get_contents(__DIR__.'/../Fixtures/House/manifest.json'))),
    ]);
    fakeHouseSite();
    fakeEmbeddings();

    requestHouseRefresh(['checksum' => $this->checksum])->assertAccepted();

    Sleep::assertSleptTimes(1);
    expect(embeddedHouseChunks())->toBeGreaterThan(0);
});

it('refreshes from what the site serves when the checksum never arrives', function (): void {
    Log::spy();
    fakeHouseSite();
    fakeEmbeddings();

    requestHouseRefresh(['checksum' => str_repeat('a', 64)])->assertAccepted();

    Sleep::assertSleptTimes(2);
    expect(embeddedHouseChunks())->toBeGreaterThan(0);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'never served')
            && $context['expected_checksum'] === str_repeat('a', 64),
    );
});

it('logs a failed refresh rather than throwing it at a response already sent', function (): void {
    Log::spy();
    Http::fake(['*' => Http::response('Not Found', 404)]);
    fakeEmbeddings();

    requestHouseRefresh()->assertAccepted();

    expect(HouseChunk::count())->toBe(0);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $message === 'house:refresh failed.'
            && str_contains($context['output'], 'stopped at `house:fetch`'),
    );
});

it('runs one refresh at a time however often it is asked', function (): void {
    Bus::fake();

    requestHouseRefresh(['checksum' => $this->checksum])->assertAccepted();
    requestHouseRefresh(['checksum' => str_repeat('b', 64)])->assertAccepted();

    Bus::assertDispatchedTimes(RefreshHouse::class, 1);
});

it('rejects a checksum that is not a sha256', function (string $checksum): void {
    Bus::fake();

    requestHouseRefresh(['checksum' => $checksum])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('checksum');

    Bus::assertNothingDispatched();
})->with([
    'too short' => ['abc123'],
    'uppercase' => [strtoupper(str_repeat('ab', 32))],
    'not hex' => [str_repeat('z', 64)],
]);

it('refuses a caller without the key', function (): void {
    Bus::fake();

    test()->postJson('/api/house/refresh')->assertUnauthorized();
    test()->postJson('/api/house/refresh', [], [VerifyBarKey::HEADER => 'not-the-key'])->assertUnauthorized();

    Bus::assertNothingDispatched();
});
