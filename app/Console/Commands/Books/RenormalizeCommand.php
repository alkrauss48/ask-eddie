<?php

namespace App\Console\Commands\Books;

use App\Models\Book;
use App\Models\BookPageExtraction;
use App\Services\Books\PagePromoter;
use App\Services\Books\PageTextNormalizer;
use App\Services\Books\PageTextQuality;
use Illuminate\Console\Command;

/**
 * Re-derives page text from the raw output already stored on each extraction.
 *
 * This is why raw_text is kept: improving the normalizer costs one pass over
 * the database rather than another run of the OCR toolchain. No external
 * process is invoked.
 */
class RenormalizeCommand extends Command
{
    protected $signature = 'books:renormalize
        {--book=* : Restrict to these slugs}
        {--all : Re-normalize every extraction, not only stale ones}';

    protected $description = 'Recompute page text and quality scores from stored raw text, without re-running OCR';

    public function handle(PageTextNormalizer $normalizer, PageTextQuality $quality, PagePromoter $promoter): int
    {
        $slugs = array_filter((array) $this->option('book'));

        $books = Book::query()
            ->when($slugs !== [], fn ($query) => $query->whereIn('slug', $slugs))
            ->get();

        if ($books->isEmpty()) {
            $this->error('No matching books.');

            return self::FAILURE;
        }

        $query = BookPageExtraction::query()
            ->whereIn('book_page_id', fn ($sub) => $sub->select('id')->from('book_pages')->whereIn('book_id', $books->pluck('id')))
            ->whereNotNull('raw_text')
            ->when(! $this->option('all'), fn ($q) => $q->where('normalizer_version', '<', PageTextNormalizer::VERSION));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Everything is already at normalizer version '.PageTextNormalizer::VERSION.'.');

            return self::SUCCESS;
        }

        $this->line("Re-normalizing {$total} extraction(s) to version ".PageTextNormalizer::VERSION.'.');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $labelsFound = 0;

        $query->chunkById(500, function ($extractions) use ($normalizer, $quality, $bar, &$labelsFound): void {
            foreach ($extractions as $extraction) {
                $normalized = $normalizer->normalize((string) $extraction->raw_text);
                $score = $quality->score($normalized->text);

                $extraction->forceFill([
                    'text' => $normalized->text,
                    'quality_score' => $score->score,
                    'score_breakdown' => $score->breakdown,
                    'normalizer_version' => PageTextNormalizer::VERSION,
                ])->save();

                if ($normalized->printedPageLabel !== null) {
                    $extraction->page->forceFill([
                        'printed_page_label' => $normalized->printedPageLabel,
                    ])->save();

                    $labelsFound++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        foreach ($books as $book) {
            $promoter->promoteBook($book);
        }

        $this->info("Done. {$labelsFound} printed page label(s) recovered.");

        return self::SUCCESS;
    }
}
