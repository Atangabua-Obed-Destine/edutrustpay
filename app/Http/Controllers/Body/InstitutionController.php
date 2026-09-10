<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Services\AnnualReturnService;
use App\Services\ConsolidationService;
use App\Services\DrillThroughService;
use EdutrustPay\Contract\Capability;
use Illuminate\Http\Request;

/**
 * Drill-down: body -> institution -> month -> OHADA group.
 *
 * The chain stops at the OHADA group on purpose. EdutrustPay holds no rows about
 * identifiable people and no line detail — for anything below the group the
 * console links OUT to the institution's own system (brief §10, §13).
 *
 * Three depths, and each answers a different question:
 *
 *   show()   how is this institution doing, and is it still reporting?
 *   monthly() what exactly did it send for this month? Everything in the
 *            return, including the blocks it did NOT send.
 *   annual() how does the year look so far, in the five-column shape the
 *            institution's own bursar already reads?
 */
class InstitutionController extends Controller
{
    public function index(ConsolidationService $consolidation)
    {
        $institutions = Institution::query()->with('capabilities')->orderBy('name')->get();

        return view('body.institutions.index', [
            'institutions' => $institutions,
            'notReporting' => collect($consolidation->notReporting($institutions))->keyBy(fn ($r) => $r->institution->id),
        ]);
    }

    public function show(Request $request, Institution $institution, ConsolidationService $consolidation, DrillThroughService $drill)
    {
        $period = (string) $request->query('period', $institution->last_period_received ?? now()->subMonth()->format('Y-m'));

        $report = $institution->reportFor($period);

        // Who looked at what is part of the evidence in a system whose purpose
        // is oversight (brief §12).
        AuditLog::viewed(Institution::class, $institution->id, ['period' => $period], $institution->body_id);

        return view('body.institutions.show', [
            'institution' => $institution,
            'period' => $period,
            'report' => $report,
            'history' => $institution->periodReports()->where('is_current', true)
                ->orderByDesc('period')->limit(18)->get(),
            // Superseded versions are retained and stay visible — that is the
            // difference between restating and overwriting.
            'versions' => $institution->periodReports()->where('period', $period)
                ->orderByDesc('sequence')->get(),
            'income' => $report ? $report->groupTotals('income') : [],
            'expenditure' => $report ? $report->groupTotals('expenditure') : [],
            'trend' => $consolidation->trend($institution, 'surplus_deficit', Capability::LEDGER),
            'events' => $institution->syncEvents()->orderByDesc('id')->limit(12)->get(),
            'drillThrough' => $drill->for($institution, $period),
        ]);
    }

    /**
     * One month's return, in full.
     *
     * The overview shows four headline figures. This shows everything that
     * arrived — and, just as importantly, everything that did not: a block the
     * institution cannot yet report is rendered as such rather than omitted, so
     * the gaps are as visible as the figures.
     */
    public function monthly(Request $request, Institution $institution, string $period, DrillThroughService $drill)
    {
        $report = $institution->reportFor($period);

        abort_if($report === null && ! preg_match('/^\d{4}-\d{2}$/', $period), 404);

        AuditLog::viewed(Institution::class, $institution->id, ['period' => $period, 'view' => 'return'], $institution->body_id);

        return view('body.institutions.monthly', [
            'institution' => $institution,
            'period' => $period,
            'report' => $report,
            'history' => $institution->periodReports()->where('is_current', true)
                ->orderByDesc('period')->limit(18)->get(),
            'versions' => $institution->periodReports()->where('period', $period)
                ->orderByDesc('sequence')->get(),
            'income' => $report ? $report->groupTotals('income') : [],
            'expenditure' => $report ? $report->groupTotals('expenditure') : [],
            'findings' => $institution->findings()->where('period', $period)
                ->bySeverity()->get(),
            // Links OUT for the detail this console does not hold. Absent
            // entirely when the institution has no recorded address.
            'drillThrough' => $drill->for($institution, $period),
        ]);
    }

    /**
     * The year to date, in the daybook's own five-column shape.
     */
    public function annual(Request $request, Institution $institution, AnnualReturnService $annual)
    {
        $period = (string) $request->query('period', $institution->last_period_received ?? now()->subMonth()->format('Y-m'));

        AuditLog::viewed(Institution::class, $institution->id, ['period' => $period, 'view' => 'annual'], $institution->body_id);

        return view('body.institutions.annual', [
            'institution' => $institution,
            'period' => $period,
            'statement' => $annual->build($institution, $period),
            'annual' => $annual,
            'history' => $institution->periodReports()->where('is_current', true)
                ->orderByDesc('period')->limit(18)->get(),
        ]);
    }
}
