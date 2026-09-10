<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A body's own colour, beside the crest column that already exists.
 *
 * `logo_path` has been on this table since the first migration and is read by
 * nothing. An archdiocese is not a SaaS tenant: its people know its crest, and a
 * console that carries it reads as their record rather than as somebody's
 * software. The accent completes that without needing a rebuild per body —
 * it arrives as a CSS custom property on the html element.
 *
 * Nullable, and every surface falls back to the platform mark: most bodies will
 * never set either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bodies', function (Blueprint $table) {
            $table->string('accent_colour', 7)->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('bodies', function (Blueprint $table) {
            $table->dropColumn('accent_colour');
        });
    }
};
