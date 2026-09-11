<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            // Reading order within the book. Sections are derived from running
            // heads rather than authored, so this is the only stable identity
            // they have; the table is flat because nothing downstream needs a
            // hierarchy and one wrong parent would mis-attribute a subtree.
            $table->unsignedInteger('sequence');

            // Null is a valid answer. A detector that cannot name a section
            // says so, rather than synthesizing a title the book never used.
            $table->string('title')->nullable();
            $table->string('kind');

            // Physical PDF pages, inclusive, and a contiguous run allowing for
            // the blank leaves and plates inside it.
            //
            // This is where the section's running head was *observed*, which
            // under-reports the section slightly: a chapter's last page often
            // carries no head. A chunk is therefore attributed to the section
            // it begins in and may end a page or two past this range.
            $table->unsignedInteger('page_from');
            $table->unsignedInteger('page_to');

            // Every spelling of the running head that produced this section and
            // the pages each appeared on, as {"Old Waldotf Bar Days": [30, 96]}.
            // This is the receipt for the only text chunking removes: the page
            // rows keep the head, so a removal is always reversible by
            // inspection rather than by re-reading the PDF.
            $table->json('head_variants')->nullable();

            // Run density -- pages carrying the head divided by the span it
            // covers. A chapter detected from a head appearing on 25 of 57
            // pages is a weaker claim than one appearing on 25 of 26.
            $table->float('confidence')->nullable();

            $table->unsignedSmallInteger('detector_version')->default(1);
            $table->timestamps();

            $table->unique(['book_id', 'sequence']);
            $table->index(['book_id', 'page_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_sections');
    }
};
