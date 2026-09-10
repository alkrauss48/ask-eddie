<?php

namespace App\Console\Commands\Books;

use App\Enums\PageStatus;
use App\Enums\PageTextSource;
use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'books:status {--book=* : Restrict to these slugs}';

    protected $description = 'Show extraction progress for every imported book';

    public function handle(): int
    {
        $slugs = array_filter((array) $this->option('book'));

        $books = Book::query()
            ->when($slugs !== [], fn ($query) => $query->whereIn('slug', $slugs))
            ->orderBy('year')
            ->get();

        if ($books->isEmpty()) {
            $this->warn('No books imported yet. Run `books:import`.');

            return self::FAILURE;
        }

        $rows = $books->map(fn (Book $book): array => $this->row($book))->all();

        $this->newLine();
        $this->table(
            ['Book', 'Year', 'Status', 'Pages', 'Done', 'Blank', 'Failed', 'Source split', 'Mean quality'],
            $rows
        );

        $totalPages = (int) $books->sum('page_count');
        $extracted = BookPage::whereIn('book_id', $books->pluck('id'))
            ->whereIn('status', [PageStatus::Extracted, PageStatus::Blank])
            ->count();

        $this->line(sprintf(
            '%d book(s), %s of %s pages accounted for (%.1f%%).',
            $books->count(),
            number_format($extracted),
            number_format($totalPages),
            $totalPages > 0 ? ($extracted / $totalPages) * 100 : 0.0,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function row(Book $book): array
    {
        // reorder() drops the relation's page ordering, which Postgres will not
        // accept alongside a GROUP BY.
        $counts = $book->pages()
            ->reorder()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sources = $book->pages()
            ->reorder()
            ->whereNotNull('text_source')
            ->selectRaw('text_source, count(*) as total')
            ->groupBy('text_source')
            ->pluck('total', 'text_source');

        $meanQuality = $book->extractions()
            ->whereNotNull('book_page_extractions.quality_score')
            ->avg('book_page_extractions.quality_score');

        $done = (int) ($counts[PageStatus::Extracted->value] ?? 0);
        $blank = (int) ($counts[PageStatus::Blank->value] ?? 0);
        $failed = (int) ($counts[PageStatus::Failed->value] ?? 0);

        return [
            $book->slug,
            (string) ($book->year ?? '—'),
            $book->status->label(),
            (string) ($book->page_count ?? 0),
            (string) $done,
            (string) $blank,
            $failed > 0 ? "<fg=red>{$failed}</>" : '0',
            sprintf(
                'ocr %d / layer %d',
                (int) ($sources[PageTextSource::Ocr->value] ?? 0),
                (int) ($sources[PageTextSource::TextLayer->value] ?? 0),
            ),
            $meanQuality === null ? '—' : number_format((float) $meanQuality, 3),
        ];
    }
}
