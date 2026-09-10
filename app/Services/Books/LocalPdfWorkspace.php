<?php

namespace App\Services\Books;

use App\Models\Book;
use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Gives a book's PDF a home on the container's own filesystem for the duration
 * of an extraction run.
 *
 * The project directory is a bind mount, which on macOS means every read
 * crosses a virtualised filesystem. Rendering a page re-reads the whole PDF, so
 * working directly against the mount would drag a 245 MB file across it once
 * per page -- roughly 84 GB for a single book. Copying it locally once costs a
 * few seconds and removes that entirely.
 */
class LocalPdfWorkspace
{
    /**
     * Distinguishes this run's scratch space from any other run's.
     *
     * Without it, two extraction runs would share a directory keyed only by the
     * book's checksum, and whichever finished a book first would delete the PDF
     * the other was still rendering from.
     */
    private readonly string $runToken;

    public function __construct()
    {
        $this->runToken = bin2hex(random_bytes(6));
    }

    /**
     * Copy the book's PDF locally, hand its path to the callback, then clean up.
     *
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $callback
     * @return TReturn
     */
    public function with(Book $book, Closure $callback): mixed
    {
        $sourcePath = $book->sourcePath();

        if (! is_readable($sourcePath)) {
            throw new RuntimeException("The source PDF for [{$book->slug}] is not readable at [{$sourcePath}].");
        }

        $directory = $this->directoryFor($book);
        $localPdf = $directory.'/source.pdf';

        File::ensureDirectoryExists($directory);

        if (! File::exists($localPdf) || File::size($localPdf) !== File::size($sourcePath)) {
            File::copy($sourcePath, $localPdf);
        }

        try {
            return $callback($localPdf);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * A scratch directory for a single page render, cleaned up by the caller.
     */
    public function temporaryPageDirectory(): string
    {
        $directory = $this->root().'/'.$this->runToken.'/pages/'.bin2hex(random_bytes(8));

        File::ensureDirectoryExists($directory);

        return $directory;
    }

    private function directoryFor(Book $book): string
    {
        return $this->root().'/'.$this->runToken.'/books/'.$book->checksum;
    }

    private function root(): string
    {
        return rtrim((string) config('books.temp_path'), '/');
    }
}
