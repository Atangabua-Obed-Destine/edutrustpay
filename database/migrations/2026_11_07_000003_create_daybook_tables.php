<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each school's daybook, line by line, so the body can analyse and compare them.
 *
 * These rows hold people's names. The body chose that, which is why reading them
 * has its own permission and every read is recorded in the audit log.
 *
 * A correction files a new version and the old one stays, so what a school filed
 * before can always be compared with what it says now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daybook_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('body_id')->constrained('bodies')->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->unsignedInteger('sequence');
            $table->boolean('is_current')->default(false);
            $table->string('currency', 3);
            $table->decimal('money_in', 18, 2);
            $table->decimal('money_out', 18, 2);
            $table->unsignedInteger('line_count');
            $table->string('payload_hash', 64);
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'period', 'sequence'], 'daybook_filing_unique');
            $table->index(['body_id', 'period', 'is_current'], 'daybook_filing_body_period');
        });

        Schema::create('daybook_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daybook_filing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('body_id')->constrained('bodies')->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->date('line_date');
            $table->string('ref');
            $table->text('description');
            $table->string('party')->nullable();
            $table->string('heading');
            $table->string('kind', 20);
            $table->string('direction', 3);
            $table->decimal('amount', 18, 2);
            $table->string('method', 20)->nullable();
            $table->string('source_id');

            $table->index(['daybook_filing_id', 'line_date'], 'daybook_line_filing_date');
            $table->index(['daybook_filing_id', 'kind'], 'daybook_line_filing_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daybook_lines');
        Schema::dropIfExists('daybook_filings');
    }
};
