<?php

namespace App\Services\Retrieval;

use App\Models\Drink;

/**
 * One drink as the language model is handed it.
 *
 * A value object rather than the model, and that is a deliberate divergence
 * from BookChunk. BookChunk::toArray() had to *become* its payload because
 * laravel/ai's SimilaritySearch serializes the model out of the application's
 * reach; nothing does that here, so Drink::toArray() never reaches a prompt at
 * all and no future $hidden omission can leak a column into one. It is the same
 * separation RetrievedChunk makes, arrived at from the other side.
 *
 * Six keys, asserted by count rather than by subset -- the discipline
 * BookChunkCitationTest and SearchTheBooksToolTest both hold. No id, no slug,
 * no folded key, no version, no score.
 */
readonly class DrinkSummary
{
    /**
     * @param  list<string>  $otherSpellings
     * @param  list<string>  $citations
     */
    public function __construct(
        public string $name,
        public int $bookCount,
        public int $mentionCount,
        public string $years,
        public array $otherSpellings,
        public array $citations,
    ) {}

    /**
     * The counts come from the tally rather than from the model, because a
     * question with year bounds is answered with the numbers inside them --
     * "three of the six books I have from the sixties", not the 27 the whole
     * shelf prints. With no bounds the tally is the model's own columns.
     *
     * @param  list<string>  $otherSpellings
     * @param  list<string>  $citations
     */
    public static function fromDrink(Drink $drink, DrinkTally $tally, array $otherSpellings, array $citations): self
    {
        return new self(
            $drink->canonical_name,
            $tally->bookCount,
            $tally->mentionCount,
            $tally->yearRange(),
            $otherSpellings,
            $citations,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => $this->name,
            'books' => $this->bookCount,
            'mentions' => $this->mentionCount,
            'years' => $this->years,
            'also_printed_as' => $this->otherSpellings,
            'citations' => $this->citations,
        ];
    }
}
