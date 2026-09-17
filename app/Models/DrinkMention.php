<?php

namespace App\Models;

use App\Enums\ChunkKind;
use Database\Factories\DrinkMentionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One printed occurrence of a drink's name, addressed by byte offset.
 *
 * The chunk is eager loaded because a mention exists to be cited, and a
 * citation is rendered from the chunk rather than from the columns copied
 * beside it: BookChunk::$with already carries the book, so this one relation
 * is the whole citation. That makes a drink's citation byte-identical to the
 * one SearchTheBooks would render for the same passage by construction rather
 * than by a second formatter kept in step by hand.
 */
class DrinkMention extends Model
{
    /** @use HasFactory<DrinkMentionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $with = ['chunk'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'book_year' => 'integer',
            'chunk_kind' => ChunkKind::class,
            'page_from' => 'integer',
            'page_to' => 'integer',
            'printed_pages_estimated' => 'boolean',
            'char_start' => 'integer',
            'extractor_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Drink, $this>
     */
    public function drink(): BelongsTo
    {
        return $this->belongsTo(Drink::class);
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * @return BelongsTo<BookChunk, $this>
     */
    public function chunk(): BelongsTo
    {
        return $this->belongsTo(BookChunk::class, 'book_chunk_id');
    }

    /**
     * Where this name was printed, exactly as the passage citing it would read.
     */
    public function citation(): string
    {
        return (string) $this->chunk?->citation;
    }
}
