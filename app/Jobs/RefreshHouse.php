<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Runs `house:refresh` on the site's behalf, after the site has been told yes.
 *
 * Dispatched with afterResponse() rather than onto the queue, because nothing
 * works the queue: production's QUEUE_CONNECTION is `database` and no pod runs
 * `queue:work`, so a queued refresh would sit in the jobs table for ever. After
 * the response it runs in the same FrankenPHP thread once the 202 has been
 * flushed, which is also why it lifts the time limit -- php.ini's 120 seconds
 * is sized for one answer, and a full re-embed takes minutes.
 *
 * The site calls this as it boots, and that is exactly when it is not yet the
 * site: during a rollout the public URL still reaches the old pod, so a fetch
 * made now would download the export being replaced. So the caller may name
 * the manifest checksum it is about to serve, and the refresh waits until the
 * site actually serves it. Waiting gives up rather than failing -- it refreshes
 * from whatever the site serves by then, which is at worst a refresh that
 * changes nothing, and at best the newer deploy that replaced this one.
 *
 * Unique on a constant rather than on the checksum, so at most one refresh is
 * ever holding a thread: the import and embed steps each take their own lock,
 * and a second refresh racing the first would only fail on them.
 */
class RefreshHouse implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How long the unique lock outlives a run that never released it -- a pod
     * killed mid-refresh. A completed run releases it straight away.
     */
    public int $uniqueFor = 30 * 60;

    public function __construct(public ?string $checksum = null) {}

    public function uniqueId(): string
    {
        return 'house:refresh';
    }

    public function handle(): void
    {
        set_time_limit(0);

        if ($this->checksum !== null && ! $this->siteServesChecksum()) {
            Log::warning('house:refresh: the site never served the expected export; refreshing from what it serves now.', [
                'expected_checksum' => $this->checksum,
            ]);
        }

        $exitCode = Artisan::call('house:refresh');
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            Log::error('house:refresh failed.', ['output' => $output]);

            return;
        }

        Log::info('house:refresh finished.', ['output' => $output]);
    }

    /**
     * Polls the live manifest until it carries the checksum the caller named.
     */
    private function siteServesChecksum(): bool
    {
        $attempts = max(1, (int) config('house.refresh.wait_attempts'));
        $manifestUrl = config('house.fetch_url').'/manifest.json';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $live = Http::timeout(10)->get($manifestUrl)->json('checksum');
            } catch (Throwable) {
                $live = null;
            }

            if (is_string($live) && hash_equals($this->checksum, $live)) {
                return true;
            }

            if ($attempt < $attempts) {
                Sleep::for((int) config('house.refresh.wait_seconds'))->seconds();
            }
        }

        return false;
    }
}
