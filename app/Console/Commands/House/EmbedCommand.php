<?php

namespace App\Console\Commands\House;

use App\Models\HouseChunk;
use App\Services\House\HouseEmbedder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Embeds the house chunks into the vector column.
 *
 * The same server, model and width the books use -- one TEI container serves
 * one model -- with the corpus's own batch size. Unlike books:embed this is not
 * an hours-long run to plan around: 207 chunks is a handful of requests. The
 * lock is still here because two concurrent runs would pay twice for the same
 * vectors, but its TTL is minutes rather than half a day.
 */
class EmbedCommand extends Command
{
    protected $signature = 'house:embed
        {--force : Re-embed chunks that already have a current vector}
        {--batch= : Chunks per request to the embedding provider}
        {--dry-run : Report what would be embedded without calling the provider}
        {--verify : Check the embedded corpus against its invariants and exit}';

    protected $description = 'Embed the house chunks into pgvector with the same local model the books use';

    public function handle(HouseEmbedder $embedder): int
    {
        if ($this->option('verify')) {
            return $this->verify($embedder);
        }

        $lock = Cache::lock('house:embed', 30 * 60);

        if (! $lock->get()) {
            $this->error('Another house:embed run is already in progress.');
            $this->line('<fg=gray>If the last run was killed rather than interrupted, clear the stale lock with:</>');
            $this->line('<fg=gray>  php artisan tinker --execute \'Cache::lock("house:embed")->forceRelease();\'</>');

            return self::FAILURE;
        }

        try {
            return $this->embed($embedder);
        } finally {
            $lock->release();
        }
    }

    private function embed(HouseEmbedder $embedder): int
    {
        $force = (bool) $this->option('force');
        $batchSize = $this->option('batch') === null
            ? $embedder->batchSize()
            : max(1, (int) $this->option('batch'));

        $indexable = HouseChunk::query()->where('is_indexable', true)->count();

        if ($indexable === 0) {
            $this->error('No house chunks to embed. Run `house:import` first.');

            return self::FAILURE;
        }

        $pending = $embedder->pending($force)->count();

        $this->newLine();
        $this->line(sprintf(
            '<fg=gray>%s at %d dimensions, via the %s provider, %d chunk(s) per request.</>',
            $embedder->model(),
            $embedder->dimensions(),
            $embedder->provider(),
            $batchSize,
        ));

        if ($pending === 0) {
            $this->newLine();
            $this->info('Every house chunk already carries a current vector.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line(sprintf('%d of %d chunk(s) would be embedded.', $pending, $indexable));
            $this->newLine();
            $this->line('<fg=gray>Dry run; the provider was not called and nothing was written.</>');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($pending);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $bar->start();

        try {
            $report = $embedder->embed($force, $batchSize, fn (int $done): mixed => $bar->advance($done));
        } catch (Throwable $exception) {
            $bar->finish();
            $this->newLine(2);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->line(sprintf(
            '%d of %d pending chunk(s) embedded in %d batch(es), %.1fs (%.1f chunk(s)/s).%s',
            $report->embeddedCount,
            $report->pendingCount,
            $report->batchCount,
            $report->seconds,
            $report->rate(),
            $report->tokens > 0 ? ' '.number_format($report->tokens).' token(s).' : '',
        ));

        $this->newLine();
        $this->line('<fg=gray>Run `house:embed --verify` to check the corpus against its invariants.</>');

        return $report->isComplete() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Report every way the embedded corpus could be wrong.
     */
    private function verify(HouseEmbedder $embedder): int
    {
        $embedded = HouseChunk::query()->whereNotNull('embedding')->count();
        $indexable = HouseChunk::query()->where('is_indexable', true)->count();

        $this->newLine();
        $this->line(sprintf(
            '%d of %d indexable chunk(s) embedded by %s at %d dimensions (embedder v%d).',
            $embedded,
            $indexable,
            $embedder->model(),
            $embedder->dimensions(),
            HouseEmbedder::VERSION,
        ));

        $failures = $embedder->verify();

        $this->newLine();

        if ($failures === []) {
            $this->info('Verified: every invariant holds.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->line("  <fg=red>✗</> {$failure}");
        }

        $this->newLine();
        $this->error(count($failures).' invariant(s) broken.');

        return self::FAILURE;
    }
}
