<?php

namespace App\Console\Commands\Books;

use App\Enums\PageStatus;
use App\Models\Book;
use App\Services\Books\BookChunker;
use App\Services\Books\ChunkingReport;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cuts extracted pages into page-anchored chunks for retrieval.
 *
 * Nothing here shells out to anything, so the parallel-wave machinery that
 * books:extract needs does not apply: the whole corpus is 5.5 MB of text and
 * chunks in seconds. A reader who knows books:extract will look for the pool,
 * which is why this says so.
 */
class ChunkCommand extends Command
{
    protected $signature = 'books:chunk
        {--book=* : Slug of a book to chunk; repeatable, defaults to all}
        {--force : Re-chunk books already at the current versions}
        {--dry-run : Report what would be written without touching the database}';

    protected $description = 'Cut extracted pages into overlapping, page-anchored chunks for retrieval';

    public function handle(BookChunker $chunker): int
    {
        // A second concurrent run would delete the rows the first one is still
        // writing, so it is turned away rather than allowed to interleave.
        $lock = Cache::lock('books:chunk', 30 * 60);

        if (! $lock->get()) {
            $this->error('Another books:chunk run is already in progress.');

            return self::FAILURE;
        }

        try {
            return $this->chunk($chunker);
        } finally {
            $lock->release();
        }
    }

    private function chunk(BookChunker $chunker): int
    {
        $books = $this->resolveBooks();

        if ($books->isEmpty()) {
            $this->error('No matching books with extracted pages. Run `books:extract` first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $skipped = 0;
        $reports = [];

        $bar = $this->output->createProgressBar($books->count());
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
        $bar->setMessage('');
        $bar->start();

        foreach ($books as $book) {
            $bar->setMessage($book->slug);

            if (! $this->option('force') && ! $chunker->isStale($book)) {
                $skipped++;
                $bar->advance();

                continue;
            }

            $report = $dryRun
                ? $chunker->plan($book)[2]
                : $chunker->chunkBook($book);

            $reports[] = $report;
            $rows[] = $this->row($book, $report);
            $bar->advance();
        }

        $bar->setMessage('');
        $bar->finish();
        $this->newLine(2);

        if ($rows === []) {
            $this->info('Everything is already at chunker version '.BookChunker::VERSION.'.');

            return self::SUCCESS;
        }

        $this->table(
            ['Book', 'Strategy', 'Sections', 'Chunks', 'Indexable', 'Median chars', 'Max tokens', 'Hard cuts', 'No label', 'Coverage'],
            $rows,
        );

        return $this->summarise($reports, $skipped, $dryRun);
    }

    /**
     * Report the totals, and fail the run if any promise was broken.
     *
     * These are assertions rather than statistics. Chunking that loses content,
     * overruns the embedding window, or produces a chunk that cannot say where
     * it came from is a defect, and the exit code should say so rather than
     * leaving it in a table for someone to notice.
     *
     * @param  list<ChunkingReport>  $reports
     */
    private function summarise(array $reports, int $skipped, bool $dryRun): int
    {
        $chunks = array_sum(array_map(fn (ChunkingReport $r): int => $r->chunkCount, $reports));
        $indexable = array_sum(array_map(fn (ChunkingReport $r): int => $r->indexableCount, $reports));
        $covered = array_sum(array_map(fn (ChunkingReport $r): int => $r->coverageChars, $reports));
        $content = array_sum(array_map(fn (ChunkingReport $r): int => $r->streamChars, $reports));
        $hardCuts = array_sum(array_map(fn (ChunkingReport $r): int => $r->hardCuts, $reports));
        $estimated = array_sum(array_map(fn (ChunkingReport $r): int => $r->estimatedLabels, $reports));
        $unlabelled = array_sum(array_map(fn (ChunkingReport $r): int => $r->unlabelled, $reports));
        $overCeiling = array_sum(array_map(
            fn (ChunkingReport $r): int => $r->maxTokens > (int) config('books.chunking.max_tokens') ? 1 : 0,
            $reports,
        ));

        $this->line(sprintf(
            '%d book(s), %s chunk(s), %s indexable (%.1f%%).%s',
            count($reports),
            number_format($chunks),
            number_format($indexable),
            $chunks > 0 ? ($indexable / $chunks) * 100 : 0.0,
            $skipped > 0 ? " {$skipped} already current." : '',
        ));

        $complete = $covered === $content;

        $this->line(sprintf(
            'Coverage: %s of %s content characters (%s) — %s',
            number_format($covered),
            number_format($content),
            $content > 0 ? number_format(($covered / $content) * 100, 2).'%' : '—',
            $complete ? 'no content dropped.' : '<fg=red>content was dropped.</>',
        ));

        $this->line(sprintf(
            '%s book(s) over the %d-token ceiling. %s hard cut(s).',
            $overCeiling > 0 ? "<fg=red>{$overCeiling}</>" : '0',
            (int) config('books.chunking.max_tokens'),
            $hardCuts > 0 ? "<fg=red>{$hardCuts}</>" : '0',
        ));

        $this->line(sprintf(
            '%s chunk(s) cite an interpolated printed page (marked ~); %s cite the PDF page only.',
            number_format($estimated),
            number_format($unlabelled),
        ));

        if ($dryRun) {
            $this->newLine();
            $this->line('<fg=gray>Dry run; nothing was written.</>');
        }

        return $complete && $hardCuts === 0 && $overCeiling === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @return array<int, string>
     */
    private function row(Book $book, ChunkingReport $report): array
    {
        $ceiling = (int) config('books.chunking.max_tokens');
        $coverage = number_format($report->coverage() * 100, 2).'%';

        return [
            $book->slug,
            $report->strategy->value,
            (string) $report->sectionCount,
            (string) $report->chunkCount,
            $report->indexableCount === $report->chunkCount
                ? (string) $report->indexableCount
                : "<fg=yellow>{$report->indexableCount}</>",
            (string) $report->medianChars,
            $report->maxTokens > $ceiling ? "<fg=red>{$report->maxTokens}</>" : (string) $report->maxTokens,
            $report->hardCuts > 0 ? "<fg=red>{$report->hardCuts}</>" : '0',
            $report->unlabelled > 0 ? "<fg=yellow>{$report->unlabelled}</>" : '0',
            $report->isComplete() ? $coverage : "<fg=red>{$coverage}</>",
        ];
    }

    /**
     * @return Collection<int, Book>
     */
    private function resolveBooks()
    {
        $slugs = array_filter((array) $this->option('book'));

        return Book::query()
            ->when($slugs !== [], fn ($query) => $query->whereIn('slug', $slugs))
            // Resolved on page state rather than book status: a book is
            // chunkable when it has text, whatever its extraction run concluded.
            ->whereHas('pages', fn ($query) => $query->where('status', PageStatus::Extracted))
            ->orderBy('year')
            ->get();
    }
}
