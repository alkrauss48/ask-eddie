<?php

namespace App\Services\Books;

use App\Enums\PageTextSource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Produces text candidates for pages of a book.
 *
 * Nothing here touches the database. The command that drives it owns
 * persistence, which keeps this a plain function of its inputs and leaves the
 * door open to running it from a queued job later without rework.
 */
class PageExtractor
{
    public function __construct(
        private readonly PdfToolkit $pdf,
        private readonly TesseractOcr $tesseract,
        private readonly PageTextNormalizer $normalizer,
        private readonly PageTextQuality $quality,
        private readonly LocalPdfWorkspace $workspace,
    ) {}

    /**
     * Read the embedded text layer for every page of a document at once.
     *
     * @return array<int, PageExtractionResult> Keyed by 1-based page number.
     */
    public function extractTextLayer(string $pdfPath, ?int $expectedPages = null): array
    {
        try {
            $pages = $this->pdf->extractTextLayer($pdfPath, $expectedPages);
        } catch (Throwable $exception) {
            return [];
        }

        $results = [];

        foreach ($pages as $index => $rawText) {
            $results[$index + 1] = $this->buildResult(
                pageNumber: $index + 1,
                source: PageTextSource::TextLayer,
                rawText: $rawText,
                settings: ['tool' => 'pdftotext', 'layout' => true],
            );
        }

        return $results;
    }

    /**
     * OCR a batch of pages in parallel.
     *
     * Pages are rendered and recognised in one wave, because a process pool
     * starts everything it is given at once; the caller is responsible for
     * sizing each wave to the configured concurrency.
     *
     * @param  list<int>  $pageNumbers
     * @return array<int, PageExtractionResult> Keyed by 1-based page number.
     */
    public function ocrPages(string $pdfPath, array $pageNumbers, string $language, ?int $dpi = null, ?int $psm = null): array
    {
        if ($pageNumbers === []) {
            return [];
        }

        $dpi ??= (int) config('books.ocr.dpi');
        $psm ??= (int) config('books.ocr.page_segmentation_mode');

        $directory = $this->workspace->temporaryPageDirectory();
        $results = [];
        $images = [];

        try {
            // Render the whole wave in parallel. Doing this serially pins the
            // work to a single core and leaves the OCR pool waiting.
            $prefixes = [];

            foreach ($pageNumbers as $pageNumber) {
                $pageDirectory = $directory.'/'.$pageNumber;
                File::ensureDirectoryExists($pageDirectory);
                $prefixes[$pageNumber] = $this->pdf->renderPrefix($pageDirectory);
            }

            $renders = Process::concurrently(function ($pool) use ($pdfPath, $prefixes, $dpi): void {
                foreach ($prefixes as $pageNumber => $prefix) {
                    $pool->as((string) $pageNumber)
                        ->timeout((int) config('books.extraction.timeout'))
                        ->command($this->pdf->renderCommand($pdfPath, $pageNumber, $prefix, $dpi));
                }
            });

            foreach ($prefixes as $pageNumber => $prefix) {
                $render = $renders[(string) $pageNumber];

                if ($render->failed()) {
                    $results[$pageNumber] = PageExtractionResult::failure(
                        $pageNumber,
                        PageTextSource::Ocr,
                        trim($render->errorOutput()) ?: 'pdftoppm exited with a non-zero status.'
                    );

                    continue;
                }

                try {
                    $images[$pageNumber] = $this->pdf->renderedImage($prefix);
                } catch (Throwable $exception) {
                    $results[$pageNumber] = PageExtractionResult::failure(
                        $pageNumber, PageTextSource::Ocr, $exception->getMessage()
                    );
                }
            }

            $startedAt = microtime(true);

            $processResults = Process::concurrently(function ($pool) use ($images, $language, $psm): void {
                foreach ($images as $pageNumber => $imagePath) {
                    $pool->as((string) $pageNumber)
                        ->timeout((int) config('books.extraction.timeout'))
                        ->env($this->tesseract->environment())
                        ->command($this->tesseract->command($imagePath, $language, $psm));
                }
            });

            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $perPageMs = $images === [] ? null : (int) round($elapsedMs / count($images));

            foreach ($images as $pageNumber => $imagePath) {
                $processResult = $processResults[(string) $pageNumber];

                if ($processResult->failed()) {
                    $results[$pageNumber] = PageExtractionResult::failure(
                        $pageNumber,
                        PageTextSource::Ocr,
                        trim($processResult->errorOutput()) ?: 'tesseract exited with a non-zero status.'
                    );

                    continue;
                }

                $results[$pageNumber] = $this->buildResult(
                    pageNumber: $pageNumber,
                    source: PageTextSource::Ocr,
                    rawText: $processResult->output(),
                    settings: ['tool' => 'tesseract', 'dpi' => $dpi, 'psm' => $psm, 'language' => $language],
                    durationMs: $perPageMs,
                );
            }
        } finally {
            File::deleteDirectory($directory);
        }

        ksort($results);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildResult(
        int $pageNumber,
        PageTextSource $source,
        string $rawText,
        array $settings,
        ?int $durationMs = null,
    ): PageExtractionResult {
        $normalized = $this->normalizer->normalize($rawText);

        return new PageExtractionResult(
            pageNumber: $pageNumber,
            source: $source,
            rawText: $rawText,
            normalized: $normalized,
            quality: $this->quality->score($normalized->text),
            settings: $settings,
            durationMs: $durationMs,
        );
    }
}
