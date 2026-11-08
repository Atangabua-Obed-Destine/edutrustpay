<?php

namespace App\Http\Controllers\Body;

use App\Http\Controllers\Controller;
use App\Models\Finding;
use App\Services\ComplianceService;
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
 * A monthly report stops at the OHADA group. Below that the console has the
 * school's daybook lines (Body\DaybookController), and links out to the school's
 * own system for anything else.
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
        $requested = (string) $request->query('period', '');
        $period = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested)
            ? $requested
            : ($institution->last_period_received ?? now()->subMonth()->format('Y-m'));

        $report = $institution->reportFor($period);

        // Who looked at what is part of the evidence in a system whose purpose
        // is oversight (brief §12).
        AuditLog::viewed(Institution::class, $institution->id, ['period' => $period], $institution->body_id);

        $findingGroups = Finding::grouped(Finding::query()->open()->where('institution_id', $institution->id)->with('institution')->get());

        return view('body.institutions.show', [
            'institution' => $institution,
            'period' => $period,
            'report' => $report,
            'findingGroups' => $findingGroups,
            'headlines' => $this->headlines($request, $institution, $period, $report, $findingGroups),
            'history' => $institution->periodReports()->where('is_current', true)
                ->orderByDesc('period')->limit(18)->get(),
            // Superseded versions are retained and stay visible — that is the
            // difference between restating and overwriting.
            'versions' => $institution->periodReports()->where('period', $period)
                ->orderByDesc('sequence')->get(),
            'income' => $report ? $report->groupTotals('income') : [],
            'expenditure' => $report ? $report->groupTotals('expenditure') : [],
            'trend' => $consolidation->trend($institution, 'surplus_deficit', Capability::LEDGER),
            'events' => $institution->syncEvents()->orderByDesc('id')->limit(6)->get(),
            'drillThrough' => $drill->for($institution, $period),
        ]);
    }

    /**
     * What this school's page says first, in words: did the month arrive, is it
     * following the rules, and is there anything to ask about.
     *
     * @return array<int, object>
     */
    private function headlines(Request $request, Institution $institution, string $period, $report, $findingGroups): array
    {
        $line = fn (string $tone, string $text, ?string $href = null) => (object) compact('tone', 'text', 'href');
        $month = period_text($period);
        $lines = [];

        if ($report) {
            $lines[] = $line('good', $report->status === 'final'
                ? __('The report for :month arrived on :date. The month is closed.', ['month' => $month, 'date' => $report->received_at->toFormattedDateString()])
                : __('The report for :month arrived on :date. The month is still open, so its figures may still change.', ['month' => $month, 'date' => $report->received_at->toFormattedDateString()]));
        } elseif ($period >= now()->format('Y-m')) {
            $lines[] = $line('muted', __(':month has not finished, so no report is due yet.', ['month' => $month]));
        } else {
            $lines[] = $line($institution->isSilent() ? 'bad' : 'warn', __('No report has arrived for :month. That is missing information, not a month of zeroes.', ['month' => $month]));
        }

        if ($request->user()->can('compliance.view')) {
            $cells = collect(app(ComplianceService::class)->board(new \Illuminate\Database\Eloquent\Collection([$institution->loadMissing('body')]))['rows'][0]->cells ?? [])->filter();
            $states = $cells->countBy(fn ($cell) => $cell->state);
            $link = route('body.compliance.index');

            $lines[] = match (true) {
                $cells->isEmpty() => $line('muted', __('None of your rules applies to this school yet.'), $link),
                $states->get(ComplianceService::BREACH, 0) > 0 => $line('bad', trans_choice('{1}It is breaking 1 of your rules.|[2,*]It is breaking :count of your rules.', $states->get(ComplianceService::BREACH), ['count' => $states->get(ComplianceService::BREACH)]), $link),
                $states->get(ComplianceService::UNKNOWN, 0) > 0 => $line('warn', trans_choice('{1}1 of your rules cannot be checked for it yet.|[2,*]:count of your rules cannot be checked for it yet.', $states->get(ComplianceService::UNKNOWN), ['count' => $states->get(ComplianceService::UNKNOWN)]), $link),
                default => $line('good', __('It is following your rules, going by what its system records.'), $link),
            };
        }

        $urgent = $findingGroups->filter(fn ($group) => in_array($group->severity, ['critical', 'high'], true))->count();

        $lines[] = $findingGroups->isEmpty()
            ? $line('good', __('Nothing about it needs looking into.'))
            : $line($urgent > 0 ? 'warn' : 'muted', trans_choice('{1}1 thing to look into|[2,*]:count things to look into', $findingGroups->count(), ['count' => $findingGroups->count()])
                .($urgent > 0 ? ', '.__(':count needing action', ['count' => $urgent]) : '').'.');

        return $lines;
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
