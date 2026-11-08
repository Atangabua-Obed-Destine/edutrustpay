<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school's own names for its account groups, as a report carried them
 * (contract 1.2): "71" => "Scolarites".
 *
 * The whole list is kept with each report, not only the groups used that month,
 * because a closed month is never sent again: the names from a later report are
 * what label it. Empty for reports sent before 1.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('period_reports', function (Blueprint $table) {
            $table->json('group_names')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('period_reports', function (Blueprint $table) {
            $table->dropColumn('group_names');
        });
    }
};
