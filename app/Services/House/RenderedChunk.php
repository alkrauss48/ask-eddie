<?php

namespace App\Services\House;

use App\Enums\HouseSourceType;

/**
 * One record rendered, but not yet persisted.
 *
 * The house equivalent of PendingChunk, and much simpler than it: nothing here
 * was cut out of a longer stream, so there are no offsets, no overlap and no
 * page arithmetic to carry. A record renders to exactly one of these.
 */
class RenderedChunk
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public HouseSourceType $type,
        public string $slug,
        public ?int $sourceId,
        public string $title,
        public ?string $subtitle,
        public string $text,
        public array $keywords,
        public string $url,
    ) {}

    /**
     * The exact string the embedding model is handed.
     *
     * Static, and shared with HouseChunk::embeddingText(), because the hash
     * below is what decides whether a stored vector is still current. Two
     * definitions of "the embedded string" would mean a corpus that is pending
     * forever or one that is stale and says it is fine.
     */
    public static function compose(string $title, ?string $subtitle, HouseSourceType $type, string $text): string
    {
        $prefix = implode(' — ', array_filter([
            $title,
            $subtitle,
            $type->label(),
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        return $prefix."\n\n".$text;
    }

    public function embeddingText(): string
    {
        return self::compose($this->title, $this->subtitle, $this->type, $this->text);
    }

    /**
     * sha256 over the embedded string, prefix included.
     *
     * Over the whole string rather than over "text" alone, because the prefix
     * carries the title: a cocktail that is renamed and nothing else must still
     * be re-embedded, and a hash of the body could not say so.
     */
    public function contentHash(): string
    {
        return hash('sha256', $this->embeddingText());
    }
}
