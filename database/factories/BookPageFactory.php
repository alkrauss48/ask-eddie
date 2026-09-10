<?php

namespace Database\Factories;

use App\Enums\PageStatus;
use App\Enums\PageTextSource;
use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookPage>
 */
class BookPageFactory extends Factory
{
    public function definition(): array
    {
        $text = $this->faker->paragraph();

        return [
            'book_id' => Book::factory(),
            'page_number' => $this->faker->unique()->numberBetween(1, 400),
            'printed_page_label' => null,
            'text' => $text,
            'text_source' => PageTextSource::Ocr,
            'char_count' => mb_strlen($text),
            'word_count' => str_word_count($text),
            'status' => PageStatus::Extracted,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'text' => null,
            'text_source' => null,
            'char_count' => 0,
            'word_count' => 0,
            'status' => PageStatus::Pending,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'text' => null,
            'text_source' => null,
            'status' => PageStatus::Failed,
        ]);
    }
}
