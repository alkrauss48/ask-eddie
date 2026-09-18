<?php

namespace App\Services\House;

use RuntimeException;

/**
 * The JSON export from the-krauss-haus, read off the house disk.
 *
 * The site's content is 239 hand-authored TypeScript modules whose records
 * reference each other by live object reference, so nothing can be parsed out
 * of them from here: the export evaluates them through Vite and writes
 * slug-referenced JSON, and this class is the consumer of that contract.
 *
 * Everything is read once and held, because the whole corpus is well under a
 * megabyte and an import reads each file several times over.
 */
class HouseExport
{
    /**
     * Written in this order by the exporter, and concatenated in this order to
     * form the manifest checksum. The order is part of the contract: change it
     * and the checksum stops reproducing, with nothing to say why.
     *
     * @var list<string>
     */
    public const DATASETS = [
        'cocktails',
        'ingredients',
        'recipes',
        'bartenders',
        'paths',
        'tags',
        'menus',
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private array $datasets = [];

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /**
     * The absolute root of the configured house disk.
     *
     * Local only, and checked rather than assumed: these files are read with
     * file_get_contents so the checksum is taken over exactly the bytes the
     * exporter wrote, which a remote adapter's stream could not promise.
     */
    public function sourceDirectory(): string
    {
        $disk = (string) config('house.disk');
        $driver = config("filesystems.disks.{$disk}.driver");

        if ($driver !== 'local') {
            throw new RuntimeException(
                "The house disk [{$disk}] uses the [{$driver}] driver; only local disks are supported."
            );
        }

        return rtrim((string) config("filesystems.disks.{$disk}.root"), '/');
    }

    /**
     * Whether every file the import needs is present.
     */
    public function isAvailable(): bool
    {
        return $this->missingFiles() === [];
    }

    /**
     * @return list<string> the dataset files that are not on the disk
     */
    public function missingFiles(): array
    {
        $directory = $this->sourceDirectory();

        if (! is_dir($directory)) {
            return array_map(fn (string $name): string => $name.'.json', [...self::DATASETS, 'manifest']);
        }

        return array_values(array_filter(
            array_map(fn (string $name): string => $name.'.json', [...self::DATASETS, 'manifest']),
            fn (string $file): bool => ! is_file($directory.'/'.$file),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dataset(string $name): array
    {
        if (! in_array($name, self::DATASETS, true)) {
            throw new RuntimeException("Unknown house dataset [{$name}].");
        }

        return $this->datasets[$name] ??= $this->decode($name);
    }

    /** @return list<array<string, mixed>> */
    public function cocktails(): array
    {
        return $this->dataset('cocktails');
    }

    /** @return list<array<string, mixed>> */
    public function ingredients(): array
    {
        return $this->dataset('ingredients');
    }

    /** @return list<array<string, mixed>> */
    public function recipes(): array
    {
        return $this->dataset('recipes');
    }

    /** @return list<array<string, mixed>> */
    public function bartenders(): array
    {
        return $this->dataset('bartenders');
    }

    /** @return list<array<string, mixed>> */
    public function paths(): array
    {
        return $this->dataset('paths');
    }

    /** @return list<array<string, mixed>> the tag categories, each with its own tags */
    public function tagCategories(): array
    {
        return $this->dataset('tags');
    }

    /** @return list<array<string, mixed>> */
    public function menus(): array
    {
        return $this->dataset('menus');
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $decoded = json_decode($this->read('manifest'), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('manifest.json is not a JSON object.');
        }

        return $this->manifest = $decoded;
    }

    public function checksum(): ?string
    {
        $checksum = $this->manifest()['checksum'] ?? null;

        return is_string($checksum) ? $checksum : null;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = $this->manifest()['counts'] ?? [];

        return is_array($counts) ? array_map(intval(...), $counts) : [];
    }

    public function generatedAt(): ?string
    {
        $generated = $this->manifest()['generated_at'] ?? null;

        return is_string($generated) ? $generated : null;
    }

    /**
     * Recompute the manifest's checksum from the files on disk.
     *
     * Taken over the raw bytes of each dataset file, concatenated in DATASETS
     * order, which is exactly what the exporter hashes. Decoding and re-encoding
     * would not reproduce it -- and should not: the point of the check is that
     * these are the same bytes that repository committed, not that they hold
     * equivalent data.
     */
    public function computeChecksum(): string
    {
        $hash = hash_init('sha256');

        foreach (self::DATASETS as $name) {
            hash_update($hash, $this->read($name));
        }

        return hash_final($hash);
    }

    /**
     * The counts the files actually hold, in the manifest's own vocabulary.
     *
     * @return array<string, int>
     */
    public function actualCounts(): array
    {
        $categories = $this->tagCategories();

        return [
            'cocktails' => count($this->cocktails()),
            'ingredients' => count($this->ingredients()),
            'recipes' => count($this->recipes()),
            'bartenders' => count($this->bartenders()),
            'paths' => count($this->paths()),
            'tag_categories' => count($categories),
            'tags' => array_sum(array_map(
                fn (array $category): int => count($category['tags'] ?? []),
                $categories,
            )),
            'menus' => count($this->menus()),
        ];
    }

    /**
     * @return list<array<string, mixed>> every tag, flattened, carrying its category label
     */
    public function tags(): array
    {
        $tags = [];

        foreach ($this->tagCategories() as $category) {
            foreach ($category['tags'] ?? [] as $tag) {
                $tags[] = [
                    'label' => (string) $tag['label'],
                    'category_label' => (string) $category['label'],
                    'order' => (int) ($tag['order'] ?? 0),
                ];
            }
        }

        return $tags;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(string $name): array
    {
        $decoded = json_decode($this->read($name), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$name}.json is not a JSON array.");
        }

        return array_values($decoded);
    }

    private function read(string $name): string
    {
        $path = $this->sourceDirectory().'/'.$name.'.json';

        if (! is_file($path)) {
            throw new RuntimeException(
                "The house export is missing [{$name}.json]. Run `npm run export:data` in the-krauss-haus, or check HOUSE_PATH."
            );
        }

        return (string) file_get_contents($path);
    }
}
