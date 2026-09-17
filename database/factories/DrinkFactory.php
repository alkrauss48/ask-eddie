<?php

namespace Database\Factories;

use App\Models\Drink;
use App\Services\Books\DrinkClassifier;
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
            // Countable by default even though the default book_count is 1,
            // because a factory drink stands for a drink the tally can see. A
            // test that wants the tail asks for it with uncountable().
            'is_countable' => true,
            'signals' => null,
            'mention_count' => 1,
            'book_count' => 1,
            'first_year' => 1900,
            'last_year' => 1900,
            'first_book_id' => null,
            'extractor_version' => DrinkExtractor::VERSION,
            'normalizer_version' => DrinkNameNormalizer::VERSION,
            'classifier_version' => DrinkClassifier::VERSION,
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
     * A row the classifier set aside: present, cited, and never counted.
     *
     * The shape of the 7,011 single-book rows the first real run produced --
     * OCR wreckage and prose a heading pattern caught. Its mentions stay real,
     * which is the point of classifying rather than deleting.
     */
    public function uncountable(string $reason = 'single_book'): static
    {
        return $this->state(fn (): array => [
            'is_countable' => false,
            'signals' => ['reason' => $reason, 'book_count' => 1],
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
