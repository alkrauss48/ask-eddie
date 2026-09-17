<?php

namespace App\Services\Books;

use App\Enums\ChunkKind;
use App\Models\DrinkMention;
use Illuminate\Support\Collection;

/**
 * What the corpus can say about one drink, without the drink row.
 *
 * Shaped like ClassificationContext: a plain carrier so DrinkClassifier can be
 * unit tested against measurements rather than against a database. Everything
 * here is derived from the drink's own mentions, which is what keeps
 * classification a pass over stored rows -- no chunk text, no stream, no PDF.
 */
class DrinkEvidence
{
    public function __construct(
        public int $bookCount = 1,
        public int $mentionCount = 1,
        public float $recipeShare = 0.0,
        public float $capsPrefixShare = 0.0,
        public int $headingFamilies = 1,
        public bool $numberedHeading = false,
    ) {}

    /**
     * @param  Collection<int, DrinkMention>  $mentions
     */
    public static function fromMentions(Collection $mentions): self
    {
        $total = max(1, $mentions->count());

        $inRecipes = $mentions
            ->filter(fn ($mention): bool => in_array(
                $mention->chunk_kind,
                [ChunkKind::Recipe, ChunkKind::RecipeList],
                true,
            ))
            ->count();

        $capsPrefix = $mentions
            ->where('heading_family', HeadingPatterns::CAPS_PREFIX)
            ->count();

        return new self(
            bookCount: $mentions->pluck('book_id')->unique()->count(),
            mentionCount: $mentions->count(),
            recipeShare: $inRecipes / $total,
            capsPrefixShare: $capsPrefix / $total,
            headingFamilies: $mentions->pluck('heading_family')->unique()->count(),
            numberedHeading: $mentions->contains('heading_family', HeadingPatterns::NUMBERED),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function signals(): array
    {
        return [
            'book_count' => $this->bookCount,
            'mention_count' => $this->mentionCount,
            'recipe_share' => round($this->recipeShare, 3),
            'caps_prefix_share' => round($this->capsPrefixShare, 3),
            'heading_families' => $this->headingFamilies,
            'numbered_heading' => $this->numberedHeading,
        ];
    }
}
