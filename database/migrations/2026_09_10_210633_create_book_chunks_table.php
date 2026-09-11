<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_section_id')->nullable()->constrained()->nullOnDelete();

            // Continuous across the whole book. The Python original restarted
            // this at every section, which made a chunk's neighbours
            // unfindable and its id unstable.
            $table->unsignedInteger('chunk_index');

            $table->string('kind');

            // Derived from kind, but stored so that excluding non-content text
            // from retrieval is one indexed predicate rather than a join and a
            // match, and so that re-deciding is an update rather than a
            // re-chunk. Nothing is ever deleted for being unindexable.
            $table->boolean('is_indexable')->default(true);

            // Copied from the section for the citation payload. The foreign key
            // is the source of truth; this string is what reaches the model, so
            // that a missing relation can never render as a null section.
            $table->string('section_title')->nullable();

            // The in-page heading this chunk opens with, and every heading it
            // contains. A packed block of ten Cafe Royal recipes names all ten,
            // so retrieval can point at one drink rather than the block.
            $table->string('heading')->nullable();
            $table->json('headings')->nullable();

            // Verbatim corpus text: never prefixed, never reordered, and with
            // the heading left in place. The Python lifted the heading out of
            // the body and then embedded the body alone, so no drink name ever
            // reached a vector. Provenance is prefixed at embed time instead;
            // see BookChunk::embeddingText().
            $table->text('text');

            $table->unsignedInteger('char_count');
            $table->unsignedInteger('word_count');

            // Of the embedded string rather than of text, because the
            // provenance prefix spends part of the same context window.
            $table->unsignedInteger('token_estimate');

            // Leading characters of text carried over from the previous chunk.
            // Excluded from the page range below, so a chunk never cites a page
            // it only borrowed a preamble from.
            $table->unsignedInteger('overlap_chars')->default(0);

            // Offsets into the book's assembled text stream. These are what
            // make a citation checkable rather than merely plausible:
            // books:chunks --verify re-assembles the stream and asserts every
            // chunk is exactly the slice it claims to be, and that the chunks
            // together account for every character of the book.
            $table->unsignedInteger('char_start');
            $table->unsignedInteger('char_end');

            // Physical PDF pages, inclusive, always exact and never null. This
            // is the half of a citation anyone can verify by opening the file.
            $table->unsignedInteger('page_from');
            $table->unsignedInteger('page_to');

            // The page numbers as printed in the book, which is what a reader
            // holding a paper copy needs. Only 61% of pages carry an observed
            // folio, so these may be interpolated from the book's numbering
            // series -- which is what the flag records.
            $table->string('printed_page_from')->nullable();
            $table->string('printed_page_to')->nullable();
            $table->boolean('printed_pages_estimated')->default(false);

            // The measurements the classifier acted on, not just its verdict,
            // so a judgement can be re-litigated with a query.
            $table->json('signals')->nullable();

            $table->unsignedSmallInteger('chunker_version')->default(1);
            $table->unsignedSmallInteger('classifier_version')->default(1);
            $table->timestamps();

            $table->unique(['book_id', 'chunk_index']);
            $table->index(['book_id', 'is_indexable']);
            $table->index(['book_id', 'page_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_chunks');
    }
};
