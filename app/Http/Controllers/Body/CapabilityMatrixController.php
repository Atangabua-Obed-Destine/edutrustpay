<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\BudgetSheet;
use App\Models\DaybookFiling;
use App\Models\Institution;
use App\Services\ConsolidationService;

/**
 * What each school sends (brief §7.4): schools down, the parts of the monthly
 * report across, then the documents each school sends on its own.
 *
 * Two things at once: what the body can currently trust, and the gaps that
 * close as a school's system gains the part that produces them. It is the
 * page that explains every "doesn't send this" elsewhere in the console.
 */
class CapabilityMatrixController extends Controller
{
    public function __invoke(ConsolidationService $consolidation)
    {
        $institutions = Institution::query()->with('capabilities')->orderBy('name')->get();
        $ids = $institutions->pluck('id');

        // The daybook and budget sheet are separate signed documents, not parts
        // of the report, so whether they arrive is read from what is held.
        $latest = fn (string $model) => $model::query()
            ->whereIn('institution_id', $ids)
            ->where('is_current', true)
            ->selectRaw('institution_id, max(period) as period')
            ->groupBy('institution_id')
            ->pluck('period', 'institution_id');

        return view('body.capabilities', $consolidation->capabilityMatrix($institutions) + [
            'daybooks' => $latest(DaybookFiling::class),
            'budgetSheets' => $latest(BudgetSheet::class),
        ]);
    }
}
