<?php

namespace App\Console\Commands\Books;

use App\Models\Book;
use App\Models\BookChunk;
use App\Services\Books\ChunkEmbedder;
use App\Services\Embedding\EmbeddingReport;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Embeds indexable chunks into the vector column.
 *
 * The counterpart of books:chunk, but resumable rather than wholesale: a vector
 * belongs to one chunk and is written by an id-keyed update, so interrupting
 * this loses at most one batch. Re-running picks up exactly the chunks that
 * still need a vector, which is also what makes a model change a command rather
 * than a manual purge -- a row records the model, width and embedder version
 * that produced it, and a row disagreeing with configuration is pending again.
 *
 * The initial bulk run is hours of CPU. Embed one small book first and read the
 * rate off the summary before committing to the corpus.
 */
class EmbedCommand extends Command
{
    protected $signature = 'books:embed
        {--book=* : Slug of a book to embed; repeatable, defaults to all}
        {--force : Re-embed chunks that already have a current vector}
        {--batch= : Chunks per request to the embedding provider}
        {--dry-run : Report what would be embedded without calling the provider}
        {--verify : Check the embedded corpus against its invariants and exit}';

    protected $description = 'Embed indexable chunks into pgvector with the configured local model';

    public function handle(ChunkEmbedder $embedder): int
    {
        if ($this->option('verify')) {
            return $this->verify($embedder);
        }

        // A second run would embed the same chunks twice and pay for it in
        // hours, so it is turned away rather than allowed to interleave. The
        // TTL has to outlive a full corpus run, which is why it is this long.
        $lock = Cache::lock('books:embed', 12 * 60 * 60);

        if (! $lock->get()) {
            $this->error('Another books:embed run is already in progress.');

            // A run killed outright -- OOM, or a SIGKILL -- never reaches the
            // release below, and the lock then blocks every retry for the rest
            // of its TTL. That is a long time to work out unaided, so the way
            // out is printed rather than left to be discovered.
            $this->line('<fg=gray>If the last run was killed rather than interrupted, clear the stale lock with:</>');
            $this->line('<fg=gray>  php artisan tinker --execute \'Cache::lock("books:embed")->forceRelease();\'</>');

            return self::FAILURE;
        }

        try {
            return $this->embed($embedder);
        } finally {
            $lock->release();
        }
    }

    private function embed(ChunkEmbedder $embedder): int
    {
        $slugs = array_values(array_filter((array) $this->option('book')));
        $force = (bool) $this->option('force');
        $batchSize = $this->option('batch') === null
            ? (int) config('books.embedding.batch_size')
            : max(1, (int) $this->option('batch'));

        $books = $this->resolveBooks($slugs);

        if ($books->isEmpty()) {
            $this->error('No matching books with chunks. Run `books:chunk` first.');

            return self::FAILURE;
        }

        $pending = $embedder->pending($slugs, $force)->count();

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
            $this->info('Every indexable chunk already carries a current vector.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($embedder, $books, $slugs, $force, $pending);
        }

        $bar = $this->output->createProgressBar($pending);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
        $bar->setMessage('');
        $bar->start();

        $rows = [];
        $reports = [];
        $failures = [];

        foreach ($books as $book) {
            $bar->setMessage($book->slug);

            try {
                $report = $embedder->embedBook($book, $force, $batchSize, fn (int $done): mixed => $bar->advance($done));
            } catch (Throwable $exception) {
                // One book's provider error must not discard the batches every
                // other book already committed.
                $failures[] = "{$book->slug}: {$exception->getMessage()}";

                continue;
            }

            if ($report->pendingCount === 0) {
                continue;
            }

            $reports[] = $report;
            $rows[] = $this->row($book, $report);
        }

        $bar->setMessage('');
        $bar->finish();
        $this->newLine(2);

        if ($rows !== []) {
            $this->table(['Book', 'Indexable', 'Pending', 'Embedded', 'Batches', 'Seconds', 'Chunks/s'], $rows);
        }

        return $this->summarise($embedder, $reports, $failures);
    }

