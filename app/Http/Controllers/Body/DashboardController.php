<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\Finding;
use App\Models\Institution;
use App\Services\ComplianceService;
use App\Services\ConsolidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The body console's landing page.
 *
 * ORDERED BY WHAT NEEDS ATTENTION, NOT BY WHAT IS EASY TO RENDER (brief §10):
 *
 *   1. who has not reported        — the most valuable widget on the platform
 *   2. findings                    — controls that fired, most severe first
 *   3. coverage statement          — "consolidated across 7 of 9"
 *   4. consolidated position       — income, expenditure, surplus, cash
 *   5. collection rates and arrears
 *   6. budget versus actual
 *
 * The consolidated total is deliberately NOT first. A bursary head who opens
 * this page and sees a healthy-looking number before they see that two
 * institutions have gone quiet has been misled by the layout.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ConsolidationService $consolidation)
    {
        $period = $this->resolvePeriod($request);

        // Body-scoped by the global scope; no manual filtering, and none
        // possible to forget.
        $institutions = Institution::query()->active()->with('capabilities')->orderBy('name')->get();

        $position = $consolidation->position($institutions, $period);

        return view('body.dashboard', [
            'period' => $period,
            'periodOptions' => $this->periodOptions(),
            'institutions' => $institutions,
            'notReporting' => $consolidation->notReporting($institutions),
            'compliance' => $request->user()->can('compliance.view') ? app(ComplianceService::class)->board($institutions) : null,
            'findingGroups' => Finding::grouped(Finding::query()->open()->with('institution')->get()),
            'openFindingCount' => Finding::query()->open()->count(),
            'position' => $position,
            'collections' => $consolidation->collections($institutions, $period),
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
