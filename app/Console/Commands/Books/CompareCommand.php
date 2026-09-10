<?php

namespace App\Console\Commands\Books;

use App\Enums\PageTextSource;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\BookPageExtraction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CompareCommand extends Command
{
    protected $signature = 'books:compare
        {book : Slug of the book to inspect}
        {--pages=6 : How many pages to sample}
        {--page=* : Inspect these specific page numbers instead of sampling}
        {--promote= : Set the book to prefer this source from now on (text_layer or ocr)}';

    protected $description = 'Compare a book\'s embedded text layer against its OCR, page by page';

    public function handle(): int
    {
        $book = Book::firstWhere('slug', $this->argument('book'));

        if ($book === null) {
            $this->error("No book with slug [{$this->argument('book')}].");

            return self::FAILURE;
        }

        if ($this->option('promote') !== null) {
            return $this->promote($book);
        }

        $pages = $this->samplePages($book);

        if ($pages->isEmpty()) {
            $this->warn('No extracted pages to compare. Run `books:extract` first.');

            return self::FAILURE;
        }

        foreach ($pages as $page) {
            $this->renderPage($page);
        }

        $this->renderSummary($book);

        return self::SUCCESS;
    }

    private function renderPage(BookPage $page): void
    {
        $layer = $page->extractionFrom(PageTextSource::TextLayer);
        $ocr = $page->extractionFrom(PageTextSource::Ocr);

        $this->newLine();
        $this->line("<options=bold>── Page {$page->page_number}</>"
            .($page->printed_page_label ? " (printed: {$page->printed_page_label})" : ''));

        foreach ([['Text layer', $layer], ['OCR', $ocr]] as [$label, $extraction]) {
            $score = $extraction?->quality_score;

            $this->newLine();
            $this->line(sprintf(
                '<fg=cyan>%s</> %s',
                $label,
                $score === null ? '(unscored)' : '(quality '.number_format($score, 3).')'
            ));

            $text = $extraction?->text;

            if ($text === null || trim($text) === '') {
                $this->line('  <fg=gray>(nothing extracted)</>');

                continue;
            }

            foreach (array_slice(explode("\n", $text), 0, 12) as $line) {
                $this->line('  '.$line);
            }
        }
    }

    private function renderSummary(Book $book): void
    {
        // Queried directly rather than through the book, because a
        // hasManyThrough adds its own key column to the select and that cannot
        // survive a GROUP BY.
        $means = BookPageExtraction::query()
            ->whereIn('book_page_id', $book->pages()->select('id'))
            ->whereNotNull('quality_score')
            ->selectRaw('text_source as source, avg(quality_score) as mean, count(*) as total')
            ->groupBy('text_source')
            ->get();

        $this->newLine();
        $this->table(
            ['Source', 'Pages', 'Mean quality'],
            $means->map(fn ($row): array => [
                PageTextSource::from($row->source)->label(),
                (string) $row->total,
                number_format((float) $row->mean, 3),
            ])->all()
        );

        $this->line('<fg=yellow>Note:</> quality scores detect malformed words, not wrong ones.');
        $this->line('A subtly incorrect word such as "Snuterne" for "Sauterne" scores as clean,');
        $this->line('so read a few pages above before deciding which source to prefer.');
    }

    private function promote(Book $book): int
    {
        $source = PageTextSource::tryFrom((string) $this->option('promote'));

        if ($source === null) {
            $this->error('--promote must be text_layer or ocr.');

            return self::FAILURE;
        }

        $book->forceFill(['preferred_text_source' => $source])->save();

        $this->info("{$book->slug} will now prefer {$source->label()}.");
        $this->line('Run `books:extract --book='.$book->slug.'` to apply it, or promote during the next run.');

        return self::SUCCESS;
    }

    /**
     * Spread the sample across the book so it takes in front matter, body and
     * back matter rather than clustering at the start.
     *
     * @return Collection<int, BookPage>
     */
    private function samplePages(Book $book): Collection
    {
        $explicit = array_filter(array_map('intval', (array) $this->option('page')));

        if ($explicit !== []) {
            return $book->pages()
                ->with('extractions')
                ->whereIn('page_number', $explicit)
                ->get();
        }

        $available = $book->pages()->pluck('page_number')->all();

        if ($available === []) {
            return collect();
        }

        $wanted = max(1, (int) $this->option('pages'));
        $step = max(1, (int) floor(count($available) / ($wanted + 1)));

        $selected = [];

        for ($i = 1; $i <= $wanted && ($i * $step) < count($available); $i++) {
            $selected[] = $available[$i * $step];
        }

        return $book->pages()
            ->with('extractions')
            ->whereIn('page_number', $selected ?: [$available[0]])
            ->get();
    }
}
