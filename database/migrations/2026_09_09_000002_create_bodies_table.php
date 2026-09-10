<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bodies — the organisations that own institutions (brief §6).
 *
 * A body may contain bodies: the Archdiocese of Bamenda contains deaneries, and
 * a deanery sees its own institutions while the centre sees all. So this is a
 * self-referencing tree.
 *
 * NESTING IS IMPLEMENTED WITH A MATERIALISED PATH, not a recursive query.
 * `path` holds "/1/4/9/" for a body whose ancestry is 1 > 4 > 9, so
 * "everything under body 4" is a single indexed `path LIKE '/1/4/%'` — one
 * query, no recursion, at any depth. That matters because the consolidated
 * position is the console's hottest page and the tenancy global scope runs on
 * every query of every body-owned model.
 *
 * The trade-off is that a path must be rewritten when a body is re-parented,
 * which is rare and is handled in BodyProvisioningService rather than here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bodies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_body_id')->nullable()
                ->constrained('bodies')->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();

            // String rather than enum: enums break under later ALTERs, which is
            // edutrustshsm's own hard-won convention.
            $table->string('type')->default('diocese');

            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();

            /*
             * Materialised path, always leading and trailing slash: "/1/4/".
             *
             * Nullable only because it cannot be computed until the row has an
             * id; Body::rebuildPath fills it immediately after insert. Nullable
             * is also the SAFE failure direction, and that is worth being
             * deliberate about: a LIKE never matches NULL, so a body whose path
             * somehow failed to be written is invisible to every subtree query.
             * The alternative — a placeholder such as '/' — would prefix-match
             * EVERY path and leak that body into every body's consolidated view.
             * Invisible is a bug someone reports; universally visible is not.
             */
            $table->string('path', 255)->nullable()->index();
            $table->unsignedTinyInteger('depth')->default(0);

            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('country', 100)->default('Cameroon');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            /*
             * The body's preferred fiscal year start (1 = January).
             *
             * This is a VIEW PREFERENCE ONLY. Institutions always report calendar
             * months and the console assembles any fiscal year from them, because
             * paxhitestbed runs January-December while the schools run July-June
             * and a body holds both. Storing a preference here must never leak
             * into how periods are received.
             */
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);

            $table->char('currency', 3)->default('XAF');

            /*
             * Days of silence before `not_reporting` fires. Per body, because a
             * rural mission with a monthly hotspot trip and a city hospital on
             * fibre should not share a threshold. Nullable = use the platform
             * default from config/findings.php.
             */
            $table->unsignedSmallInteger('not_reporting_days')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bodies');
    }
};
