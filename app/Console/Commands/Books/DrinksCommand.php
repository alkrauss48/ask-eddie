<?php

namespace App\Console\Commands\Books;

use App\Models\Book;
use App\Models\Drink;
use App\Services\Books\DrinkExtractionReport;
use App\Services\Books\DrinkExtractor;
use App\Services\Books\DrinkNameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Tallies the drinks the corpus prints, so a question about the shelf as a
 * whole is a query rather than a guess.
 *
 * A run is seconds: it re-scans stored chunk text and writes two tables, with
 * nothing shelled out and no inference. That is why the lock's TTL is
 * books:chunk's half hour rather than books:embed's twelve, and why there is no
 * stale-lock incantation printed here -- that exists because a nine-hour embed
 * can be OOM-killed, and this cannot.
 */
class DrinksCommand extends Command
{
    protected $signature = 'books:drinks
        {--book=* : Slug of a book to extract; repeatable, defaults to all}
        {--force : Re-extract books already at the current versions}
        {--dry-run : Report what would be written without touching the database}
        {--verify : Check the drink layer against its invariants and exit}
        {--merges : List every merge the clusterer made, for review}
        {--top= : Show the drinks the most books print, and the shape of the tail}
        {--show= : Inspect one drink, its spellings and where it was printed}';

    protected $description = 'Tally the drinks the books print, and where each one was printed';

    public function handle(DrinkExtractor $extractor): int
    {
        if ($this->option('verify')) {
            return $this->verify($extractor);
        }

        // Presence, not value: `--top` is VALUE_OPTIONAL, so a bare flag reads
        // as null and would otherwise be indistinguishable from an absent one.
        if ($this->input->hasParameterOption('--top')) {
            return $this->top((int) $this->option('top') ?: 20);
        }

        if ($this->option('show') !== null) {
            return $this->show((string) $this->option('show'));
        }

        // A second run would delete the mentions the first is still writing.
        $lock = Cache::lock('books:drinks', 30 * 60);

        if (! $lock->get()) {
            $this->error('Another books:drinks run is already in progress.');

            return self::FAILURE;
        }

        try {
            return $this->extract($extractor);
        } finally {
            $lock->release();
        }
    }

