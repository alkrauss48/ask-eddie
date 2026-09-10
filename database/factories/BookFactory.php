<?php

namespace Database\Factories;

use App\Enums\BookStatus;
use App\Models\Book;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    public function definition(): array
    {
        $title = $this->faker->unique()->sentence(3);
        $year = $this->faker->numberBetween(1827, 1939);

        return [
            'slug' => Str::slug($title).'-'.$year,
            'title' => rtrim($title, '.'),
            'author' => $this->faker->name(),
            'year' => $year,
            'language' => 'eng',
            'source_filename' => $this->faker->unique()->slug(3).'.pdf',
            'checksum' => hash('sha256', Str::random()),
            'file_size' => $this->faker->numberBetween(1_000_000, 200_000_000),
            'file_modified_at' => now(),
            'page_count' => $this->faker->numberBetween(40, 320),
            'status' => BookStatus::Pending,
            'metadata' => [],
        ];
    }

    public function extracted(): static
    {
        return $this->state(fn (): array => [
            'status' => BookStatus::Extracted,
            'extracted_at' => now(),
        ]);
    }

    public function missing(): static
    {
        return $this->state(fn (): array => ['status' => BookStatus::Missing]);
    }
}
