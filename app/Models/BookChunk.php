<?php

namespace App\Models;

use App\Enums\ChunkKind;
use Database\Factories\BookChunkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One retrievable passage of a book, addressed by page range.
 *
 * This model's toArray() output is not a convenience: it is literally the
 * payload the language model is handed at answer time. Laravel's
 * SimilaritySearch tool runs
 *
 *     $model::query()->whereVectorSimilarTo(...)->limit(...)->get()
 *         ->map(fn ($model) => Arr::except($model->toArray(), ['embedding']))
 *
 * so it strips the vector and passes everything else through verbatim, and it
 * never calls with(). That is why $with, $hidden and $appends below are a
 * product decision rather than presentation tidying, and why they are covered
 * by their own test.
 */
class BookChunk extends Model
{
    /** @use HasFactory<BookChunkFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The retrieval query is built by the SimilaritySearch tool, which gives no
     * opportunity to eager load. Without this, every citation costs a query per
     * chunk to name the book it came from.
     *
     * @var list<string>
     */
    protected $with = ['book:id,slug,title,author,year'];

    /**
     * Everything the model needs but the prompt does not.
     *
     * Offsets, counts, versions and classifier evidence are all operational.
     * The raw page numbers are hidden because the "pages" accessor renders both
     * the printed and the physical page together, and showing them twice invites
     * the model to cite the wrong one.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'book_id',
        'book_section_id',
        'chunk_index',
        'kind',
        'is_indexable',
        'headings',
        'char_count',
        'word_count',
        'token_estimate',
        'overlap_chars',
        'char_start',
        'char_end',
        'page_from',
        'page_to',
        'printed_page_from',
        'printed_page_to',
        'printed_pages_estimated',
        'signals',
        'chunker_version',
        'classifier_version',
        // The vector itself, the tsvector, and the provenance of the embedding.
        // A single omission here is all it takes for retrieval bookkeeping to
        // land in a prompt, which is why the payload is asserted by count.
        'embedding',
        'embedding_model',
        'embedding_dimensions',
        'embedder_version',
        'embedded_at',
        'search_vector',
        'created_at',
        'updated_at',
        'book',
        'section',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['book_title', 'author', 'year', 'pages', 'citation'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'kind' => ChunkKind::class,
            'is_indexable' => 'boolean',
            'headings' => 'array',
            'char_count' => 'integer',
            'word_count' => 'integer',
            'token_estimate' => 'integer',
            'overlap_chars' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
            'page_from' => 'integer',
            'page_to' => 'integer',
            'printed_pages_estimated' => 'boolean',
            'signals' => 'array',
            'chunker_version' => 'integer',
            'classifier_version' => 'integer',
            'embedding' => AsVector::class,
            'embedding_dimensions' => 'integer',
            'embedder_version' => 'integer',
            'embedded_at' => 'datetime',
        ];
    }

    /**
     * Select everything retrieval needs and nothing it does not.
     *
     * The vector is 4 KB a row and the stored tsvector is roughly the size of
     * the text again; hydrating either for a page of citations is pure waste,
     * and neither is ever read in PHP. Columns are named rather than excluded
     * with a wildcard so that adding one to the table is a deliberate decision
     * here too -- the same reason $hidden is spelled out above.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForRetrieval(Builder $query): void
    {
        $query->select([
            'id',
            'book_id',
            'book_section_id',
            'chunk_index',
            'kind',
            'is_indexable',
            'section_title',
            'heading',
            'headings',
            'text',
            'char_count',
            'word_count',
            'token_estimate',
            'overlap_chars',
            'char_start',
            'char_end',
            'page_from',
            'page_to',
            'printed_page_from',
            'printed_page_to',
            'printed_pages_estimated',
            'signals',
            'chunker_version',
            'classifier_version',
            'embedding_model',
            'embedding_dimensions',
            'embedder_version',
            'embedded_at',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * @return BelongsTo<BookSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(BookSection::class, 'book_section_id');
    }

    /**
     * The string handed to the embedder.
     *
     * Derived rather than stored, so that "text" stays byte-identical to the
     * corpus and any citation can be checked by re-slicing the book's stream.
     *
     * The provenance prefix exists because a query like "a 1930s London gin
     * cocktail" has to match on facts the passage never states. A recipe chunk
     * repeats its own heading here, once in the prefix and once in the body,
     * which is deliberate: in books that are nothing but drink lists, the name
     * is the most searchable thing on the page.
     */
    public function embeddingText(): string
    {
        $prefix = implode(' — ', array_filter([
            $this->bookLabel(),
            $this->section_title,
            $this->heading,
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        return $prefix === '' ? $this->text : $prefix."\n\n".$this->text;
    }

    /**
     * The book as it would be named in a citation, with its year.
     */
    public function bookLabel(): string
    {
        $title = (string) $this->book?->title;

        return $this->book?->year === null ? $title : "{$title} ({$this->book->year})";
    }

    /**
     * @return Attribute<string, never>
     */
    protected function bookTitle(): Attribute
    {
        return Attribute::get(fn (): string => (string) $this->book?->title);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function author(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->book?->author);
    }

    /**
     * @return Attribute<int|null, never>
     */
    protected function year(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->book?->year);
    }

    /**
     * Where this passage sits, printed page first and physical page always.
     *
     * The printed number is what a reader with a paper copy needs; the physical
     * page is the one anyone can verify by opening the PDF, so it is never
     * omitted. A tilde marks a printed number that was interpolated from the
     * book's numbering series rather than read off the page.
     *
     * @return Attribute<string, never>
     */
    protected function pages(): Attribute
    {
        return Attribute::get(function (): string {
            $physical = $this->range('PDF p. ', 'PDF pp. ', (string) $this->page_from, (string) $this->page_to);

            if ($this->printed_page_from === null && $this->printed_page_to === null) {
                return $physical;
            }

            $mark = $this->printed_pages_estimated ? '~' : '';
            $printed = $this->range(
                'p. ',
                'pp. ',
                $mark.($this->printed_page_from ?? $this->printed_page_to),
                $mark.($this->printed_page_to ?? $this->printed_page_from),
            );

            return $printed.' ('.$physical.')';
        });
    }

    /**
     * The whole citation as one line, for a prompt or a footnote.
     *
     * @return Attribute<string, never>
     */
    protected function citation(): Attribute
    {
        return Attribute::get(fn (): string => implode(', ', array_filter([
            $this->bookLabel(),
            $this->section_title === null ? null : '"'.$this->section_title.'"',
            $this->pages,
        ])));
    }

    /**
     * Render a range, collapsing it when both ends are the same page.
     */
    private function range(string $singular, string $plural, string $from, string $to): string
    {
        return $from === $to ? $singular.$from : $plural.$from.'–'.$to;
    }
}
