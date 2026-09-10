<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail must outlive what it describes.
 *
 * `audit_logs.body_id` carried a foreign key, which made recording a body's
 * DELETION impossible: the row is gone by the time the `deleted` hook writes the
 * entry, so the insert violated the constraint and the deletion failed outright.
 * The one action most worth recording was the one action that could not be.
 *
 * Bodies are the only thing here that can be deleted at all, and only while
 * empty — reports, findings and credentials are all retained by design. So the
 * column stays, indexed, as a historical value, and stops being a live
 * reference. An audit row pointing at a body that no longer exists is not a
 * broken link; it is the whole point of the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite has no DROP FOREIGN KEY, and the test suite runs on it.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        /*
         * Drop it only if it is actually there.
         *
         * This constraint was removed by hand on the development database the
         * day the bug was found, before this migration existed — so re-running
         * it died on "Can't DROP FOREIGN KEY". A migration that fails against a
         * database already in its target state blocks every later migration
         * queued behind it, which is how one afternoon's hotfix stops a
         * deployment weeks later.
         */
        if (! $this->foreignKeyExists()) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['body_id']);
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite' || $this->foreignKeyExists()) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('body_id')->references('id')->on('bodies')->nullOnDelete();
        });
    }

    private function foreignKeyExists(): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'audit_logs')
            ->where('COLUMN_NAME', 'body_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
