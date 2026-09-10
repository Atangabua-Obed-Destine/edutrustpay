<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A body's type is a label, not a classification.
 *
 * It was a required choice from seven ecclesial terms — archdiocese, diocese,
 * deanery, parish, school group, ministry, other — which cannot describe half
 * the organisations this platform will actually hold. A Regional Delegation for
 * the Northwest is a real body with real institutions under it and is none of
 * those; so is a congregation, a trust, a foundation, a school board. Every one
 * of them would have been filed as "other", which the sidebar then renders as
 * the literal word "other" — less informative than showing nothing.
 *
 * Nothing in the platform branches on this value. It is rendered in three
 * places as a caption and read nowhere else: no scope, no permission, no
 * finding rule, no report logic. (Contrast `institutions.type`, which IS
 * load-bearing — it decides that enrolment can never apply to a pharmacy.)
 * A constrained vocabulary that buys no behaviour and cannot describe the
 * organisation in front of you is only a way to record something untrue.
 *
 * So: free text, optional, and no default. 'diocese' as a default was a guess
 * the platform had no business making — a body created without a type should
 * carry no type, not silently become a diocese.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bodies', function (Blueprint $table) {
            $table->string('type', 60)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bodies', function (Blueprint $table) {
            $table->string('type')->default('diocese')->change();
        });
    }
};
