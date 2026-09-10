<?php

namespace App\Console\Commands\Books;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Models\Book;
use App\Models\BookPage;
use App\Services\Books\LocalPdfWorkspace;
use App\Services\Books\PageExtractionResult;
use App\Services\Books\PageExtractor;
use App\Services\Books\PagePromoter;
use App\Services\Books\PageTextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ExtractCommand extends Command
{
    protected $signature = 'books:extract
        {--book=* : Slug of a book to extract; repeatable, defaults to all}
        {--pages= : Restrict to a page range, e.g. 10-40 or 12}
        {--strategy= : ocr_all or gated; defaults to the configured strategy}
        {--concurrency= : Pages to OCR in parallel}
        {--dpi= : Render resolution for OCR}
        {--psm= : Tesseract page segmentation mode}
        {--force : Re-extract pages that are already done}
        {--retry-failed : Re-extract only pages that previously failed}';

    protected $description = 'Extract text from imported books, page by page';

    public function handle(PageExtractor $extractor, PagePromoter $promoter, LocalPdfWorkspace $workspace): int
    {
        // Two concurrent runs would duplicate every page's work and race each
        // other's progress, so the second one is turned away.
        $lock = Cache::lock('books:extract', 6 * 60 * 60);

        if (! $lock->get()) {
            $this->error('Another books:extract run is already in progress.');

            return self::FAILURE;
        }

        try {
            return $this->extract($extractor, $promoter, $workspace);
        } finally {
            $lock->release();
        }
    }

    private function extract(PageExtractor $extractor, PagePromoter $promoter, LocalPdfWorkspace $workspace): int
    {
        $books = $this->resolveBooks();

        if ($books->isEmpty()) {
            $this->error('No matching books. Run `books:import` first.');

            return self::FAILURE;
        }

        $strategy = (string) ($this->option('strategy') ?: config('books.strategy'));

        if (! in_array($strategy, ['ocr_all', 'gated'], true)) {
            $this->error("Unknown strategy [{$strategy}]. Use ocr_all or gated.");

            return self::FAILURE;
        }

        foreach ($books as $book) {
            $this->extractBook($book, $strategy, $extractor, $promoter, $workspace);
        }

        return self::SUCCESS;
    }

    private function extractBook(
        Book $book,
        string $strategy,
        PageExtractor $extractor,
        PagePromoter $promoter,
        LocalPdfWorkspace $workspace,
    ): void {
        $this->newLine();
        $this->line("<options=bold>{$book->title}</> ({$book->year}) — {$book->page_count} pages");

        $targetPages = $this->targetPageNumbers($book);

        if ($targetPages === []) {
            $this->line('  <fg=gray>Nothing to do; pass --force to re-extract.</>');

            return;
        }

        $book->forceFill(['status' => BookStatus::Extracting])->save();

        try {
            $workspace->with($book, function (string $pdfPath) use ($book, $targetPages, $strategy, $extractor, $promoter): void {
                // One invocation reads the embedded layer for the whole book,
                // so this is effectively free regardless of the strategy.
                $textLayer = $extractor->extractTextLayer($pdfPath, $book->page_count);

                foreach ($targetPages as $pageNumber) {
                    if (isset($textLayer[$pageNumber])) {
                        $this->persist($book, $textLayer[$pageNumber]);
                    }
                }

                $ocrPages = $this->pagesNeedingOcr($targetPages, $textLayer, $strategy);

                if ($ocrPages !== []) {
                    $this->ocrInWaves($book, $pdfPath, $ocrPages, $extractor);
                }

                $promoter->promoteBook($book);
            });

            $failed = $book->pages()->where('status', PageStatus::Failed)->count();

            $book->forceFill([
                'status' => $failed > 0 ? BookStatus::Failed : BookStatus::Extracted,
                'extracted_at' => now(),
            ])->save();

            if ($failed > 0) {
                $this->warn("  {$failed} page(s) failed. Re-run with --retry-failed.");
            }
        } catch (Throwable $exception) {
            $book->forceFill(['status' => BookStatus::Failed])->save();
            $this->error('  '.$exception->getMessage());
        }
    }

    /**
     * @param  list<int>  $pageNumbers
     */
    private function ocrInWaves(Book $book, string $pdfPath, array $pageNumbers, PageExtractor $extractor): void
    {
        $concurrency = max(1, (int) ($this->option('concurrency') ?: config('books.extraction.concurrency')));
        $dpi = $this->option('dpi') !== null ? (int) $this->option('dpi') : null;
        $psm = $this->option('psm') !== null ? (int) $this->option('psm') : null;

        $bar = $this->output->createProgressBar(count($pageNumbers));
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
        $bar->setMessage('');
        $bar->start();

        // A process pool starts everything it is handed at once, so pages are
        // OCR'd in waves and each wave is committed before the next begins.
        // That also caps what a Ctrl-C can lose to a single wave.
        foreach (array_chunk($pageNumbers, $concurrency) as $wave) {
            $results = $extractor->ocrPages($pdfPath, $wave, $book->language, $dpi, $psm);

            foreach ($results as $result) {
                $this->persist($book, $result);
            }

            $bar->advance(count($wave));
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Record one candidate transcription against its page.
     */
    private function persist(Book $book, PageExtractionResult $result): void
    {
        $page = BookPage::firstOrNew([
            'book_id' => $book->id,
            'page_number' => $result->pageNumber,
        ]);

        if (! $page->exists) {
            $page->status = PageStatus::Pending;
            $page->save();
        }

        if ($result->normalized?->printedPageLabel !== null && $page->printed_page_label === null) {
            $page->forceFill(['printed_page_label' => $result->normalized->printedPageLabel])->save();
        }

        $page->extractions()->updateOrCreate(
            ['text_source' => $result->source],
            [
                'raw_text' => $result->rawText,
                'text' => $result->normalized?->text,
                'quality_score' => $result->quality?->score,
                'score_breakdown' => $result->quality?->breakdown,
                'settings' => $result->settings,
                'normalizer_version' => PageTextNormalizer::VERSION,
                'duration_ms' => $result->durationMs,
                'status' => $result->failed() ? PageStatus::Failed : PageStatus::Extracted,
                'error' => $result->error,
            ]
        );

        if ($result->failed()) {
            $page->forceFill(['status' => PageStatus::Failed])->save();
        }
    }

    /**
     * Which pages still need OCR under the chosen strategy.
     *
     * @param  list<int>  $targetPages
     * @param  array<int, PageExtractionResult>  $textLayer
     * @return list<int>
     */
    private function pagesNeedingOcr(array $targetPages, array $textLayer, string $strategy): array
    {
        if ($strategy === 'ocr_all') {
            return $targetPages;
        }

        return array_values(array_filter($targetPages, function (int $pageNumber) use ($textLayer): bool {
            $candidate = $textLayer[$pageNumber] ?? null;

            if ($candidate === null || $candidate->normalized === null) {
                return true;
            }

            if ($candidate->normalized->characterCount() < (int) config('books.quality.min_characters')) {
                return true;
            }

            return ! ($candidate->quality?->passes() ?? false);
        }));
    }

    /**
     * @return list<int>
     */
    private function targetPageNumbers(Book $book): array
    {
        $range = $this->pageRange();
        $all = range(1, max(1, (int) $book->page_count));

        if ($range !== null) {
            $all = array_values(array_filter($all, fn (int $page): bool => $page >= $range[0] && $page <= $range[1]));
        }

        if ($this->option('force')) {
            return $all;
        }

        // A page whose OCR failed but whose text layer came through still reads
        // as extracted, yet it is running on the weaker of the two candidates.
        // Retrying has to cover those as well as outright failed pages.
        if ($this->option('retry-failed')) {
            return $book->pages()
                ->where(function ($query): void {
                    $query->where('status', PageStatus::Failed)
                        ->orWhereHas('extractions', fn ($extraction) => $extraction->where('status', PageStatus::Failed));
                })
                ->whereIn('page_number', $all)
                ->orderBy('page_number')
                ->pluck('page_number')
                ->all();
        }

        $done = $book->pages()
            ->where('status', PageStatus::Extracted)
            ->pluck('page_number')
            ->all();

        return array_values(array_diff($all, $done));
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function pageRange(): ?array
    {
        $option = $this->option('pages');

        if ($option === null || $option === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', (string) $option, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return [(int) $option, (int) $option];
    }

    /**
     * @return Collection<int, Book>
     */
    private function resolveBooks(): Collection
    {
        $slugs = array_filter((array) $this->option('book'));

        return Book::query()
            ->when($slugs !== [], fn ($query) => $query->whereIn('slug', $slugs))
            ->where('status', '!=', BookStatus::Missing)
            ->orderBy('year')
            ->get();
    }
}
