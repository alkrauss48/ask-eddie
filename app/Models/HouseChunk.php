<?php

namespace App\Models;

use App\Enums\HouseSourceType;
use App\Services\House\RenderedChunk;
use App\Services\Retrieval\RetrievablePassage;
use Database\Factories\HouseChunkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One retrievable passage of the house's own menus, addressed by slug.
 *
 * The counterpart of BookChunk, and held to the same discipline for the same
 * reason: this model's toArray() is the payload a language model is handed at
 * answer time, so $hidden and $appends below are a product decision rather than
 * presentation tidying. HouseChunkCitationTest asserts the payload by *count*,
 * which is what stops a column added to this table from arriving in a prompt
 * because nobody remembered to hide it.
 *
 * What differs from BookChunk is provenance. A book passage proves itself with
 * a byte offset into a scan; a house passage proves itself with a URL a guest
 * can open. There is no page number, no printed/physical distinction and no
 * offset arithmetic here, because nothing was cut: one record renders to
 * exactly one chunk.
 */
class HouseChunk extends Model implements RetrievablePassage
{
    /** @use HasFactory<HouseChunkFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Everything the model needs but the prompt does not.
     *
     * keywords is hidden deliberately, and it is the one that looks like an
     * omission. It exists to feed the B-weighted half of the lexical index --
     * it is an index artefact, the same standing search_vector has, and its
     * content already reaches the model as prose inside "text". Handing the
     * flattened vocabulary over as well would put a comma-separated tag dump in
     * a bartender's context for her to read back out.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        // Hidden in favour of the "kind" accessor, which renders the same fact
        // as a word rather than as an enum value. Both would be the same fact
        // twice, and a prompt that shows a model two spellings of one thing
        // invites it to quote the wrong one.
        'source_type',
        'source_slug',
        'source_id',
        'subtitle',
        'keywords',
        'content_hash',
        'renderer_version',
        'char_count',
        'word_count',
        'token_estimate',
        'is_indexable',
        // The vector, the tsvector, and the provenance of the embedding. A
        // single omission here is all it takes for retrieval bookkeeping to
        // land in a prompt, which is why the payload is asserted by count.
        'embedding',
        'embedding_model',
        'embedding_dimensions',
        'embedder_version',
        'embedded_at',
        'embedded_content_hash',
        'search_vector',
        'created_at',
        'updated_at',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['kind', 'citation'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => HouseSourceType::class,
            'source_id' => 'integer',
            'keywords' => 'array',
            'char_count' => 'integer',
            'word_count' => 'integer',
            'token_estimate' => 'integer',
            'is_indexable' => 'boolean',
            'renderer_version' => 'integer',
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
     * the text again; neither is ever read in PHP. Columns are named rather
     * than excluded with a wildcard so that adding one to the table is a
     * deliberate decision here too.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForRetrieval(Builder $query): void
    {
        $query->select([
            'id',
            'source_type',
            'source_slug',
            'source_id',
            'title',
            'subtitle',
            'text',
            'keywords',
            'url',
            'content_hash',
            'renderer_version',
            'char_count',
            'word_count',
            'token_estimate',
            'is_indexable',
            'embedding_model',
            'embedding_dimensions',
            'embedder_version',
            'embedded_at',
            'embedded_content_hash',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * The string handed to the embedder.
     *
     * Derived rather than stored, so "text" stays exactly what the renderer
     * produced and a citation can be checked by re-rendering the record.
     *
     * A cocktail repeats its own name here, once in the prefix and once at the
     * head of the body, which is deliberate and is the same call BookChunk
     * makes: on a site that is mostly drink names, the name is the most
     * searchable thing on the page.
     */
    public function embeddingText(): string
    {
        return RenderedChunk::compose(
            $this->title,
            $this->subtitle,
            $this->source_type,
            $this->text,
        );
    }

    /**
     * What house:embed hashes, and what a stale render is measured against.
     */
    public function currentContentHash(): string
    {
        return hash('sha256', $this->embeddingText());
    }

    /**
     * @return Attribute<string, never>
     */
    protected function kind(): Attribute
    {
        return Attribute::get(fn (): string => $this->source_type->label());
    }

    /**
     * The whole citation as one line, for a prompt or a footnote.
     *
     * A house citation is a link rather than a page, so the URL is part of the
     * sentence rather than a separate field a model might not think to quote.
     *
     * @return Attribute<string, never>
     */
    protected function citation(): Attribute
    {
        return Attribute::get(fn (): string => implode(' ', array_filter([
            $this->title,
            $this->subtitle === null ? null : '('.$this->subtitle.')',
            '— '.$this->url,
        ])));
    }
}
