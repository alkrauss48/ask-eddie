<?php

namespace App\Services\Books;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Thin wrapper around the poppler command line tools.
 */
class PdfToolkit
{
    public function pageCount(string $pdfPath): int
    {
        $result = Process::timeout($this->timeout())
            ->run([$this->binary('pdfinfo'), $pdfPath]);

        if ($result->failed()) {
            throw new RuntimeException("pdfinfo failed for [{$pdfPath}]: ".trim($result->errorOutput()));
        }

        if (preg_match('/^Pages:\s+(\d+)$/m', $result->output(), $matches) !== 1) {
            throw new RuntimeException("Could not read a page count for [{$pdfPath}].");
        }

        return (int) $matches[1];
    }

    /**
     * Extract the whole document's embedded text layer in one invocation.
     *
     * Poppler separates pages with a form feed, so a single call replaces one
     * invocation per page. That matters more than it sounds: running this once
     * over the 260-page 1862 Jerry Thomas takes 0.14 seconds, where 260 calls
     * would each re-parse the entire file.
     *
     * @return list<string> One entry per page, in order, blank pages included.
     */
    public function extractTextLayer(string $pdfPath, ?int $expectedPages = null): array
    {
        $result = Process::timeout($this->timeout())
            ->run([$this->binary('pdftotext'), '-layout', $pdfPath, '-']);

        if ($result->failed()) {
            throw new RuntimeException("pdftotext failed for [{$pdfPath}]: ".trim($result->errorOutput()));
        }

        $pages = explode("\f", $result->output());

        // A trailing form feed leaves an empty final element that is not a page.
        if ($pages !== [] && trim((string) end($pages)) === '') {
            array_pop($pages);
        }

        if ($expectedPages !== null && count($pages) !== $expectedPages) {
            throw new RuntimeException(
                'pdftotext returned '.count($pages)." pages for [{$pdfPath}], expected {$expectedPages}."
            );
        }

        return array_values($pages);
    }

    /**
     * The output path prefix pdftoppm should be given for a page.
     */
    public function renderPrefix(string $destinationDirectory): string
    {
        return $destinationDirectory.'/page';
    }

    /**
     * Build the argument list that renders one page to a greyscale PNG.
     *
     * Exposed as a command rather than executed here so that a whole wave of
     * pages can be rendered in parallel. Rendering is roughly a third of the
     * cost of a page and doing it serially leaves most of the CPU idle.
     *
     * @return list<string>
     */
    public function renderCommand(string $pdfPath, int $pageNumber, string $prefix, ?int $dpi = null): array
    {
        return [
            $this->binary('pdftoppm'),
            '-r', (string) ($dpi ?? config('books.ocr.dpi')),
            '-gray',
            '-png',
            '-f', (string) $pageNumber,
            '-l', (string) $pageNumber,
            $pdfPath,
            $prefix,
        ];
    }

    /**
     * Locate the image a render produced.
     *
     * Poppler zero-pads the page number to the width of the document's highest
     * page, so the exact filename is not predictable; each prefix has its own
     * directory and therefore only ever one image.
     */
    public function renderedImage(string $prefix): string
    {
        $rendered = glob($prefix.'-*.png') ?: [];

        if ($rendered === []) {
            throw new RuntimeException("pdftoppm produced no image for [{$prefix}].");
        }

        return $rendered[0];
    }

    /**
     * Render a single page and return the resulting file's path.
     */
    public function renderPage(string $pdfPath, int $pageNumber, string $destinationDirectory, ?int $dpi = null): string
    {
        $prefix = $this->renderPrefix($destinationDirectory);

        $result = Process::timeout($this->timeout())
            ->run($this->renderCommand($pdfPath, $pageNumber, $prefix, $dpi));

        if ($result->failed()) {
            throw new RuntimeException(
                "pdftoppm failed for [{$pdfPath}] page {$pageNumber}: ".trim($result->errorOutput())
            );
        }

        return $this->renderedImage($prefix);
    }

    private function binary(string $name): string
    {
        return (string) config("books.binaries.{$name}", $name);
    }

    private function timeout(): int
    {
        return (int) config('books.extraction.timeout');
    }
}
