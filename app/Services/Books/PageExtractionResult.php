<?php

namespace App\Services\Books;

use App\Enums\PageTextSource;

/**
 * One candidate transcription of a single page.
 */
class PageExtractionResult
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public int $pageNumber,
        public PageTextSource $source,
        public ?string $rawText,
        public ?NormalizedPage $normalized,
        public ?QualityScore $quality,
        public array $settings = [],
        public ?int $durationMs = null,
        public ?string $error = null,
    ) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }

    public static function failure(int $pageNumber, PageTextSource $source, string $error): self
    {
        return new self(
            pageNumber: $pageNumber,
            source: $source,
            rawText: null,
            normalized: null,
            quality: null,
            error: $error,
        );
    }
}
