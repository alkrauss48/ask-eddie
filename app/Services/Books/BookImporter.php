<?php

namespace App\Services\Books;

use App\Enums\BookStatus;
use App\Models\Book;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registers the PDFs on the books disk as Book records.
 */
class BookImporter
{
    public function __construct(
        private readonly BookMetadataParser $parser,
        private readonly PdfToolkit $pdf,
    ) {}

    /**
     * The absolute path of the configured books disk.
     */
    public function sourceDirectory(): string
    {
        $disk = (string) config('books.disk');
        $driver = config("filesystems.disks.{$disk}.driver");

        // The extraction tools are command line binaries and need a real path,
        // so a remote adapter cannot work here. Fail clearly rather than
        // surfacing a confusing error from pdfinfo later.
        if ($driver !== 'local') {
            throw new RuntimeException(
                "The books disk [{$disk}] uses the [{$driver}] driver; only local disks are supported."
            );
        }

        return rtrim((string) config("filesystems.disks.{$disk}.root"), '/');
    }

    /**
     * @return list<string> Filenames of the PDFs on the books disk, sorted.
     */
    public function discover(): array
    {
        $directory = $this->sourceDirectory();

        if (! is_dir($directory)) {
            throw new RuntimeException("The books disk root [{$directory}] does not exist.");
        }

        $files = array_map(
            fn (string $path): string => basename($path),
            glob($directory.'/*.pdf') ?: []
        );

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    /**
     * Create or update the Book record for a single PDF.
     *
     * @return array{book: Book, changed: bool, created: bool}
     */
    public function import(string $filename): array
    {
        $path = $this->sourceDirectory().'/'.$filename;
        $size = (int) filesize($path);
        $modifiedAt = filemtime($path);

        $book = Book::firstWhere('source_filename', $filename);

        // Hashing 1.8 GB on every run is a pointless tax, so only re-hash when
        // the file's size or timestamp says something might have moved.
        $unchanged = $book !== null
            && $book->file_size === $size
            && $book->file_modified_at?->getTimestamp() === $modifiedAt;

        if ($unchanged) {
            return ['book' => $book, 'changed' => false, 'created' => false];
        }

        $checksum = hash_file('sha256', $path);
        $metadata = $this->parser->parse($filename);

        if ($book === null) {
            $book = new Book(['source_filename' => $filename]);
            $created = true;
        } else {
            $created = false;
        }

        $contentChanged = ! $created && $book->checksum !== $checksum;

        $book->fill([
            'slug' => $book->slug ?? $this->uniqueSlug($metadata['slug']),
            'title' => $metadata['title'],
            'author' => $metadata['author'],
            'year' => $metadata['year'],
            'language' => $book->exists ? $book->language : $metadata['language'],
            'checksum' => $checksum,
            'file_size' => $size,
            'file_modified_at' => $modifiedAt ? now()->setTimestamp($modifiedAt) : null,
            'metadata' => array_filter(['edition' => $metadata['edition']]),
        ]);

        if ($created || $contentChanged) {
            $book->page_count = $this->pdf->pageCount($path);
            $book->status = BookStatus::Pending;
        }

        DB::transaction(function () use ($book, $contentChanged): void {
            $book->save();

            // A changed PDF may have been re-scanned or re-paginated, so pages
            // keyed by the old numbering are not merely stale but misleading.
            if ($contentChanged) {
                $book->pages()->delete();
                $book->forceFill(['extracted_at' => null])->save();
            }
        });

        return ['book' => $book, 'changed' => $created || $contentChanged, 'created' => $created];
    }

    /**
     * Flag books whose PDF has disappeared without deleting any extraction work.
     *
     * @param  list<string>  $presentFilenames
     */
    public function markMissing(array $presentFilenames): int
    {
        return Book::query()
            ->whereNotIn('source_filename', $presentFilenames)
            ->where('status', '!=', BookStatus::Missing)
            ->update(['status' => BookStatus::Missing]);
    }

    /**
     * The parser already disambiguates by year; this guards the rest.
     */
    private function uniqueSlug(string $slug): string
    {
        $candidate = $slug;
        $suffix = 2;

        while (Book::where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix++;
        }

        return $candidate;
    }
}
