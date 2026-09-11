<?php

namespace Database\Factories;

use App\Enums\ChunkKind;
use App\Models\Book;
use App\Models\BookChunk;
use App\Services\Books\BookChunker;
use App\Services\Books\ChunkClassifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookChunk>
 */
class BookChunkFactory extends Factory
{
    public function definition(): array
    {
        $text = $this->faker->paragraph();
        $page = $this->faker->numberBetween(1, 200);

        return [
            'book_id' => Book::factory(),
            'book_section_id' => null,
            'chunk_index' => $this->faker->unique()->numberBetween(0, 5000),
            'kind' => ChunkKind::Prose,
            'is_indexable' => true,
            'section_title' => null,
            'heading' => null,
            'headings' => null,
            'text' => $text,
            'char_count' => mb_strlen($text),
            'word_count' => str_word_count($text),
            'token_estimate' => (int) ceil(mb_strlen($text) / 3.6),
            'overlap_chars' => 0,
            'char_start' => 0,
            'char_end' => mb_strlen($text),
            'page_from' => $page,
            'page_to' => $page,
            'printed_page_from' => null,
            'printed_page_to' => null,
            'printed_pages_estimated' => false,
            'signals' => null,
            'chunker_version' => BookChunker::VERSION,
            'classifier_version' => ChunkClassifier::VERSION,
        ];
    }

    /**
     * A chunk cut by an older set of rules, which books:chunk should rebuild.
     */
    public function stale(): static
    {
        return $this->state(fn (): array => [
            'chunker_version' => BookChunker::VERSION - 1,
        ]);
    }

    public function noise(): static
    {
        return $this->state(fn (): array => [
            'kind' => ChunkKind::Noise,
            'is_indexable' => false,
            'text' => '3%',
            'char_count' => 2,
            'word_count' => 1,
        ]);
    }

    /**
     * Retained, but kept out of the vector index.
     */
    public function excluded(): static
    {
        return $this->state(fn (): array => [
            'kind' => ChunkKind::Index,
            'is_indexable' => false,
        ]);
    }

    public function recipe(): static
    {
        return $this->state(function (): array {
            $text = "BLUE LADY 1/2 Blue Curasao (Gamier).\n1/4 Booth's Gin.\nShake and strain.";

            return [
                'kind' => ChunkKind::Recipe,
                'heading' => 'BLUE LADY',
                'headings' => ['BLUE LADY'],
                'text' => $text,
                'char_count' => mb_strlen($text),
                'word_count' => str_word_count($text),
            ];
        });
    }
}
