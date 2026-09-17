<?php

namespace Database\Factories;

use App\Enums\ChunkKind;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\Drink;
use App\Models\DrinkMention;
use App\Services\Books\DrinkExtractor;
use App\Services\Books\HeadingPatterns;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrinkMention>
 */
class DrinkMentionFactory extends Factory
{
    public function definition(): array
    {
        $page = $this->faker->numberBetween(1, 200);

        return [
            'drink_id' => Drink::factory(),
            'book_id' => Book::factory(),
            'book_chunk_id' => BookChunk::factory(),
            'book_year' => 1900,
            'raw_heading' => 'BLUE LADY',
            'heading_family' => HeadingPatterns::CAPS_PREFIX,
            'heading_number' => null,
            'chunk_kind' => ChunkKind::Recipe,
            'section_title' => null,
            'page_from' => $page,
            'page_to' => $page,
            'printed_page_from' => null,
            'printed_page_to' => null,
            'printed_pages_estimated' => false,
            'char_start' => 0,
            'extractor_version' => DrinkExtractor::VERSION,
        ];
    }

    /**
     * A mention whose copied columns agree with the chunk it points at.
     *
     * --verify asserts exactly this agreement, so a test that wants a sound
     * corpus should build mentions this way rather than by hand.
     */
    public function forChunk(BookChunk $chunk, string $rawHeading, int $offsetInChunk = 0): static
    {
        return $this->state(fn (): array => [
            'book_id' => $chunk->book_id,
            'book_chunk_id' => $chunk->id,
            'book_year' => $chunk->book?->year,
            'raw_heading' => $rawHeading,
            'chunk_kind' => $chunk->kind,
            'section_title' => $chunk->section_title,
            'page_from' => $chunk->page_from,
            'page_to' => $chunk->page_to,
            'printed_page_from' => $chunk->printed_page_from,
            'printed_page_to' => $chunk->printed_page_to,
            'printed_pages_estimated' => $chunk->printed_pages_estimated,
            'char_start' => $chunk->char_start + $offsetInChunk,
        ]);
    }
}