    private function extract(DrinkExtractor $extractor): int
    {
        $books = $this->resolveBooks();

        if ($books->isEmpty()) {
            $this->error('No matching books with chunks. Run `books:chunk` first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $reports = [];
        $skipped = 0;
        $merges = [];

        $bar = $this->output->createProgressBar($books->count());
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
        $bar->setMessage('');
        $bar->start();

        foreach ($books as $book) {
            $bar->setMessage($book->slug);

            if (! $this->option('force') && ! $extractor->isStale($book)) {
                $skipped++;
                $bar->advance();

                continue;
            }

            $report = $dryRun
                ? $extractor->plan($book)[1]
                : $extractor->extractBook($book);

            $reports[] = [$book, $report];
            $rows[] = $this->row($book, $report);
            $merges = array_merge($merges, $report->mergedKeys);
            $bar->advance();
        }

        $bar->setMessage('');
        $bar->finish();
        $this->newLine(2);

        if ($rows === []) {
            $this->info('Everything is already at extractor version '.DrinkExtractor::VERSION.'.');

            return self::SUCCESS;
        }

        if (! $dryRun) {
            // Aggregates are rewritten once, after every book has been seen,
            // because a drink's book count is not knowable one book at a time.
            $extractor->recomputeAggregates();
        }

        $this->table(
            ['Book', 'Strategy', 'Chunks', 'Mentions', 'Drinks', 'Per page', 'Divisions'],
            $rows,
        );

        return $this->summarise($extractor, $reports, $skipped, $merges, $dryRun);
    }

    /**
     * Report the totals, and say plainly what the tally could not reach.
     *
     * The coverage line is the point of this method. The narrative books print
     * few drink headings -- the same measurement that put them on the packing
     * path -- so a tally drawn from this layer is a claim about the books it
     * could count, and it has to say which those were. Without that, "what
     * comes up time and again" silently means "across the recipe books".
     *
     * @param  list<array{0: Book, 1: DrinkExtractionReport}>  $reports
     * @param  list<string>  $merges
     */
    private function summarise(
        DrinkExtractor $extractor,
        array $reports,
        int $skipped,
        array $merges,
        bool $dryRun,
    ): int {
        $mentions = array_sum(array_map(fn (array $pair): int => $pair[1]->mentionCount, $reports));
        $divisions = array_sum(array_map(fn (array $pair): int => $pair[1]->stopHeadingCount, $reports));

        $silent = array_values(array_map(
            fn (array $pair): string => $pair[0]->slug,
            array_filter($reports, fn (array $pair): bool => $pair[1]->contributedNothing()),
        ));

        $this->line("  {$mentions} mention(s) across ".count($reports).' book(s).');
        $this->line("  {$divisions} heading(s) set aside as divisions of a book rather than drinks.");

        if ($skipped > 0) {
            $this->line("  {$skipped} book(s) already current.");
        }

        if ($silent !== []) {
            $this->newLine();
            $this->warn('  '.count($silent).' book(s) contributed no drink names:');

            foreach ($silent as $slug) {
                $this->line("    {$slug}");
            }

            $this->line('  These are the narrative books. A tally is a claim about the rest.');
        }

        if ($merges !== [] && ! $this->option('merges')) {
            $this->newLine();
            $this->warn('  '.count($merges).' spelling(s) were merged. Review them with `books:drinks --merges`.');
        }

        if ($this->option('merges')) {
            $this->newLine();
            $this->line('  Merges, for review:');

            foreach ($merges as $merge) {
                $this->line("    {$merge}");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('  Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $coverage = $extractor->coverage();

        $this->newLine();
        $this->line("  {$coverage['drinks']} drink(s) across {$coverage['counted']} of {$coverage['total']} book(s); "
            ."nine parts in ten of the count come from {$coverage['carrying']} of them.");
        $shape = $this->distribution();

        $this->line(sprintf(
            '  %s printed in one book only, %s in two to four, %s in five to nine, %s in ten or more.',
            number_format($shape['one']),
            number_format($shape['few']),
            number_format($shape['several']),
            number_format($shape['many']),
        ));
        $this->line('  Run `books:drinks --top` to read the head of the tally, `--verify` to check its invariants.');

        return self::SUCCESS;
    }

    private function verify(DrinkExtractor $extractor): int
    {
        $failures = $extractor->verify();
        $coverage = $extractor->coverage();

        $this->line("  {$coverage['drinks']} drink(s) across {$coverage['counted']} of {$coverage['total']} book(s); "
            ."nine parts in ten of the count come from {$coverage['carrying']} of them.");

        if ($coverage['silent'] !== []) {
            $this->line('  no mentions: '.implode(', ', $coverage['silent']));
        }

        if ($failures === []) {
            $this->newLine();
            $this->info('  Every invariant holds.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($failures as $failure) {
            $this->line("  <fg=red>✗</> {$failure}");
        }

        return self::FAILURE;
    }

    /**
     * The head of the tally, and the shape of its tail.
     *
     * The distribution is the number that decides whether any of this answers
     * the question it was built for. A corpus where nearly every name is
     * printed once has no "comes up time and time again" to report -- it has a
     * catalogue of one-off house drinks, which is a true and much less useful
     * thing to say.
     */
    private function top(int $limit): int
    {
        $drinks = Drink::query()
            ->orderByDesc('book_count')
            ->orderByDesc('mention_count')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($drinks->isEmpty()) {
            $this->error('Nothing tallied yet. Run `books:drinks` first.');

            return self::FAILURE;
        }

        $this->table(
            ['#', 'Drink', 'Books', 'Mentions', 'Years', 'Also printed as'],
            $drinks->values()->map(fn (Drink $drink, int $index): array => [
                $index + 1,
                $drink->canonical_name,
                $drink->book_count,
                $drink->mention_count,
                $drink->yearRange(),
                implode(', ', array_slice($drink->otherSpellings(), 0, 3)),
            ])->all(),
        );

        $shape = $this->distribution();
        $total = array_sum($shape);

        $this->line(sprintf(
            '  %s drink(s): %s printed in one book only (%.0f%%), %s in two to four, %s in five to nine, %s in ten or more.',
            number_format($total),
            number_format($shape['one']),
            $total > 0 ? ($shape['one'] / $total) * 100 : 0.0,
            number_format($shape['few']),
            number_format($shape['several']),
            number_format($shape['many']),
        ));

        return self::SUCCESS;
    }

    /**
     * How many drinks are printed in one book, a few, several, or many.
     *
     * One grouped query rather than four counts, because this runs at the end
     * of every real extraction.
     *
     * @return array{one: int, few: int, several: int, many: int}
     */
    private function distribution(): array
    {
        $row = Drink::query()
            ->selectRaw('count(*) filter (where book_count <= 1) as one')
            ->selectRaw('count(*) filter (where book_count between 2 and 4) as few')
            ->selectRaw('count(*) filter (where book_count between 5 and 9) as several')
            ->selectRaw('count(*) filter (where book_count >= 10) as many')
            ->first();

        return [
            'one' => (int) ($row->one ?? 0),
            'few' => (int) ($row->few ?? 0),
            'several' => (int) ($row->several ?? 0),
            'many' => (int) ($row->many ?? 0),
        ];
    }

    private function show(string $name): int
    {
        $key = app(DrinkNameNormalizer::class)->key($name);
        $drink = Drink::query()->where('canonical_key', $key)->first();

        if ($drink === null) {
            $this->error("No drink folds to \"{$key}\".");

            return self::FAILURE;
        }

        $this->line("  <options=bold>{$drink->canonical_name}</>");
        $this->line("  {$drink->mention_count} mention(s) in {$drink->book_count} book(s), {$drink->yearRange()}");

        $spellings = $drink->otherSpellings();

        if ($spellings !== []) {
            $this->line('  also printed as: '.implode(', ', $spellings));
        }

        $this->newLine();

        foreach ($drink->mentions()->limit(20)->get() as $mention) {
            $this->line("    {$mention->raw_heading} — {$mention->citation()}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string|int|float>
     */
    private function row(Book $book, DrinkExtractionReport $report): array
    {
        return [
            $book->slug,
            $report->strategy->label(),
            $report->chunkCount,
            $report->mentionCount,
            $report->drinkCount,
            number_format($report->density(), 2),
            $report->stopHeadingCount,
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
            // Resolved on chunks rather than book status: this layer reads
            // chunk text and nothing else, so a book is extractable exactly
            // when it has been cut.
            ->whereHas('chunks')
            ->orderBy('year')
            ->get();
    }
}
