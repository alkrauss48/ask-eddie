<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The house catalog: the-krauss-haus's own drinks, as structure rather than prose.
     *
     * This is the half of the house corpus that retrieval cannot do. "I don't
     * like whiskey, what else have you got" is a negation, and negation is what
     * dense retrieval is worst at -- an embedding of "not whiskey" sits next to
     * the whiskey drinks. A vector answers it plausibly and wrongly; these
     * tables answer it exactly, because every cocktail carries its base spirit,
     * its flavour profile, its technique and its glass as rows a WHERE clause
     * can rule out.
     *
     * Deliberately no vector column, so Schema::ensureVectorExtensionExists() is
     * not called here -- the neighbouring chunks migration does call it, and its
     * absence should read as a decision rather than an omission.
     */
    public function up(): void
    {
        Schema::create('house_bartenders', function (Blueprint $table) {
            $table->id();

            // The site's own slug throughout this migration. Identity comes
            // from the export rather than from anything derived here, which is
            // what makes an import idempotent and a citation checkable: the
            // slug is the URL.
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // Nullable because most of these are living bartenders, and one of
            // them is the house.
            $table->smallInteger('birth_year')->nullable();
            $table->smallInteger('death_year')->nullable();

            // Stored rather than composed from a route pattern in PHP. The
            // site owns its own URL shapes; reconstructing them here would mean
            // a route change on that side becomes a broken citation on this
            // one, with nothing to report it.
            $table->string('url');

            $table->timestamps();
        });

        Schema::create('house_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            // "name", not "title". Every other record on the site uses title;
            // Recipe is the one exception, and it is kept rather than smoothed
            // over so that a reader comparing this table to the export does not
            // have to wonder which field they are looking at.
            $table->string('name');
            $table->text('description')->nullable();

            // Free text, one line per ingredient: "9oz 40% ABV Vodka". There is
            // deliberately no link from these to house_ingredients. Matching
            // that string to a catalog slug is fuzzy matching, and .ai/rules/
            // books.md already refuses that risk for drink names for the reason
            // that applies here too: a wrong match produces a real recipe with
            // a real URL and the wrong ingredient in it, and a guest cannot
            // tell.
            $table->json('ingredients');
            $table->text('instructions')->nullable();
            $table->text('notes')->nullable();
            $table->string('category')->nullable();
            $table->string('url');
            $table->timestamps();

            $table->index('category');
        });

        Schema::create('house_ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');

            // The bottle: "Smith and Cross", "London Dry Gin". Null for the
            // generic entries that name no particular product.
            $table->string('group')->nullable();

            // The catalog's three axes, named *_label because they are the
            // site's display strings rather than keys -- there is no slug for
            // any of them, so the label is the identity.
            $table->string('category_label')->nullable();
            $table->string('subcategory_label')->nullable();

            // Alcoholic / Non-Alcoholic.
            $table->string('ingredient_type')->nullable();

            // The 28 ingredients the house makes rather than buys point at the
            // recipe that makes them.
            $table->foreignId('house_recipe_id')->nullable()->constrained()->nullOnDelete();

            // An index page: /ingredients. The site has no [slug] route for an
            // ingredient, so every row here carries the same URL, which is also
            // why ingredients are catalog-only and never become chunks.
            $table->string('url');

            $table->timestamps();

            $table->index(['category_label', 'subcategory_label']);
            $table->index('ingredient_type');
        });

        Schema::create('house_tags', function (Blueprint $table) {
            $table->id();

            // A tag has no slug on the site: identity is the label plus the
            // category it sits in, which is what the unique index below says.
            // "Rum" under Base Alcohol and a hypothetical "Rum" under Flavor
            // Profile would be two different tags, and the pair is the only
            // thing that can say so.
            $table->string('label');
            $table->string('category_label');

            // The site's own display order within a category, kept so that a
            // facet list reads the way the menus read.
            $table->unsignedSmallInteger('order')->default(0);

            $table->timestamps();

            $table->unique(['category_label', 'label']);
            $table->index('category_label');
        });

        Schema::create('house_cocktails', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();

            // The facets that live on the cocktail itself rather than as tags.
            // They are duplicated into house_tags as well, because the site
            // tags on them -- but a structured filter should not have to reach
            // through a pivot to ask how a drink is built.
            $table->string('method')->nullable();
            $table->string('served_in')->nullable();
            $table->string('ice')->nullable();
            $table->boolean('has_straw')->default(false);

            // Set only on the batched and shared drinks.
            $table->unsignedSmallInteger('servings')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('house_bartender_id')->nullable()->constrained()->nullOnDelete();

            // Riffs on the drink, each a name and its own ingredient list.
            // Stored as json rather than as two more tables because nothing
            // filters on a variation: it is rendered into the chunk and read
            // back out whole.
            $table->json('variations')->nullable();

            // The exported record, verbatim, kept the way
            // book_page_extractions.raw_text is kept -- so a renderer change
            // can be replayed from the database without a re-export, and so
            // anything the columns above do not model (image URLs, a field
            // added on the site next month) is not lost on the way in.
            //
            // json, NOT jsonb: jsonb canonicalizes key order and rewrites
            // numbers, which would make content_hash below unstable across a
            // round trip that changed nothing.
            $table->string('url');

            $table->json('source');

            // sha256 over the canonical form of that record. The import
            // compares it to decide whether anything actually changed, which is
            // what makes a second run write nothing.
            $table->string('content_hash', 64);

            $table->timestamps();

            $table->index('method');
            $table->index('served_in');
        });

        Schema::create('house_cocktail_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_cocktail_id')->constrained()->cascadeOnDelete();

            // Nullable, and load-bearing. The site's ingredient list is a real
            // union -- some entries are catalog ingredients with an amount,
            // others are bare strings ("3.5oz Purified Water", "Garnish: 3
            // coffee beans"). Flattening a free-text line into a fake
            // ingredient row would invent a catalog member the site does not
            // have, and every structured query would then be able to find it.
            $table->foreignId('house_ingredient_id')->nullable()->constrained()->nullOnDelete();

            // The printed order of the line, which is the order a bartender
            // builds in.
            $table->unsignedSmallInteger('position');

            $table->string('amount')->nullable();

            // The site's own override for how this line reads: "Garnish: Lemon
            // twist" on an ingredient that is catalogued as "Lemon Garnish".
            $table->string('label')->nullable();

            // Set when, and only when, house_ingredient_id is null. A row with
            // neither is a line that names nothing, which --verify rejects.
            $table->string('free_text')->nullable();

            $table->timestamps();

            $table->unique(['house_cocktail_id', 'position']);
            $table->index('house_ingredient_id');
        });

        Schema::create('house_cocktail_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_cocktail_id')->constrained()->cascadeOnDelete();
            $table->foreignId('house_tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['house_cocktail_id', 'house_tag_id']);
            $table->index('house_tag_id');
        });

        Schema::create('house_collections', function (Blueprint $table) {
            $table->id();

            // menu | path. A menu and a flight are the same structure -- a
            // titled, ordered list of cocktails with a slug -- differing only in
            // a section title, a featured flag and a subtitle, all of which are
            // nullable columns below rather than a second pair of tables.
            // Keeping them apart would cost every downstream query a union or a
            // duplicated branch, and "which lists is this drink on" is a
            // question Sasha asks constantly.
            $table->string('kind');

            // Unique per kind rather than globally: nothing stops a menu and a
            // flight from sharing a name, and the site's URLs already separate
            // them.
            $table->string('slug');

            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('url');
            $table->timestamps();

            $table->unique(['kind', 'slug']);
        });

        Schema::create('house_collection_cocktails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('house_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('house_cocktail_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            // The menu's own grouping: "Mommy's Drinks", "Bold and Boozy". Null
            // on a flight, which has no sections.
            $table->string('section_title')->nullable();

            // A menu's featured drinks are a separate list from its sections,
            // and a drink can be on both.
            $table->boolean('is_featured')->default(false);

            $table->timestamps();

            // is_featured is part of the key deliberately: a summer-menu
            // cocktail can appear once in a section and once in featuredDrinks,
            // and both facts are true. Without it the second insert would
            // collide and one of the two would quietly go missing.
            $table->unique(['house_collection_id', 'house_cocktail_id', 'is_featured'], 'house_collection_cocktails_unique');
            $table->index('house_cocktail_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('house_collection_cocktails');
        Schema::dropIfExists('house_collections');
        Schema::dropIfExists('house_cocktail_tags');
        Schema::dropIfExists('house_cocktail_ingredients');
        Schema::dropIfExists('house_cocktails');
        Schema::dropIfExists('house_tags');
        Schema::dropIfExists('house_ingredients');
        Schema::dropIfExists('house_recipes');
        Schema::dropIfExists('house_bartenders');
    }
};
