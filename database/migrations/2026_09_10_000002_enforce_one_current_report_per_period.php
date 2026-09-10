<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * At most one CURRENT report per institution per period — enforced by the
 * database, not by remembering.
 *
 * ReportIngestService::resolveSupersession() already gets this right: it loads
 * every sibling for the period and flips `is_current` so the highest sequence
 * wins. But it is the only thing that does, and a row written by any other
 * route — a seeder, a backfill, a console command, a hand-repair — leaves two
 * rows both claiming to be current with nothing to object.
 *
 * That was not hypothetical. This migration found one: institution 2, 2026-08,
 * sequences 1 and 3 both current, neither linked to the other.
 *
 * WHY IT MATTERS MORE HERE THAN IT WOULD ELSEWHERE. Every consumer resolves the
 * period with `->where('is_current', true)->get()->keyBy('period')`. With two
 * rows that keyBy silently keeps whichever the driver returned last — so the
 * consolidated position, the year-to-date statement and the findings engine can
 * each show a different month's figures for the same month, and none of them
 * would look wrong. A platform whose entire purpose is refusing to state a
 * confident wrong number cannot leave that to convention.
 *
 * HOW. A generated column that is the period when the row is current and NULL
 * when it is not, with a unique index over (institution_id, that). NULLs do not
 * collide in either MySQL or SQLite, so superseded rows are unconstrained and
 * retained exactly as before — supersession still never overwrites. Because the
 * column is DERIVED from is_current rather than maintained alongside it, there
 * is no writer that can forget to update it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->repairExistingDuplicates();

        Schema::table('period_reports', function (Blueprint $table) {
            $table->string('current_period', 7)
                ->nullable()
                ->virtualAs('CASE WHEN is_current = 1 THEN period END')
                ->after('is_current');

            $table->unique(['institution_id', 'current_period'], 'period_reports_one_current');
        });
    }

    public function down(): void
    {
        Schema::table('period_reports', function (Blueprint $table) {
            $table->dropUnique('period_reports_one_current');
            $table->dropColumn('current_period');
        });
    }

    /**
     * Apply the documented rule to whatever is already there: the highest
     * sequence stands.
     *
     * Deliberately the same rule as resolveSupersession(), because a repair
     * that used different reasoning from the live path would leave the two
     * disagreeing about history.
     */
    private function repairExistingDuplicates(): void
    {
        $duplicates = DB::table('period_reports')
            ->where('is_current', true)
            ->select('institution_id', 'period')
            ->groupBy('institution_id', 'period')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $winner = DB::table('period_reports')
                ->where('institution_id', $duplicate->institution_id)
                ->where('period', $duplicate->period)
                ->orderByDesc('sequence')
                ->value('id');

            DB::table('period_reports')
                ->where('institution_id', $duplicate->institution_id)
                ->where('period', $duplicate->period)
                ->where('id', '!=', $winner)
                // Only the current ones need standing down; the rest already are.
                ->where('is_current', true)
                ->update(['is_current' => false]);
        }
    }
};
