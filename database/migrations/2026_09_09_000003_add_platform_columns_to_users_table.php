<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform columns on users.
 *
 * Two kinds of person use this system:
 *   - the EdutrustPay operator (superadmin), who has NO body — body_id is null
 *     and `role` is 'super_admin';
 *   - a body's own people (bursary head, auditor, liaison), who belong to
 *     exactly one body and see that body and everything nested beneath it.
 *
 * `role` is the coarse legacy-style column edutrustshsm also carries, used by
 * the `role:` middleware for cheap defence in depth; fine-grained authorisation
 * goes through the permissions tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('body_id')->nullable()->after('id')
                ->constrained('bodies')->nullOnDelete();

            $table->string('role')->default('viewer')->after('email');

            $table->boolean('is_active')->default(true);

            // Invitation with a one-time link — never a generated password shown
            // on screen or emailed in plain text (brief §9).
            $table->string('invite_token', 64)->nullable()->unique();
            $table->timestamp('invite_sent_at')->nullable();
            $table->timestamp('invite_accepted_at')->nullable();

            $table->timestamp('last_login_at')->nullable();

            $table->index(['body_id', 'role'], 'users_body_role_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_body_role_index');
            $table->dropConstrainedForeignId('body_id');
            $table->dropColumn([
                'role', 'is_active', 'invite_token',
                'invite_sent_at', 'invite_accepted_at', 'last_login_at',
            ]);
        });
    }
};
