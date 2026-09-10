<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Received period reports, their OHADA lines, and the sync event log.
 *
 * EVERY FIGURE COLUMN BELOW IS NULLABLE, AND THAT IS THE WHOLE POINT.
 *
 *   NULL  = the institution did not report this. It may be mid-build, or the
 *           capability may not apply to it at all.
 *   0.00  = the institution reported this and it is genuinely zero.
 *
 * These are different facts (brief §4.6) and the schema is where the difference
 * is made impossible to lose. A column defaulting to 0 would quietly convert
 * every gap in the group's reporting into a confident, wrong number — and a
 * consolidated total that is wrong in that direction is exactly the failure this
 * platform exists to prevent.
 *
 * The raw signed payload is retained alongside the exploded columns so that any
 * figure can always be traced back to the exact bytes that were signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();

            // Denormalised for the console's body-wide queries; the institution
            // is the authority on which body it belongs to.
            $table->foreignId('body_id')->constrained('bodies')->cascadeOnDelete();

            // Calendar month, "YYYY-MM". Never a fiscal year.
            $table->string('period', 7);

            // provisional | final
            $table->string('status');

            $table->unsignedInteger('sequence')->default(1);

            /*
             * Restatement chain (brief §4.7). A higher sequence supersedes, it
             * never overwrites: the prior row stays, is_current flips to false,
             * and both remain visible in the console.
             */
            $table->foreignId('supersedes_id')->nullable()->constrained('period_reports')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('period_reports')->nullOnDelete();
            $table->boolean('is_current')->default(true);

            $table->string('contract_version', 16);
            $table->char('currency', 3)->default('XAF');

            /** @see EdutrustPay\Contract\Capability — what this report declared. */
            $table->json('capabilities');

            // The exact bytes that were signed, plus the signature over them.
            $table->longText('payload');
            $table->string('payload_hash', 64)->index();
            $table->string('signature', 128);
            $table->string('key_id', 64)->nullable();

            /*
             * DATETIME, not TIMESTAMP. Two reasons, one practical and one that
             * matters more: MySQL/MariaDB refuses a second NOT NULL TIMESTAMP
             * column under strict mode, and TIMESTAMP silently converts on the
             * way in and out according to the server's timezone. A figure's
             * "the time it was true" must not depend on where the database is.
             */
            $table->dateTime('generated_at');
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();

            /*
             * Semantic warnings raised by the contract Validator at ingest —
             * unbalanced ledger, ageing that does not sum, and so on. These are
             * accepted and stored, then turned into Findings; they are never a
             * reason to reject a report.
             */
            $table->json('warnings')->nullable();

            // ---- financial.ledger -------------------------------------------
            $table->decimal('surplus_deficit', 18, 2)->nullable();
            $table->decimal('cash_opening', 18, 2)->nullable();
            $table->decimal('cash_movement', 18, 2)->nullable();
            $table->decimal('cash_closing', 18, 2)->nullable();
            $table->decimal('total_debits', 18, 2)->nullable();
            $table->decimal('total_credits', 18, 2)->nullable();
            $table->unsignedInteger('journal_entry_count')->nullable();
            $table->unsignedInteger('unposted_count')->nullable();

            // ---- financial.receivables --------------------------------------
            $table->decimal('receivables_total', 18, 2)->nullable();
            $table->decimal('receivables_0_30', 18, 2)->nullable();
            $table->decimal('receivables_31_60', 18, 2)->nullable();
            $table->decimal('receivables_61_90', 18, 2)->nullable();
            $table->decimal('receivables_90_plus', 18, 2)->nullable();

            // ---- financial.payables -----------------------------------------
            $table->decimal('payables_total', 18, 2)->nullable();
            $table->decimal('payables_0_30', 18, 2)->nullable();
            $table->decimal('payables_31_60', 18, 2)->nullable();
            $table->decimal('payables_61_90', 18, 2)->nullable();
            $table->decimal('payables_90_plus', 18, 2)->nullable();

            // ---- financial.budget -------------------------------------------
            $table->decimal('budgeted', 18, 2)->nullable();
            $table->decimal('budget_actual', 18, 2)->nullable();

            // ---- financial.payroll ------------------------------------------
            $table->decimal('staff_cost', 18, 2)->nullable();
            $table->decimal('statutory_liabilities', 18, 2)->nullable();

            // ---- financial.bank_recon ---------------------------------------
            $table->decimal('bank_statement_closing', 18, 2)->nullable();
            $table->decimal('bank_ledger_closing', 18, 2)->nullable();
            $table->decimal('bank_divergence', 18, 2)->nullable();
            $table->string('bank_statement_ref')->nullable();

            // ---- operational.enrolment --------------------------------------
            $table->unsignedInteger('enrolment')->nullable();
            $table->decimal('expected_fees', 18, 2)->nullable();
            $table->decimal('collected', 18, 2)->nullable();
            // Rates are carried as decimals, not floats, for the same reason
            // money is: reproducibility. 4dp matches the contract's rate pattern.
            $table->decimal('collection_rate', 5, 4)->nullable();
            $table->decimal('cash_share', 5, 4)->nullable();

            // ---- integrity ---------------------------------------------------
            $table->string('ledger_hash', 71)->nullable();
            $table->unsignedInteger('audit_events')->nullable();
            $table->unsignedInteger('reversals')->nullable();
            $table->unsignedInteger('backdated_entries')->nullable();

            $table->timestamps();

            /*
             * Idempotency (brief §7.3). A resend of the same (institution,
             * period, sequence) must never double-count — the database refuses
             * it outright rather than relying on the application remembering to
             * check.
             */
            $table->unique(['institution_id', 'period', 'sequence'], 'period_report_idempotency');

            $table->index(['body_id', 'period', 'is_current'], 'period_report_console_index');
            $table->index(['institution_id', 'is_current'], 'period_report_current_index');
        });

        Schema::create('report_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_report_id')->constrained()->cascadeOnDelete();

            // income | expenditure
            $table->string('section', 20);

            // Two-digit OHADA group. Consolidation happens here and nowhere else,
            // so that no institution has to adopt anyone else's chart of accounts.
            $table->char('ohada_group', 2);

            $table->decimal('amount', 18, 2);

            $table->unique(['period_report_id', 'section', 'ohada_group'], 'report_line_unique');
            $table->index(['section', 'ohada_group'], 'report_line_group_index');
        });

        /*
         * Every interaction with an institution, including the ones that carried
         * no figures.
         *
         * The heartbeat matters as much as the report (brief §8): without it you
         * cannot tell a quiet month from a stopped one, and "silence is an alarm"
         * only works if silence is distinguishable from "nothing to say".
         */
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();

            // received | rejected | restated | heartbeat | stale | recovered
            $table->string('type', 30);

            $table->string('period', 7)->nullable();
            $table->foreignId('period_report_id')->nullable()->constrained()->nullOnDelete();
            $table->string('summary')->nullable();
            $table->json('detail')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['institution_id', 'type', 'created_at'], 'sync_event_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
        Schema::dropIfExists('report_lines');
        Schema::dropIfExists('period_reports');
    }
};
