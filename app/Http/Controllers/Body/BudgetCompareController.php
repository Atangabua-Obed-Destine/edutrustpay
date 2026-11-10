<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\BudgetSheet;
use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Every school's budget for the year, compared on its totals.
 *
 * Each school keeps its own budget in its own system, line by line, and its
 * budget page here shows that sheet as the bursar reads it. This page sets the
 * schools side by side on the totals only: income, spending and capital, each
 * budgeted against what has come in or gone out so far, and how much of each
 * school's own budget year has passed. Lines are never matched across schools:
 * their budgets are their own.
 *
 * A school with no budget sheet for the month says so, and a total that leaves
 * it out says whose budgets it holds.
 */
class BudgetCompareController extends Controller
{
    public function index(Request $request)
    {
        $requested = (string) $request->query('period', '');
        $period = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) ? $requested : now()->subMonth()->format('Y-m');

        $institutions = Institution::query()->active()->orderBy('name')->get();

        $sheets = BudgetSheet::query()
            ->where('period', $period)
            ->where('is_current', true)
            ->whereIn('institution_id', $institutions->pluck('id'))
            ->get()
            ->keyBy('institution_id');

        $rows = $institutions->map(fn (Institution $institution) => $this->row($institution, $sheets->get($institution->id)));
        $sent = $rows->filter(fn ($row) => $row->sheet !== null);

        $sum = fn (string $part, string $column) => $sent->sum(fn ($row) => $row->{$part}[$column]);

        $totals = $sent->isEmpty() ? null : (object) [
            'covered' => $sent->count(),
            'of' => $rows->count(),
            'income' => ['budget' => $sum('income', 'budget'), 'to_date' => $sum('income', 'to_date')],
            'spending' => ['budget' => $sum('spending', 'budget'), 'to_date' => $sum('spending', 'to_date')],
            'capital' => ['budget' => $sum('capital', 'budget'), 'to_date' => $sum('capital', 'to_date')],
            'planned' => $sent->sum('planned'),
            'so_far' => $sent->sum('so_far'),
            'years_differ' => $sent->pluck('sheet.budget_title')->unique()->count() > 1,
        ];

        return view('body.budgets.compare', [
            'period' => $period,
            'periodOptions' => collect(range(0, 17))->map(fn (int $i) => Carbon::now()->startOfMonth()->subMonths($i)->format('Y-m'))->all(),
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }

    private function row(Institution $institution, ?BudgetSheet $sheet): object
    {
        if (! $sheet) {
            return (object) ['institution' => $institution, 'sheet' => null];
        }

        $capital = $sheet->document()['totals']['capital'] ?? ['budget' => 0, 'to_date' => 0];

        $part = fn (float $budget, float $toDate) => [
            'budget' => $budget,
            'to_date' => $toDate,
            'share' => $budget > 0 ? (int) round($toDate / $budget * 100) : null,
        ];

        $income = $part((float) $sheet->income_budget, (float) $sheet->income_to_date);
        $spending = $part((float) $sheet->expenditure_budget, (float) $sheet->expenditure_to_date);
        $capitalPart = $part((float) ($capital['budget'] ?? 0), (float) ($capital['to_date'] ?? 0));
        $yearGone = $sheet->yearGoneShare();

        return (object) [
            'institution' => $institution,
            'sheet' => $sheet,
            'year_gone' => $yearGone,
            'income' => $income,
            'spending' => $spending,
            'capital' => $capitalPart,
            // Spending well ahead of the year is the one thing worth flagging.
            'spending_ahead' => $spending['share'] !== null && $spending['share'] > $yearGone + 10,
            'income_behind' => $income['share'] !== null && $income['share'] + 10 < $yearGone,
            'planned' => $income['budget'] - $spending['budget'] - $capitalPart['budget'],
            'so_far' => $income['to_date'] - $spending['to_date'] - $capitalPart['to_date'],
        ];
    }
}
