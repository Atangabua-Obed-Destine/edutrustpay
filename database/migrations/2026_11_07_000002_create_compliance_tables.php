<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rules a body sets for its schools, and what happens when one is broken.
 *
 * Nothing here is pushed into a school's system. The console holds the rule, the
 * school reports what it does, and the two are compared when somebody looks, so
 * correcting a rule or a mapping takes effect at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('body_id')->constrained('bodies')->cascadeOnDelete();
            $table->string('area', 30);
            // fee_schedule is compared with reports; attestation is confirmed by a person.
            $table->string('check_type', 30);
            $table->string('title');
            $table->text('description')->nullable();
            // Institution type this applies to. Null means every type.
            $table->string('applies_to', 30)->nullable();
            // maximum: charging less is fine. exact: charging less is a breach too.
            $table->string('fee_mode', 10)->default('maximum');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('regulation_fee_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->string('level');
            $table->string('category');
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->unique(['regulation_id', 'level', 'category'], 'regulation_fee_item_unique');
        });

        // Schools name their classes and fees their own way. This says which
        // line of the schedule a school's own fee counts as.
        Schema::create('regulation_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('school_level');
            $table->string('school_category');
            $table->foreignId('regulation_fee_item_id')->nullable()->constrained()->nullOnDelete();
            // Reviewed by a person and deliberately left outside this rule.
            $table->boolean('ignored')->default(false);
            $table->timestamps();
            $table->unique(['regulation_id', 'institution_id', 'school_level', 'school_category'], 'regulation_mapping_unique');
        });

        Schema::create('regulation_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->date('expires_on');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('regulation_attestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 10);
            $table->string('note');
            $table->date('valid_until');
            $table->foreignId('attested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('compliance_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->text('note');
            $table->text('outcome')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['institution_id', 'status'], 'compliance_case_open_index');
        });

        // A school being migrated is not judged until it is really on the system.
        Schema::table('institutions', function (Blueprint $table) {
            $table->date('live_from')->nullable()->after('fiscal_year_start_month');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn('live_from'));
        Schema::dropIfExists('compliance_cases');
        Schema::dropIfExists('regulation_attestations');
        Schema::dropIfExists('regulation_waivers');
        Schema::dropIfExists('regulation_mappings');
        Schema::dropIfExists('regulation_fee_items');
        Schema::dropIfExists('regulations');
    }
};
