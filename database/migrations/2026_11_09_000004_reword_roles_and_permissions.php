<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The standard roles and every permission now read in plain words ("Answer
 * what needs looking into", not "Resolve Finding"; "Administrator", not
 * "Body Administrator"). The seeder holds that wording and is safe to re-run,
 * so running it here brings an existing database into line. It changes names
 * and descriptions only; no role gains or loses a permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        // Wording only; there is nothing to undo.
    }
};
