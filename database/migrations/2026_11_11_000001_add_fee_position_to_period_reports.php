<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fee position for the academic year, since contract 1.4: the year's name
 * and its four amounts, as at the end of the month. Kept as columns, like every
 * other headline figure, so the overview can add them up across schools; the
 * class-by-class and fee-by-fee rows stay in the stored payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('period_reports', function (Blueprint $table) {
            $table->string('fee_session', 120)->nullable()->after('cash_share');
            $table->decimal('fees_expected', 18, 2)->nullable()->after('fee_session');
            $table->decimal('fees_collected', 18, 2)->nullable()->after('fees_expected');
            $table->decimal('fees_owed', 18, 2)->nullable()->after('fees_collected');
            $table->decimal('fees_overpaid', 18, 2)->nullable()->after('fees_owed');
        });
    }

    public function down(): void
    {
        Schema::table('period_reports', function (Blueprint $table) {
            $table->dropColumn(['fee_session', 'fees_expected', 'fees_collected', 'fees_owed', 'fees_overpaid']);
        });
    }
};
