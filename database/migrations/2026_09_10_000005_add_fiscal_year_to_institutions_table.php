<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An institution's own fiscal year.
 *
 * The body already had one, and the year-to-date statement was assembling every
 * institution's year on it. That is wrong wherever the two differ, and they
 * differ constantly: the Archdiocese of Bamenda keeps calendar books, while
 * PAX HIGHER INSTITUTE's budget 22 — the sheet its Bursar actually works from —
 * runs 2025-10-01 to 2026-08-31, an academic year.
 *
 * The consequence was not a wrong figure but something subtler and worse: a
 * year-to-date statement that reconciles to nothing. The Bursar's own sheet
 * opens in October, the console's opened in January, and both look
 * authoritative. Nobody would suspect the window.
 *
 * So both settings exist and they answer different questions:
 *
 *   institution  this institution's own statement, in its own year
 *   body         the CONSOLIDATED position, in the body's year, across
 *                institutions that individually run different years
 *
 * Nullable, inheriting the body's when unset — the same cascade already used by
 * not_reporting_days, for the same reason: most institutions will match their
 * body and should not have to restate it.
 *
 * Note this changes no reported figure. Institutions always report calendar
 * months; the fiscal year only decides which twelve the console adds up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->unsignedTinyInteger('fiscal_year_start_month')->nullable()->after('not_reporting_days');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('fiscal_year_start_month');
        });
    }
};
