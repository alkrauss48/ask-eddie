<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a drink row is a drink, which is not the same as whether it exists.
     *
     * 7,011 of the first real run's 9,437 rows were printed in exactly one book,
     * and a sample of that tail is mostly OCR wreckage and prose caught by a
     * heading pattern -- "Thiet Dtn", "Caucliois", "Israel Hatch announced daily
     * stages between". They are real occurrences of real strings at real offsets,
     * so nothing in --verify objects to them, and they sink the tally in exactly
     * one place: ordering by first_year returns "T He", "This", "There" and
     * "Page" from the 1757 book before it returns a drink.
     *
     * The column follows book_chunks.is_indexable rather than a delete, for the
     * reason .ai/rules/books.md gives there: garbage is classified, never
     * deleted. A mention is evidence that a book printed a string, and that
     * stays true whatever this column decides. Excluding it from a tally is then
     * a query, re-including it is an update, and the signals column is the
     * receipt for which it was and why.
     */
    public function up(): void
    {
        Schema::table('drinks', function (Blueprint $table) {
            // Defaults true so the column is inert until the classifier has run:
            // a fresh migrate followed by no books:drinks leaves the tally
            // behaving exactly as it did, rather than silently emptying it.
            $table->boolean('is_countable')->default(true)->after('canonical_key');

            // The measurements behind the verdict, shaped like
            // book_chunks.signals. This is what makes a wrong exclusion
            // diagnosable by reading the row instead of re-running the pipeline.
            $table->json('signals')->nullable()->after('aliases');

            $table->unsignedSmallInteger('classifier_version')->default(1)->after('normalizer_version');

            // "What comes up time and time again", now that the question is
            // asked of the countable rows only. The unqualified index on
            // [book_count, mention_count] stays: --top and --verify still walk
            // the whole table, and the distribution report counts both sides.
            $table->index(['is_countable', 'book_count', 'mention_count'], 'drinks_countable_rank_index');
        });
    }

    public function down(): void
    {
        Schema::table('drinks', function (Blueprint $table) {
            $table->dropIndex('drinks_countable_rank_index');
            $table->dropColumn(['is_countable', 'signals', 'classifier_version']);
        });
    }
};
