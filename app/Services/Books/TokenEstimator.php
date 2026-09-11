<?php

namespace App\Services\Books;

/**
 * A deliberately pessimistic token count.
 *
 * No tokenizer exists in this project's dependencies, and the two failure modes
 * are not symmetric. Over-estimating costs a slightly smaller chunk.
 * Under-estimating means an embedding model quietly truncates the tail of a
 * recipe, produces a vector for text nobody chose, and reports nothing. So this
 * takes the larger of two heuristics and then adds a penalty for the things
 * subword tokenizers split hardest.
 *
 * Measured over the promoted corpus: 5,510,224 characters across 962,220 words,
 * or 5.73 characters per word. Plain English prose runs about 4 characters per
 * token; the configured 3.6 accounts for OCR fragments, fractions such as 1/2,
 * and the French, Spanish and Italian drink names.
 */
class TokenEstimator
{
    public function estimate(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        $characters = mb_strlen($text);
        $words = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        // Runs of digits and accented letters both fragment into several tokens
        // apiece, which neither of the two headline heuristics can see.
        $digitRuns = preg_match_all('/\d+/u', $text) ?: 0;
        $nonAscii = preg_match_all('/[^\x00-\x7F]/u', $text) ?: 0;

        $charsPerToken = (float) config('books.chunking.chars_per_token');

        return (int) max(
            ceil($characters / max($charsPerToken, 0.1)),
            ceil($words * 1.4),
        ) + (int) ceil($digitRuns * 0.5) + (int) ceil($nonAscii * 0.4);
    }

    /**
     * The largest chunk of text that can be embedded, in characters.
     *
     * Both bounds matter: the character ceiling is what the packer works in,
     * and the token ceiling is what the embedding model actually enforces.
     */
    public function characterCeiling(): int
    {
        return (int) config('books.chunking.max_chars');
    }

    /**
     * The token budget left for a chunk's stored text once provenance is
     * prefixed at embed time.
     */
    public function tokenCeiling(): int
    {
        return max(
            1,
            (int) config('books.chunking.max_tokens') - (int) config('books.chunking.prefix_reserve_tokens'),
        );
    }

    /**
     * The ceilings a chunk's *own* content is packed to.
     *
     * Overlap is stored as part of the chunk's text, so it spends the same
     * budget. Packing content right up to the ceiling therefore leaves no room
     * for it, and the overlap gets dropped -- which is how prose chunks silently
     * lost their overlap entirely until this reservation was made explicit.
     */
    public function contentCharacterCeiling(): int
    {
        return max(240, $this->characterCeiling() - (int) config('books.chunking.overlap_chars'));
    }

    public function contentTokenCeiling(): int
    {
        $overlapTokens = (int) ceil(
            (int) config('books.chunking.overlap_chars') / max((float) config('books.chunking.chars_per_token'), 0.1)
        );

        return max(60, $this->tokenCeiling() - $overlapTokens);
    }
}
