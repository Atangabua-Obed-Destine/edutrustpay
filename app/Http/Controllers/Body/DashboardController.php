<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\Finding;
use App\Models\Institution;
use App\Services\ComplianceService;
use App\Services\ConsolidationService;
use App\Services\OverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The body console's landing page.
 *
 * ORDERED BY WHAT NEEDS ATTENTION, NOT BY WHAT IS EASY TO RENDER (brief §10):
 *
 *   1. in words: who reported, who is breaking a rule, what to look into
 *   2. one row per school, so a problem has a name beside it
 *   3. findings, most serious first
 *   4. the money and fees, school by school, totalled only when all sent them
 *   5. budget against actual, once anyone sends it
 *
 * No total comes first. Someone who sees a healthy-looking number before they
 * see that two schools have gone quiet has been misled by the layout.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ConsolidationService $consolidation, OverviewService $overview)
    {
        $period = $this->resolvePeriod($request);

        // Body-scoped by the global scope; no manual filtering, and none
        // possible to forget.
        $institutions = Institution::query()->active()->with('capabilities')->orderBy('name')->get();

        $position = $consolidation->position($institutions, $period);

        $notReporting = $consolidation->notReporting($institutions);
        $compliance = $request->user()->can('compliance.view') ? app(ComplianceService::class)->board($institutions) : null;
        $findingGroups = Finding::grouped(Finding::query()->open()->with('institution')->get());
        $collections = $consolidation->collections($institutions, $period);

        return view('body.dashboard', [
            'period' => $period,
            'periodOptions' => $this->periodOptions(),
            'institutions' => $institutions,
            'overview' => $overview->build($institutions, $period, $position, $collections, $compliance, $findingGroups, $notReporting),
            'notReporting' => $notReporting,
            'compliance' => $compliance,
            'findingGroups' => $findingGroups,
            // Issues, not months: one finding seen for twelve months is one thing to ask about.
            'openFindingCount' => $findingGroups->count(),
            'position' => $position,
            'collections' => $collections,
            'budget' => $consolidation->budget($institutions, $period),
        ]);
    }

    /**
     * Default to LAST month, not this one.
     *
     * A month still in progress is reported as provisional if at all, so opening
     * on the current month would show almost everything as "no report" and read
     * as a crisis every first of the month.
     */
    private function resolvePeriod(Request $request): string
    {
        $requested = (string) $request->query('period', '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested)
            ? $requested
            : Carbon::now()->subMonth()->format('Y-m');
    }

    /**
     * @return array<int, string>
     */
    private function periodOptions(): array
    {
        $cursor = Carbon::now()->startOfMonth();

        return collect(range(0, 17))
            ->map(fn (int $i) => $cursor->copy()->subMonths($i)->format('Y-m'))
            ->all();
    }
}
