<?php

namespace App\Services\Books;

use App\Enums\PageStatus;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\BookPageExtraction;

/**
 * Chooses which stored candidate becomes a page's canonical text.
 *
 * Keeping this separate from extraction is what makes the decision cheap to
 * revisit: changing a book's preferred source re-runs a query rather than
 * another hour of OCR.
 */
class PagePromoter
{
    /**
     * Promote the best candidate for every page of a book.
     *
     * @return int The number of pages whose promoted text changed.
     */
    public function promoteBook(Book $book): int
    {
        $promoted = 0;

        $book->pages()->with('extractions')->chunkById(200, function ($pages) use ($book, &$promoted): void {
            foreach ($pages as $page) {
                if ($this->promote($page, $book)) {
                    $promoted++;
                }
            }
        });

        return $promoted;
    }

    /**
     * @return bool Whether the page's promoted text changed.
     */
    public function promote(BookPage $page, ?Book $book = null): bool
    {
        $book ??= $page->book;
        $winner = $this->pick($page, $book);

        if ($winner === null) {
            return $this->markBlankIfExhausted($page);
        }

        $changed = $page->promoted_extraction_id !== $winner->id
            || $page->text !== $winner->text;

        $normalized = new NormalizedPage($winner->text ?? '');

        $page->forceFill([
            'text' => $winner->text,
            'text_source' => $winner->text_source,
            'promoted_extraction_id' => $winner->id,
            'char_count' => $normalized->characterCount(),
            'word_count' => $normalized->wordCount(),
            'status' => PageStatus::Extracted,
        ])->save();

        return $changed;
    }

    /**
     * Record a page as blank once every candidate has run and come back empty.
     *
     * Blank leaves and plate versos are ordinary in these scans -- one book has
     * one every sixteenth page, which is simply how its gatherings were bound.
     * Leaving them marked failed would misreport the run and hide real failures
     * among them.
     *
     * @return bool Whether the page's state changed.
     */
    private function markBlankIfExhausted(BookPage $page): bool
    {
        $extractions = $page->extractions;

        if ($extractions->isEmpty()) {
            return false;
        }

        // Anything still failing is a real failure and must stay one.
        if ($extractions->contains(fn (BookPageExtraction $extraction): bool => $extraction->status !== PageStatus::Extracted)) {
            return false;
        }

        if ($page->status === PageStatus::Blank) {
            return false;
        }

        $page->forceFill([
            'text' => null,
            'text_source' => null,
            'promoted_extraction_id' => null,
            'char_count' => 0,
            'word_count' => 0,
            'status' => PageStatus::Blank,
        ])->save();

        return true;
    }

    /**
     * Prefer the book's configured source when one is set, otherwise take the
     * highest scoring candidate. An unscorable candidate still beats nothing.
     */
    private function pick(BookPage $page, Book $book): ?BookPageExtraction
    {
        // A blank result is not a candidate. Pages really are blank sometimes,
        // but so is a failed render, and promoting empty text would mark the
        // page done and hide the failure.
        $candidates = $page->extractions
            ->where('status', PageStatus::Extracted)
            ->filter(fn (BookPageExtraction $extraction): bool => trim((string) $extraction->text) !== '');

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($book->preferred_text_source !== null) {
            $preferred = $candidates->firstWhere('text_source', $book->preferred_text_source);

            if ($preferred !== null) {
                return $preferred;
            }
        }

        return $candidates
            ->sortByDesc(fn (BookPageExtraction $extraction): float => $extraction->quality_score ?? -1.0)
            ->first();
    }
}
