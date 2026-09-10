<?php

namespace App\Services\Books;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Runs Tesseract over a rendered page image.
 */
class TesseractOcr
{
    /**
     * Build the argument list for a page image.
     *
     * Page segmentation mode 3 is fully automatic without orientation and
     * script detection. Mode 1 adds OSD, which on century-old scans sometimes
     * decides a page is rotated and returns nothing usable, so it is not the
     * default. OEM 1 selects the LSTM engine, which is the whole reason fresh
     * OCR beats the pre-LSTM text layer already embedded in these PDFs.
     *
     * @return list<string>
     */
    public function command(string $imagePath, string $language, ?int $pageSegmentationMode = null): array
    {
        return [
            (string) config('books.binaries.tesseract', 'tesseract'),
            $imagePath,
            'stdout',
            '-l', $language,
            '--oem', '1',
            '--psm', (string) ($pageSegmentationMode ?? config('books.ocr.page_segmentation_mode')),
            '-c', 'preserve_interword_spaces=1',
        ];
    }

    /**
     * Environment for the child process.
     *
     * Tesseract is multithreaded by default and will take every core it can
     * see. Since pages are processed by a pool of parallel Tesseract processes,
     * leaving this unset oversubscribes the CPU badly enough to run slower than
     * doing the work one page at a time.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return ['OMP_THREAD_LIMIT' => '1'];
    }

    public function run(string $imagePath, string $language, ?int $pageSegmentationMode = null): string
    {
        $result = Process::timeout((int) config('books.extraction.timeout'))
            ->env($this->environment())
            ->run($this->command($imagePath, $language, $pageSegmentationMode));

        return $this->output($result, $imagePath);
    }

    /**
     * Read a completed process result, whether it was run directly or as part
     * of a pool.
     */
    public function output(ProcessResult $result, string $imagePath): string
    {
        if ($result->failed()) {
            throw new RuntimeException("tesseract failed for [{$imagePath}]: ".trim($result->errorOutput()));
        }

        return $result->output();
    }
}
