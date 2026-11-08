<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBody;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One version of one school's budget sheet at the end of one month.
 */
class BudgetSheet extends Model
{
    use BelongsToBody;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'sequence' => 'integer',
            'budget_starts' => 'date',
            'budget_ends' => 'date',
            'income_budget' => 'decimal:2',
            'income_to_date' => 'decimal:2',
            'expenditure_budget' => 'decimal:2',
            'expenditure_to_date' => 'decimal:2',
            'generated_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return json_decode($this->payload, true) ?: [];
    }

    /** Share of the budget spent so far, or null when nothing was budgeted. */
    public function spentShare(): ?int
    {
        return (float) $this->expenditure_budget > 0
            ? (int) round((float) $this->expenditure_to_date / (float) $this->expenditure_budget * 100)
            : null;
    }

    /** Share of the budgeted income received so far, or null when none was budgeted. */
    public function receivedShare(): ?int
    {
        return (float) $this->income_budget > 0
            ? (int) round((float) $this->income_to_date / (float) $this->income_budget * 100)
            : null;
    }

    /**
     * How much of the budget year has passed by the end of this month, so spending
     * can be read against time: 60% spent is fine in month eight, not in month three.
     */
    public function yearGoneShare(): int
    {
        $start = $this->budget_starts->copy()->startOfDay();
        $end = $this->budget_ends->copy()->endOfDay();
        $now = Carbon::createFromFormat('!Y-m', $this->period)->endOfMonth()->min($end);

        $length = max(1, $start->diffInDays($end));

        return (int) max(0, min(100, round($start->diffInDays($now, false) / $length * 100)));
    }
}
