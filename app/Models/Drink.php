<?php

namespace App\Models;

use App\Services\Retrieval\DrinkTally;
use Database\Factories\DrinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One drink, as the corpus prints it.
 *
 * Unlike BookChunk, this model's toArray() is not a payload and never reaches
 * a prompt. BookChunk had to become one because laravel/ai's SimilaritySearch
 * serializes the model out of the application's reach; nothing does that here,
 * so the safer construction is available and DrinkSummary carries the payload
 * instead. A column added to this table cannot leak into an answer.
 */
class Drink extends Model
{
    /** @use HasFactory<DrinkFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'signals' => 'array',
            'is_countable' => 'boolean',
            'mention_count' => 'integer',
            'book_count' => 'integer',
            'first_year' => 'integer',
            'last_year' => 'integer',
            'extractor_version' => 'integer',
            'normalizer_version' => 'integer',
            'classifier_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<DrinkMention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(DrinkMention::class);
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function firstBook(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'first_book_id');
    }

    /**
     * The span of years this drink was printed across, as a citation renders it.
     *
     * Collapsed when both ends agree, the same way BookChunk::pages collapses a
     * single-page range, and empty when no book carrying it records a year.
     */
    public function yearRange(): string
    {
        return DrinkTally::fromDrink($this)->yearRange();
    }

    /**
     * Every spelling other than the canonical one, most printed first.
     *
     * @return list<string>
     */
    public function otherSpellings(): array
    {
        $aliases = $this->aliases ?? [];

        arsort($aliases);

        return array_values(array_filter(
            array_keys($aliases),
            fn (string $spelling): bool => $spelling !== $this->canonical_name,
        ));
    }
}
