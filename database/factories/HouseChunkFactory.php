<?php

namespace Database\Factories;

use App\Enums\HouseSourceType;
use App\Models\HouseChunk;
use App\Services\House\HouseEmbedder;
use App\Services\House\HouseRenderer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HouseChunk>
 */
class HouseChunkFactory extends Factory
{
    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);
        $title = $this->faker->words(2, true);
        $text = $title."\n\n".$this->faker->paragraph();

        return [
            'source_type' => HouseSourceType::Cocktail,
            'source_slug' => $slug,
            'source_id' => null,
            'title' => $title,
            'subtitle' => null,
            'text' => $text,
            'keywords' => [$title],
            'url' => 'https://thekrausshaus.com/cocktails/'.$slug,
            // Computed rather than faked, because "pending" is defined as this
            // hash disagreeing with embedded_content_hash: a made-up value here
            // would make every factory-built chunk permanently stale.
            'content_hash' => hash('sha256', HouseChunk::make([
                'source_type' => HouseSourceType::Cocktail,
                'title' => $title,
                'subtitle' => null,
                'text' => $text,
            ])->embeddingText()),
            'renderer_version' => HouseRenderer::VERSION,
            'char_count' => mb_strlen($text),
            'word_count' => str_word_count($text),
            'token_estimate' => (int) ceil(mb_strlen($text) / 3.6),
            'is_indexable' => true,
        ];
    }

    /**
     * A chunk that has been through house:embed.
     *
     * Callers asserting on ordering should pass a one-hot vector: cosine
     * similarity between two one-hot vectors is exactly 0 or exactly 1, which
     * makes an ordering assertion exact rather than nearly always true.
     *
     * @param  list<float>|null  $vector
     */
    public function embedded(?array $vector = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'embedding' => $vector ?? array_fill(0, (int) config('house.embedding.dimensions'), 0.0),
            'embedding_model' => (string) config('house.embedding.model'),
            'embedding_dimensions' => (int) config('house.embedding.dimensions'),
            'embedder_version' => HouseEmbedder::VERSION,
            'embedded_content_hash' => $attributes['content_hash'],
            'embedded_at' => now(),
        ]);
    }

    /**
     * A chunk whose render has moved since it was embedded.
     *
     * The house's own staleness signal, and the reason it is a hash rather than
     * books' embedded_at < updated_at comparison: this state is *pending*, not
     * broken, and the next run fixes it without anyone being told.
     */
    public function reRendered(): static
    {
        return $this->state(fn (): array => [
            'embedded_content_hash' => hash('sha256', 'an older render'),
        ]);
    }

    public function ofType(HouseSourceType $type): static
    {
        return $this->state(fn (): array => ['source_type' => $type]);
    }

    public function excluded(): static
    {
        return $this->state(fn (): array => ['is_indexable' => false]);
    }
}
