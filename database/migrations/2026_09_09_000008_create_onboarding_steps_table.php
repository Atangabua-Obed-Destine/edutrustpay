<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-institution onboarding checklist (brief §9).
 *
 * Visible to BOTH the operator and the body, deliberately. Enrolling an
 * institution is not a database row, it is eight things that happen in the real
 * world over weeks — a merchant account in the institution's own name takes
 * longest and should start first, and the chart of accounts being mapped to
 * OHADA groups decides whether any of the rest is worth anything.
 *
 * A row per step rather than a JSON blob, because who completed a step and when
 * is exactly the sort of thing somebody will need to establish later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();

            // See App\Services\OnboardingChecklistService::STEPS
            $table->string('step', 60);

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'step'], 'onboarding_step_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_steps');
    }
};
