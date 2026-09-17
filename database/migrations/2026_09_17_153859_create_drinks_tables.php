<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The drink layer: canonical drinks, and every printed occurrence of one.
     *
     * Deliberately no vector column, so Schema::ensureVectorExtensionExists()
     * is not called here -- the neighbouring embedding migration does call it,
     * and its absence should read as a decision rather than an omission. Drink
     * identity is a deterministic fold of a printed string; an embedding puts
     * "Blue Lady" nearer "Pink Lady" than its own OCR misreading, which is the
     * wrong answer arrived at confidently.
     */
    public function up(): void
    {
        Schema::create('drinks', function (Blueprint $table) {
            $table->id();

            // Stable identity for the config overrides in books.drinks, keyed
            // the way books.slug is: renaming the display name must not orphan
            // an alias someone recorded against it.
            $table->string('slug')->unique();

            // Always a string some book actually printed -- the most frequent
            // raw spelling, title-cased only when it was shouted. Never
            // synthesized, because this is the name Eddie says out loud.
            $table->string('canonical_name');

            // The fold. This unique index is what makes the extractor
            // idempotent: a drink is upserted against it rather than searched
            // for, so a second run cannot produce a second row.
            $table->string('canonical_key')->unique();

            // Every raw spelling and the number of times it was printed:
            // {"BLUE LADY": 14, "BLUE LADV": 1}. Shaped like
            // book_sections.head_variants and for the same reason -- it is the
            // receipt for a merge, so a wrong one is reversible by reading the
            // row rather than by re-reading a PDF.
            $table->json('aliases')->nullable();

            // Materialized so they can be checked, not for speed: --verify
            // recomputes every one of these from drink_mentions and asserts
            // equality. A count that exists only as a query can be wrong with
            // nothing to report it. They are always recomputed wholesale and
            // never incremented, because incrementing is how a tally drifts
            // across a partial re-run.
            $table->unsignedInteger('mention_count')->default(0);
            $table->unsignedInteger('book_count')->default(0);

            // The era span, and the two halves of every judgement this layer is
            // allowed to make: ubiquity is book_count, persistence is the
            // distance between these. Nullable because books.year is.
            $table->smallInteger('first_year')->nullable();
            $table->smallInteger('last_year')->nullable();
            $table->foreignId('first_book_id')->nullable()->constrained('books')->nullOnDelete();

            $table->unsignedSmallInteger('extractor_version')->default(1);
            $table->unsignedSmallInteger('normalizer_version')->default(1);
            $table->timestamps();

            // "What comes up time and time again" is this index.
            $table->index(['book_count', 'mention_count']);
            $table->index('mention_count');
            $table->index(['first_year', 'last_year']);
        });

        Schema::create('drink_mentions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('drink_id')->constrained()->cascadeOnDelete();

            // Denormalized off the chunk so that every aggregate this layer
            // serves is one table rather than a three-way join.
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            // Provenance, and the cascade is the point: re-chunking a book
            // invalidates its mentions, and they must go with it rather than
            // survive pointing at chunk ids that have been rebuilt.
            $table->foreignId('book_chunk_id')->constrained()->cascadeOnDelete();

            // Copied from the book. Every question this layer exists to answer
            // is grouped or filtered by period, and without it each one joins
            // 45 books to reach a smallint. Checked against books.year in
            // --verify, because a copy that can drift must be checkable.
            $table->smallInteger('book_year')->nullable();

            // Exactly as printed, and load-bearing: this is what makes a wrong
            // merge visible. A drink whose aliases read "BRANDY SOUR" and
            // "BRANDY SOUP" is one someone can see is wrong.
            $table->string('raw_heading');

            // What HeadingPatterns already knew and threw away. numbered /
            // caps_prefix / caps_line / title_line.
            $table->string('heading_family');

            // "128", and this corpus's "13u". Recorded for the record, never
            // used for ordering or de-duplication -- the same caveat
            // HeadingPatterns::matchNumbered() already documents.
            $table->string('heading_number')->nullable();

            // The kind of chunk the name was found in, so an aggregate can
            // weight or exclude by the quality of its evidence without a join.
            $table->string('chunk_kind');

            // Copied rather than joined, on the same argument the chunks
            // migration makes: the foreign key is the source of truth, and this
            // string is what reaches a citation, so a missing relation can
            // never render as a null section.
            $table->string('section_title')->nullable();

            $table->unsignedInteger('page_from');
            $table->unsignedInteger('page_to');
            $table->string('printed_page_from')->nullable();
            $table->string('printed_page_to')->nullable();
            $table->boolean('printed_pages_estimated')->default(false);

            // Byte offset of the heading line into the book's assembled stream:
            // chunk.char_start plus its offset within the chunk. This is what
            // makes a mention checkable the way a chunk is -- --verify asserts
            // the stored raw_heading is byte-identical to the text at this
            // offset, which proves the page range, which proves the citation.
            $table->unsignedInteger('char_start');

            $table->unsignedSmallInteger('extractor_version')->default(1);
            $table->timestamps();

            // One mention per printed occurrence, which is also what lets the
            // writer be re-run without duplicating a book's tally.
            $table->unique(['book_chunk_id', 'char_start']);
            $table->index(['drink_id', 'book_id']);
            $table->index('book_id');
            $table->index(['drink_id', 'book_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drink_mentions');
        Schema::dropIfExists('drinks');
    }
};