    /**
     * @param  Collection<int, Book>  $books
     * @param  list<string>  $slugs
     */
    private function dryRun(ChunkEmbedder $embedder, Collection $books, array $slugs, bool $force, int $pending): int
    {
        $rows = $books->map(fn (Book $book): array => [
            $book->slug,
            (string) $book->chunks()->where('is_indexable', true)->count(),
            (string) $embedder->pending([$book->slug], $force)->count(),
        ])->filter(fn (array $row): bool => $row[2] !== '0')->values()->all();

        $this->newLine();
        $this->table(['Book', 'Indexable', 'Pending'], $rows);
        $this->line(sprintf('%s chunk(s) would be embedded.', number_format($pending)));
        $this->newLine();
        $this->line('<fg=gray>Dry run; the provider was not called and nothing was written.</>');

        return self::SUCCESS;
    }

    /**
     * @param  list<EmbeddingReport>  $reports
     * @param  list<string>  $failures
     */
    private function summarise(ChunkEmbedder $embedder, array $reports, array $failures): int
    {
        $embedded = array_sum(array_map(fn (EmbeddingReport $r): int => $r->embeddedCount, $reports));
        $pending = array_sum(array_map(fn (EmbeddingReport $r): int => $r->pendingCount, $reports));
        $seconds = array_sum(array_map(fn (EmbeddingReport $r): float => $r->seconds, $reports));
        $tokens = array_sum(array_map(fn (EmbeddingReport $r): int => $r->tokens, $reports));

        $this->line(sprintf(
            '%d book(s), %s of %s pending chunk(s) embedded in %s.%s',
            count($reports),
            number_format($embedded),
            number_format($pending),
            $this->duration($seconds),
            $tokens > 0 ? ' '.number_format($tokens).' token(s).' : '',
        ));

        // The number that matters before a full corpus run: the rest of the
        // corpus costs this rate, and nothing about the run gets faster later.
        if ($embedded > 0 && $seconds > 0) {
            $remaining = $embedder->pending()->count();

            $this->line(sprintf(
                '%.1f chunk(s)/s.%s',
                $embedded / $seconds,
                $remaining > 0
                    ? sprintf(
                        ' %s chunk(s) still pending — about %s at this rate.',
                        number_format($remaining),
                        $this->duration($remaining / ($embedded / $seconds)),
                    )
                    : ' Nothing left pending.',
            ));
        }

        if ($failures !== []) {
            $this->newLine();

            foreach ($failures as $failure) {
                $this->line("  <fg=red>✗</> {$failure}");
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<fg=gray>Run `books:embed --verify` to check the corpus against its invariants.</>');

        return $embedded === $pending ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Report every way the embedded corpus could be wrong.
     */
    private function verify(ChunkEmbedder $embedder): int
    {
        $embedded = BookChunk::query()->whereNotNull('embedding')->count();
        $indexable = BookChunk::query()->where('is_indexable', true)->count();

        $this->newLine();
        $this->line(sprintf(
            '%s of %s indexable chunk(s) embedded by %s at %d dimensions (embedder v%d).',
            number_format($embedded),
            number_format($indexable),
            $embedder->model(),
            $embedder->dimensions(),
            ChunkEmbedder::VERSION,
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

    /**
     * @return array<int, string>
     */
    private function row(Book $book, EmbeddingReport $report): array
    {
        return [
            $book->slug,
            (string) $report->indexableCount,
            (string) $report->pendingCount,
            $report->isComplete()
                ? (string) $report->embeddedCount
                : "<fg=red>{$report->embeddedCount}</>",
            (string) $report->batchCount,
            number_format($report->seconds, 1),
            number_format($report->rate(), 1),
        ];
    }

    private function duration(float $seconds): string
    {
        if ($seconds < 90) {
            return number_format($seconds, 1).'s';
        }

        return $seconds < 5400
            ? number_format($seconds / 60, 1).'m'
            : number_format($seconds / 3600, 1).'h';
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, Book>
     */
    private function resolveBooks(array $slugs): Collection
    {
        return Book::query()
            ->when($slugs !== [], fn ($query) => $query->whereIn('slug', $slugs))
            ->whereHas('chunks', fn ($query) => $query->where('is_indexable', true))
            ->orderBy('year')
            ->get();
    }
}
