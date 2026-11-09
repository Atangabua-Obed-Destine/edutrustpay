<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Primary or secondary school" becomes two types: nursery or primary, and
 * secondary or high school. Hospitals, health centres and pharmacies get their
 * own type, apart from shops and other businesses.
 *
 * The old combined type cannot say which level a school is, so it moves to
 * secondary, which is what every school enrolled under it so far has been (a
 * "college" here is a secondary school). An administrator corrects any that
 * are primary on the institution's edit page. A rule that applied to the
 * combined type moves with it, for the same reason.
 *
 * Existing businesses stay businesses: whether one is a pharmacy is for
 * someone who knows it to say.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('institutions')->where('type', 'school_basic')->update(['type' => 'school_secondary']);
        DB::table('regulations')->where('applies_to', 'school_basic')->update(['applies_to' => 'school_secondary']);
    }

    public function down(): void
    {
        DB::table('institutions')->whereIn('type', ['school_primary', 'school_secondary'])->update(['type' => 'school_basic']);
        DB::table('institutions')->where('type', 'health')->update(['type' => 'business']);
        DB::table('regulations')->whereIn('applies_to', ['school_primary', 'school_secondary'])->update(['applies_to' => 'school_basic']);
        DB::table('regulations')->where('applies_to', 'health')->update(['applies_to' => 'business']);
    }
};
