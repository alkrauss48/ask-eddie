<?php

namespace App\Console\Commands\House;

use App\Services\House\HouseExport;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Downloads the house export from the live site onto the house disk.
 *
 * Locally the export arrives through compose.yaml's bind mount of the sibling
 * checkout; a deployed pod has no such checkout, and anything copied into its
 * filesystem is gone on the next rollout. The site already serves the
 * committed export from /data, so this pulls it from there. Only house:import
 * and house:status read these files -- the imported catalog lives in Postgres
 * -- so losing them on a restart costs nothing until the next import.
 *
 * Nothing is written unless every dataset reproduces the manifest's checksum.
 * A download caught mid-deploy, with half the files from one export and half
 * from the next, would otherwise import as a catalog neither export describes.
 */
class FetchCommand extends Command
{
    protected $signature = 'house:fetch
        {--from= : Base URL to download from, instead of config(\'house.fetch_url\')}
        {--force : Download even when the local export already matches the site}';

    protected $description = 'Download the-krauss-haus export from the live site onto the house disk';

    public function handle(HouseExport $export): int
    {
        $baseUrl = rtrim((string) ($this->option('from') ?: config('house.fetch_url')), '/');

        try {
            $directory = $export->sourceDirectory();
            $manifest = $this->download($baseUrl, 'manifest');
            $checksum = json_decode($manifest, true)['checksum'] ?? null;

            if (! is_string($checksum)) {
                throw new RuntimeException("{$baseUrl}/manifest.json carries no checksum.");
            }

            if (! $this->option('force') && $this->localChecksum($export) === $checksum) {
                $this->info("The export on {$directory} already matches the site.");

                return self::SUCCESS;
            }

            $datasets = [];

            foreach (HouseExport::DATASETS as $name) {
                $datasets[$name] = $this->download($baseUrl, $name);
            }

            $downloaded = hash('sha256', implode('', $datasets));

            if (! hash_equals($checksum, $downloaded)) {
                throw new RuntimeException(sprintf(
                    'The downloaded files do not reproduce the manifest checksum (expected %s…, got %s…). The site may be mid-deploy; try again. Nothing was written.',
                    substr($checksum, 0, 12),
                    substr($downloaded, 0, 12),
                ));
            }

            $this->write($directory, [...$datasets, 'manifest' => $manifest]);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Fetched %d files from %s into %s, checksum %s…',
            count($datasets) + 1,
            $baseUrl,
            $directory,
            substr($checksum, 0, 12),
        ));
        $this->line('<fg=gray>Run `house:import`, then `house:embed`.</>');

        return self::SUCCESS;
    }

    private function download(string $baseUrl, string $name): string
    {
        return Http::timeout(30)->retry(2, 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException)->get("{$baseUrl}/{$name}.json")->throw()->body();
    }

    /**
     * The checksum of the export already on disk, or null when there is no
     * complete one to compare against.
     */
    private function localChecksum(HouseExport $export): ?string
    {
        if (! $export->isAvailable()) {
            return null;
        }

        try {
            return $export->checksum();
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Write each file beside its final name and rename it into place, manifest
     * last, so an interrupted run leaves a checksum mismatch that house:import
     * --verify can see rather than a truncated file that looks whole.
     *
     * @param  array<string, string>  $files  file contents keyed by dataset name
     */
    private function write(string $directory, array $files): void
    {
        File::ensureDirectoryExists($directory);

        if (! is_writable($directory)) {
            throw new RuntimeException("{$directory} is not writable. Set HOUSE_PATH to a writable directory.");
        }

        foreach ($files as $name => $contents) {
            $path = "{$directory}/{$name}.json";

            File::put("{$path}.tmp", $contents);
            File::move("{$path}.tmp", $path);
        }
    }
}
