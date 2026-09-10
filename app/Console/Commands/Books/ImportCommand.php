<?php

namespace App\Console\Commands\Books;

use App\Services\Books\BookImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportCommand extends Command
{
    protected $signature = 'books:import {--dry-run : Report what would change without writing anything}';

    protected $description = 'Register the PDFs on the books disk, recording their metadata and checksums';

    public function handle(BookImporter $importer): int
    {
        try {
            $filenames = $importer->discover();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($filenames === []) {
            $this->warn('No PDFs found on the books disk. Check BOOKS_PATH.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->reportDryRun($importer, $filenames);
        }

        $rows = [];
        $created = 0;
        $updated = 0;

        $this->withProgressBar($filenames, function (string $filename) use ($importer, &$rows, &$created, &$updated): void {
            try {
                ['book' => $book, 'changed' => $changed, 'created' => $wasCreated] = $importer->import($filename);
            } catch (Throwable $exception) {
                $rows[] = [$filename, '<fg=red>error</>', $exception->getMessage()];

                return;
            }

            $wasCreated ? $created++ : ($changed ? $updated++ : null);

            $rows[] = [
                $book->slug,
                $wasCreated ? '<fg=green>new</>' : ($changed ? '<fg=yellow>changed</>' : 'unchanged'),
                sprintf('%s%s, %d pages', $book->year ? $book->year.' ' : '', $book->author ?? 'unknown author', $book->page_count ?? 0),
            ];
        });

        $missing = $importer->markMissing($filenames);

        $this->newLine(2);
        $this->table(['Book', 'State', 'Detail'], $rows);

        $this->line(sprintf(
            '%d book(s) on disk: <fg=green>%d new</>, <fg=yellow>%d changed</>, %d unchanged.',
            count($filenames),
            $created,
            $updated,
            count($filenames) - $created - $updated,
        ));

        if ($missing > 0) {
            $this->warn("{$missing} book(s) are no longer on disk and were marked missing. Their extracted text was kept.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $filenames
     */
    private function reportDryRun(BookImporter $importer, array $filenames): int
    {
        $this->info('Dry run: nothing will be written.');
        $this->newLine();

        $this->table(
            ['Filename', 'Would resolve to'],
            array_map(function (string $filename) use ($importer): array {
                return [$filename, $importer->sourceDirectory().'/'.$filename];
            }, $filenames)
        );

        $this->line(count($filenames).' PDF(s) would be imported.');

        return self::SUCCESS;
    }
}
