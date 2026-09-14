<?php

namespace App\Console\Commands\Books;

use App\Services\Books\BookImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'books:doctor';

    protected $description = 'Check that the OCR toolchain, source disk, working directory and inference services are usable';

    public function handle(BookImporter $importer): int
    {
        $checks = [
            ...$this->binaryChecks(),
            $this->languageCheck(),
            $this->sourceDiskCheck($importer),
            $this->workingDirectoryCheck(),
            $this->inferenceCheck(
                'tei-embed',
                (string) config('ai.providers.tei.url'),
                (string) config('books.embedding.model'),
            ),
            $this->inferenceCheck(
                'tei-rerank',
                (string) config('ai.providers.tei-rerank.url'),
                (string) config('books.retrieval.rerank.model'),
            ),
        ];

        $this->newLine();
        $this->table(['Check', 'Result', 'Detail'], array_map(
            fn (array $check): array => [
                $check['name'],
                $check['ok'] ? '<fg=green>OK</>' : '<fg=red>FAIL</>',
                $check['detail'],
            ],
            $checks
        ));

        $failed = array_filter($checks, fn (array $check): bool => ! $check['ok']);

        if ($failed !== []) {
            $this->newLine();
            $this->error(count($failed).' check(s) failed. Rebuild the container with `sail build --no-cache` if binaries are missing.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Everything checks out.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{name: string, ok: bool, detail: string}>
     */
    private function binaryChecks(): array
    {
        $versionFlags = [
            'pdfinfo' => '-v',
            'pdftotext' => '-v',
            'pdftoppm' => '-v',
            'tesseract' => '--version',
        ];

        $checks = [];

        foreach ($versionFlags as $binary => $flag) {
            $path = (string) config("books.binaries.{$binary}", $binary);
            $result = Process::run([$path, $flag]);

            // Poppler reports its version on stderr and exits non-zero for -v.
            $output = trim($result->output()) ?: trim($result->errorOutput());
            $found = $output !== '';

            $checks[] = [
                'name' => $binary,
                'ok' => $found,
                'detail' => $found ? strtok($output, "\n") : 'not found on PATH',
            ];
        }

        return $checks;
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function languageCheck(): array
    {
        $result = Process::run([(string) config('books.binaries.tesseract'), '--list-langs']);
        $output = trim($result->output()) ?: trim($result->errorOutput());

        $available = collect(explode("\n", $output))
            ->skip(1)
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->all();

        $required = ['eng', 'spa', 'ita', 'fra'];
        $missing = array_diff($required, $available);

        return [
            'name' => 'tesseract languages',
            'ok' => $missing === [],
            'detail' => $missing === []
                ? implode(', ', $available)
                : 'missing: '.implode(', ', $missing),
        ];
    }

    /**
     * Whether a TEI instance is up, and whether it is serving what we think.
     *
     * The model check is the half that matters. A TEI container serves exactly
     * one model and never says so in a response, so pointing the embedding
     * provider at a container running something else produces vectors of the
     * right width, in the right shape, with no error anywhere -- and a corpus
     * whose queries are embedded by a different model than its passages, which
     * degrades retrieval silently rather than loudly.
     *
     * @return array{name: string, ok: bool, detail: string}
     */
    private function inferenceCheck(string $name, string $url, string $expected): array
    {
        // The provider URL may carry an OpenAI-compatible /v1 suffix; /health
        // and /info sit at the root.
        $root = rtrim(preg_replace('#/v1/?$#', '', $url) ?? $url, '/');

        try {
            $health = Http::timeout(5)->get($root.'/health');

            if (! $health->successful()) {
                return [
                    'name' => $name,
                    'ok' => false,
                    'detail' => "{$root}/health returned {$health->status()}; the model may still be loading",
                ];
            }

            $served = (string) Http::timeout(5)->get($root.'/info')->json('model_id', '');

            return [
                'name' => $name,
                'ok' => $served === $expected,
                'detail' => $served === $expected
                    ? "{$served} at {$root}"
                    : "serving {$served}, but configuration asks for {$expected}",
            ];
        } catch (Throwable $exception) {
            return [
                'name' => $name,
                'ok' => false,
                'detail' => "unreachable at {$root}: ".$exception->getMessage(),
            ];
        }
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function sourceDiskCheck(BookImporter $importer): array
    {
        try {
            $directory = $importer->sourceDirectory();
            $count = count($importer->discover());

            return [
                'name' => 'books disk',
                'ok' => $count > 0,
                'detail' => $count > 0
                    ? "{$count} PDF(s) in {$directory}"
                    : "no PDFs found in {$directory}",
            ];
        } catch (Throwable $exception) {
            return ['name' => 'books disk', 'ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function workingDirectoryCheck(): array
    {
        $path = (string) config('books.temp_path');

        try {
            File::ensureDirectoryExists($path);

            return [
                'name' => 'working directory',
                'ok' => is_writable($path),
                'detail' => is_writable($path) ? $path : "{$path} is not writable",
            ];
        } catch (Throwable $exception) {
            return ['name' => 'working directory', 'ok' => false, 'detail' => $exception->getMessage()];
        }
    }
}
