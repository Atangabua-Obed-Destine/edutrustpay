<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The console's own audit trail (brief §12).
 *
 * Not only writes: who LOOKED at what, and who resolved a finding. In a system
 * whose purpose is oversight, the oversight itself has to be accountable — a
 * finding quietly marked "accepted" with nobody's name against it would be a
 * gap in exactly the place the platform is supposed to be strongest.
 *
 * `body_id` is nullable and deliberately so: entries written outside a request
 * context (console commands, queue workers, seeders) carry none, and an audit
 * trail that silently hides entries is worse than no audit trail at all. See
 * AuditLog::scopeVisible, which always keeps NULL-body system rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            /*
             * Deliberately NOT a foreign key.
             *
             * The trail has to outlive what it describes. With a constraint here,
             * recording a body's DELETION was impossible — the row is gone by the
             * time the entry is written — so the single most important thing to
             * record was the one thing that could not be. An audit row pointing
             * at a body that no longer exists is the record working, not a
             * dangling reference.
             */
            $table->unsignedBigInteger('body_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // created | updated | deleted, plus domain events that are not a
            // plain Eloquent write: viewed, exported, rotated, revoked, resolved.
            $table->string('action', 40);

            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['model_type', 'model_id'], 'audit_model_index');
            $table->index(['body_id', 'created_at'], 'audit_body_time_index');
            $table->index(['user_id', 'created_at'], 'audit_user_time_index');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
