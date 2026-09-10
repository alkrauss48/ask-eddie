<?php

namespace Database\Factories;

use App\Enums\PageStatus;
use App\Enums\PageTextSource;
use App\Models\BookPage;
use App\Models\BookPageExtraction;
use App\Services\Books\PageTextNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookPageExtraction>
 */
class BookPageExtractionFactory extends Factory
{
    public function definition(): array
    {
        $text = $this->faker->paragraph();

        return [
            'book_page_id' => BookPage::factory(),
            'text_source' => PageTextSource::Ocr,
            'raw_text' => $text,
            'text' => $text,
            'quality_score' => $this->faker->randomFloat(4, 0.5, 1.0),
            'score_breakdown' => [],
            'settings' => [],
            'normalizer_version' => PageTextNormalizer::VERSION,
            'duration_ms' => $this->faker->numberBetween(400, 3000),
            'status' => PageStatus::Extracted,
        ];
    }

    public function textLayer(): static
    {
        return $this->state(fn (): array => ['text_source' => PageTextSource::TextLayer]);
    }

    public function scoring(float $score): static
    {
        return $this->state(fn (): array => ['quality_score' => $score]);
    }
}
