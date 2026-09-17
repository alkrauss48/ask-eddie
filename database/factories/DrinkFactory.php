<?php

namespace Database\Factories;

use App\Models\Drink;
use App\Services\Books\DrinkExtractor;
use App\Services\Books\DrinkNameNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Drink>
 */
class DrinkFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);
        $display = Str::title($name);

        return [
            'slug' => Str::slug($display),
            'canonical_name' => $display,
            'canonical_key' => app(DrinkNameNormalizer::class)->key($display),
            'aliases' => [$display => 1],
            'mention_count' => 1,
            'book_count' => 1,
            'first_year' => 1900,
            'last_year' => 1900,
            'first_book_id' => null,
            'extractor_version' => DrinkExtractor::VERSION,
            'normalizer_version' => DrinkNameNormalizer::VERSION,
        ];
    }

    /**
     * A drink named exactly as given, folded the way the extractor would fold it.
     */
    public function named(string $name): static
    {
        return $this->state(fn (): array => [
            'slug' => Str::slug($name),
            'canonical_name' => $name,
            'canonical_key' => app(DrinkNameNormalizer::class)->key($name),
            'aliases' => [$name => 1],
        ]);
    }

    /**
     * Printed in many books over a long span: the shape of a common drink.
     */
    public function ubiquitous(): static
    {
        return $this->state(fn (): array => [
            'mention_count' => 34,
            'book_count' => 19,
            'first_year' => 1862,
            'last_year' => 1937,
        ]);
    }

    /**
     * Folded by rules that have since moved, which books:drinks should redo.
     */
    public function stale(): static
    {
        return $this->state(fn (): array => [
            'normalizer_version' => DrinkNameNormalizer::VERSION - 1,
        ]);
    }
}
