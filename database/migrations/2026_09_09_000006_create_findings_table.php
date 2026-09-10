<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Findings — a control that fired (brief §11).
 *
 * "A control that only writes to a log is not a control." A Finding is therefore
 * a first-class record with a lifecycle and an owner, not a log line: it carries
 * the figures behind it, an explanation in plain language, and who decided what
 * to do about it.
 *
 * The plain-language explanation is stored rather than generated at render time
 * because the figures that justified it can change underneath — a restated
 * period rewrites the numbers, and a finding that silently rewords itself
 * afterwards is worse than useless in an audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('body_id')->constrained('bodies')->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();

            // Null for findings that are not about one month — `not_reporting`
            // is about a span of silence, not a period.
            $table->string('period', 7)->nullable();

            $table->foreignId('period_report_id')->nullable()
                ->constrained('period_reports')->nullOnDelete();

            /** @see config/findings.php — not_reporting, final_restated, … */
            $table->string('rule', 60);

            // critical | high | medium | low
            $table->string('severity', 20)->default('medium');

            $table->string('title');
            $table->text('explanation');

            // The numbers that justified the finding, frozen at the moment it fired.
            $table->json('figures')->nullable();

            /*
             * open      — nobody has looked at it yet
             * explained — someone gave a reason and it stands as answered
             * accepted  — the body has seen it and chosen to live with it
             *
             * Deliberately no "dismissed": a finding is never made to disappear,
             * only answered. Nothing is deleted where money is concerned.
             */
            $table->string('status', 20)->default('open');

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            /*
             * A rule that keeps firing for the same institution and period is one
             * finding that is still true, not a new one every night. first/last
             * carry the span; the unique key below enforces it.
             */
            $table->dateTime('first_detected_at');
            $table->dateTime('last_seen_at');
            $table->unsignedInteger('occurrences')->default(1);

            $table->timestamps();

            /*
             * CAVEAT, because it will bite whoever forgets: MySQL treats NULLs
             * as distinct in a unique index, so this constraint does NOT stop
             * duplicate rows for a period-less rule such as `not_reporting`.
             * FindingEngine must match on `whereNull('period')` explicitly when
             * upserting those. The index is still worth having — it does enforce
             * the period-scoped rules, which are most of them.
             */
            $table->unique(['institution_id', 'period', 'rule'], 'finding_unique');
            $table->index(['body_id', 'status', 'severity'], 'finding_console_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
