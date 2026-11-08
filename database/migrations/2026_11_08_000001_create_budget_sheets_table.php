<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each school's budget sheet at the end of each month, as the school keeps it.
 *
 * The sheet is stored whole, because it is read whole, in the school's own order.
 * The year's totals are copied out so the body can compare schools without
 * opening every sheet. Corrections add a version; the old one stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('body_id')->constrained('bodies')->cascadeOnDelete();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->unsignedInteger('sequence');
            $table->boolean('is_current')->default(false);
            $table->string('currency', 3);
            $table->string('budget_title');
            $table->date('budget_starts');
            $table->date('budget_ends');
            $table->decimal('income_budget', 18, 2)->nullable();
            $table->decimal('income_to_date', 18, 2)->nullable();
            $table->decimal('expenditure_budget', 18, 2)->nullable();
            $table->decimal('expenditure_to_date', 18, 2)->nullable();
            $table->longText('payload');
            $table->string('payload_hash', 64);
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'period', 'sequence'], 'budget_sheet_unique');
            $table->index(['body_id', 'period', 'is_current'], 'budget_sheet_body_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_sheets');
    }
};
