<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When someone hid the "Start here" guide on the Overview. Null means they have
 * not, so a new person always meets it; it is kept per person, not per browser,
 * so it does not come back on every other computer they sign in from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('guide_hidden_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('guide_hidden_at');
        });
    }
};
