<?php

namespace App\Console\Commands\Books;

use App\Enums\SectionKind;
use App\Models\Book;
use App\Models\BookSection;
use App\Services\Books\BookChunker;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Reads a book's chunks back, and proves they are what they claim to be.
 *
 * The analogue of books:compare for this phase. --verify is the payoff for
 * storing byte offsets on every chunk: it re-assembles the book and checks each
 * chunk against the text it says it came from, which turns "chunking never
 * loses a line" from a statement in a rules file into a check over the real
 * corpus. --payload prints exactly what the retrieval layer will hand the
 * model, which is worth being able to read before any of that is written.
 */
class ChunksCommand extends Command
{
    protected $signature = 'books:chunks
        {book : Slug of the book to inspect}
        {--outline : Show the detected section structure instead of chunks}
        {--page= : Only chunks covering this physical page}
        {--kind=* : Only these chunk kinds}
        {--excluded : Only chunks that will not be embedded}
        {--search= : Only chunks whose text contains this string}
        {--limit=8 : How many chunks to show}
        {--verify : Re-assemble the book and check every chunk against its offsets}
        {--payload : Print each chunk\'s retrieval payload}';

    protected $description = "Inspect a book's chunks, its detected outline, or verify both against the source text";

    public function handle(BookChunker $chunker): int
    {
        $book = Book::where('slug', $this->argument('book'))->first();

        if ($book === null) {
            $this->error("No book with slug {$this->argument('book')}.");

            return self::FAILURE;
        }

        if ($this->option('outline')) {
            return $this->outline($book);
        }

        if ($this->option('verify')) {
            return $this->verify($book, $chunker);
        }

        return $this->show($book);
    }

    /**
     * The structure the detector found, including what it removed.
     */
    private function outline(Book $book): int
    {
        $sections = $book->sections()->get();

        if ($sections->isEmpty()) {
            $this->warn("No sections recorded. Run `books:chunk --book={$book->slug}`.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<options=bold>{$book->title}</> ({$book->year}) — {$book->page_count} pages");

        $state = $book->metadata['chunking'] ?? [];

        if ($state !== []) {
            $this->line(sprintf(
                '  <fg=gray>strategy %s, chunker v%s, detector v%s</>',
                $state['strategy'] ?? '—',
                $state['chunker_version'] ?? '—',
                $state['detector_version'] ?? '—',
            ));
        }

        $this->newLine();

        $rows = $sections->map(fn (BookSection $section): array => [
            (string) $section->sequence,
            $section->kind === SectionKind::RunningHead
                ? "<fg=gray>{$section->kind->label()}</>"
                : $section->kind->label(),
            $section->title ?? '<fg=gray>(untitled)</>',
            $section->pageRangeLabel(),
            $section->confidence === null ? '—' : number_format($section->confidence, 2),
            (string) $section->chunks()->count(),
            $this->variantSummary($section),
        ])->all();

        $this->table(['#', 'Kind', 'Title', 'Pages', 'Confidence', 'Chunks', 'Head variants removed'], $rows);

        $removed = $sections->filter(fn (BookSection $s): bool => $s->kind === SectionKind::RunningHead);

        if ($removed->isNotEmpty()) {
            $this->line(sprintf(
                '<fg=gray>%d running head(s) were removed from chunk text; the page rows still carry them.</>',
                $removed->count(),
            ));
        }

        return self::SUCCESS;
    }

    private function variantSummary(BookSection $section): string
    {
        $variants = $section->head_variants ?? [];

        if ($variants === []) {
            return '—';
        }

        $names = array_slice(array_keys($variants), 0, 2);
        $summary = implode(', ', array_map(fn (string $name): string => '"'.$name.'"', $names));

        return count($variants) > 2
            ? $summary.' +'.(count($variants) - 2)
            : $summary;
    }

    /**
     * Check every chunk against the text it claims to be a slice of.
     */
    private function verify(Book $book, BookChunker $chunker): int
    {
        $chunks = $book->chunks()->get();

        if ($chunks->isEmpty()) {
            $this->warn("No chunks recorded. Run `books:chunk --book={$book->slug}`.");

            return self::FAILURE;
        }

        $stale = $chunker->isStale($book);
        $failures = [];

        // The offsets are only meaningful against the stream the chunks were
        // cut from, so a stale book is reported rather than mis-measured.
        if ($stale) {
            $this->warn('This book is stale: its pages or the chunking rules have changed since it was chunked.');
        }

        $ceiling = (int) config('books.chunking.max_tokens');

        foreach ($chunks as $chunk) {
            if ($chunk->token_estimate > $ceiling) {
                $failures[] = "chunk {$chunk->chunk_index} estimates {$chunk->token_estimate} tokens, over {$ceiling}";
            }

            if ($chunk->page_from > $chunk->page_to) {
                $failures[] = "chunk {$chunk->chunk_index} has an inverted page range";
            }

        }

        [$stream, $mismatches, $uncovered] = $chunker->verify($book, $chunks);

        foreach ($mismatches as $mismatch) {
            $failures[] = $mismatch;
        }

        $this->newLine();
        $this->line("<options=bold>{$book->title}</> ({$book->year})");
        $this->line(sprintf('  %d chunk(s) over %s characters of assembled text.', $chunks->count(), number_format($stream)));
        $this->line(sprintf(
            '  offsets: %s',
            $mismatches === [] ? '<fg=green>every chunk is exactly the slice it claims</>' : '<fg=red>'.count($mismatches).' mismatch(es)</>',
        ));
        $this->line(sprintf(
            '  coverage: %s',
            $uncovered === 0 ? '<fg=green>every content character is accounted for</>' : "<fg=red>{$uncovered} content character(s) in no chunk</>",
        ));

        if ($uncovered > 0) {
            $failures[] = "{$uncovered} content characters reached no chunk";
        }

        if ($failures !== []) {
            $this->newLine();

            foreach (array_slice($failures, 0, 10) as $failure) {
                $this->line("  <fg=red>✗</> {$failure}");
            }

            if (count($failures) > 10) {
                $this->line('  <fg=gray>… and '.(count($failures) - 10).' more.</>');
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Verified.');

        return self::SUCCESS;
    }

    private function show(Book $book): int
    {
        $kinds = array_filter((array) $this->option('kind'));

        $chunks = $book->chunks()
            ->when($this->option('page') !== null, function ($query): void {
                $page = (int) $this->option('page');
                $query->where('page_from', '<=', $page)->where('page_to', '>=', $page);
            })
            ->when($kinds !== [], fn ($query) => $query->whereIn('kind', $kinds))
            ->when($this->option('excluded'), fn ($query) => $query->where('is_indexable', false))
            ->when($this->option('search') !== null, fn ($query) => $query->where('text', 'ilike', '%'.$this->option('search').'%'))
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($chunks->isEmpty()) {
            $this->warn('No chunks matched.');

            return self::FAILURE;
        }

        foreach ($chunks as $chunk) {
            $this->newLine();
            $this->line(sprintf(
                '<options=bold>#%d</> %s · %s · %s',
                $chunk->chunk_index,
                $chunk->kind->isIndexable() ? $chunk->kind->label() : "<fg=yellow>{$chunk->kind->label()}</>",
                $chunk->pages,
                $chunk->char_count.' chars / ~'.$chunk->token_estimate.' tokens',
            ));

            if ($chunk->overlap_chars > 0) {
                $this->line("  <fg=gray>{$chunk->overlap_chars} characters carried over from the previous chunk</>");
            }

            if ($this->option('payload')) {
                $this->newLine();
                $this->line(json_encode(
                    Arr::except($chunk->toArray(), ['embedding']),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ));

                continue;
            }

            $this->line('  <fg=gray>'.$chunk->citation.'</>');

            if ($chunk->headings !== null && count($chunk->headings) > 1) {
                $this->line('  <fg=gray>contains: '.implode(' · ', $chunk->headings).'</>');
            }

            $this->newLine();

            foreach (explode("\n", $chunk->text) as $line) {
                $this->line('  '.$line);
            }
        }

        $this->newLine();
        $this->line(sprintf('<fg=gray>%d of %d chunk(s) shown.</>', $chunks->count(), $book->chunks()->count()));

        return self::SUCCESS;
    }
}
