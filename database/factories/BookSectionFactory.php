<?php

namespace Database\Factories;

use App\Enums\SectionKind;
use App\Models\Book;
use App\Models\BookSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookSection>
 */
class BookSectionFactory extends Factory
{
    public function definition(): array
    {
        $from = $this->faker->numberBetween(1, 200);

        return [
            'book_id' => Book::factory(),
            'sequence' => $this->faker->unique()->numberBetween(0, 100),
            'title' => $this->faker->words(2, true),
            'kind' => SectionKind::Chapter,
            'page_from' => $from,
            'page_to' => $from + $this->faker->numberBetween(0, 40),
            'head_variants' => null,
            'confidence' => 1.0,
        ];
    }

    public function runningHead(): static
    {
        return $this->state(fn (): array => [
            'kind' => SectionKind::RunningHead,
            'head_variants' => ['Old Waldotf Bar Days' => [30, 96]],
        ]);
    }

    public function untitled(): static
    {
        return $this->state(fn (): array => [
            'title' => null,
            'kind' => SectionKind::Body,
            'confidence' => null,
        ]);
    }
}
