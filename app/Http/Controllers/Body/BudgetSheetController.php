<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\BudgetSheet;
use App\Models\Institution;
use Illuminate\Http\Request;

/**
 * A school's budget sheet, read in the console the way its bursar reads it.
 */
class BudgetSheetController extends Controller
{
    public function show(Request $request, Institution $institution)
    {
        $sheets = BudgetSheet::query()
            ->where('institution_id', $institution->id)
            ->where('is_current', true)
            ->orderByDesc('period')
            ->get(['id', 'period']);

        $requested = (string) $request->query('period', '');
        $period = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) ? $requested : ($sheets->first()?->period ?? now()->subMonth()->format('Y-m'));

        $sheet = BudgetSheet::query()
            ->where('institution_id', $institution->id)
            ->where('period', $period)
            ->where('is_current', true)
            ->first();

        return view('body.budget.show', [
            'institution' => $institution,
            'periods' => $sheets->pluck('period'),
            'period' => $period,
            'sheet' => $sheet,
            'document' => $sheet?->document(),
        ]);
    }
}
