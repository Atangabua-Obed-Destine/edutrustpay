<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Reports can now carry each institution's staff list: number, name,
 * department and post. Seeing it is its own permission, staff.view, given to
 * the bursary head, the Education Secretary and the auditor, the people who
 * check postings and the payroll. The seeder holds that and is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolesAndPermissionsSeeder)->run();
    }

    public function down(): void
    {
        // The permission stays; removing it would only hide the list.
    }
};
