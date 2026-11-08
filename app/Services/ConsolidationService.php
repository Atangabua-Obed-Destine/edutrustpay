<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\PeriodReport;
use App\Support\Figure;
use App\Support\FigureAggregate;
use EdutrustPay\Contract\Capability;
use EdutrustPay\Contract\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Assembles the body's consolidated position out of what institutions have
 * actually sent.
 *
 * The organising idea is that this service never returns a bare number. Every
 * figure comes back as a Figure — which knows whether it is real, stale, absent
 * or inapplicable — and every total comes back as a FigureAggregate, which
 * carries its own coverage. A caller therefore cannot render a consolidated
 * total without also having in hand the fact that it covers 7 of 9 institutions.
 *
 * That is deliberate, and it is the difference between this and a reporting
 * tool: the console's job is not to display numbers, it is to make discrepancies
 * impossible to miss (brief §3).
 */
class ConsolidationService
{
    private int $staleDays;

    public function __construct()
    {
        $this->staleDays = (int) config('findings.stale_after_days', 45);
    }

    /**
     * WHO HAS NOT REPORTED — the most valuable widget on the platform (brief §10).
     *
     * Ordered by how long the silence has run, longest first, with
     * never-reported institutions at the top: an institution that was enrolled
     * and has never once pushed is a failed onboarding, not a lapse, and it
     * needs a different conversation.
     *
     * @param  Collection<int, Institution>  $institutions
     * @return array<int, object>
     */
    public function notReporting(Collection $institutions): array
    {
        return $institutions
            ->map(function (Institution $institution) {
                $days = $institution->daysSilent();
                $threshold = $institution->notReportingDays();

                return (object) [
                    'institution' => $institution,
                    'days_silent' => $days,
                    'never_reported' => $days === null,
                    'threshold' => $threshold,
                    'last_period' => $institution->last_period_received,
                    'overdue' => $institution->isSilent(),
                    // A heartbeat with no report is its own state: the server is
                    // alive and someone chose not to send figures. That is a
                    // different problem from a machine that has stopped.
                    'alive_but_silent' => $institution->last_heartbeat_at !== null
                        && $institution->last_report_at === null,
                ];
            })
            ->filter(fn ($row) => $row->overdue)
            ->sortByDesc(fn ($row) => $row->never_reported ? PHP_INT_MAX : $row->days_silent)
            ->values()
            ->all();
    }

    /**
     * The consolidated position for one calendar month.
     *
     * @param  Collection<int, Institution>  $institutions
     * @return array{
     *     period: string,
     *     income: FigureAggregate, expenditure: FigureAggregate,
     *     surplus: FigureAggregate, cash_closing: FigureAggregate,
     *     reports: Collection<int, PeriodReport>,
     *     rows: array<int, object>
     * }
     */
    public function position(Collection $institutions, string $period): array
    {
        $reports = $this->reportsFor($institutions, $period);

        $income = [];
        $expenditure = [];
        $surplus = [];
        $cash = [];
        $rows = [];

        foreach ($institutions as $institution) {
            $report = $reports->get($institution->id);
            $name = $institution->name;

            $groupIncome = $this->groupAggregate($institution, $report, 'income');
            $groupExpenditure = $this->groupAggregate($institution, $report, 'expenditure');

            $income[$name] = $groupIncome;
            $expenditure[$name] = $groupExpenditure;
            $surplus[$name] = $this->figure($institution, $report, 'surplus_deficit', Capability::LEDGER);
            $cash[$name] = $this->figure($institution, $report, 'cash_closing', Capability::LEDGER);

            $rows[] = (object) [
                'institution' => $institution,
                'report' => $report,
                'income' => $groupIncome,
                'expenditure' => $groupExpenditure,
                'surplus' => $surplus[$name],
                'cash_closing' => $cash[$name],
                'balanced' => $report ? $this->isBalanced($report) : null,
            ];
        }

        return [
            'period' => $period,
            'income' => Figure::sum($income),
            'expenditure' => Figure::sum($expenditure),
            'surplus' => Figure::sum($surplus),
            'cash_closing' => Figure::sum($cash),
            'reports' => $reports,
            'rows' => $rows,
        ];
    }

    /**
     * Income or expenditure by OHADA group across the body — the comparison that
     * lets a college be read against a clinic, because both arrive as classes.
     *
     * @param  Collection<int, Institution>  $institutions
     * @return array<string, FigureAggregate>  keyed by OHADA group
     */
    public function byOhadaGroup(Collection $institutions, string $period, string $section): array
    {
        $reports = $this->reportsFor($institutions, $period);
        $groups = [];

        foreach ($institutions as $institution) {
            $report = $reports->get($institution->id);
            $totals = $report ? $report->groupTotals($section) : [];

            foreach ($totals as $group => $money) {
                $groups[$group][$institution->name] = Figure::reported($money, $report->generated_at);
            }
        }

        ksort($groups);

        return array_map(fn (array $figures) => Figure::sum($figures), $groups);
    }

    /**
     * Collection performance and arrears ageing across the body.
     *
     * @param  Collection<int, Institution>  $institutions
     * @return array{
     *     expected: FigureAggregate, collected: FigureAggregate,
     *     receivables: FigureAggregate, ageing: array<string, FigureAggregate>,
     *     rows: array<int, object>
     * }
     */
    public function collections(Collection $institutions, string $period): array
    {
        $reports = $this->reportsFor($institutions, $period);

        $expected = [];
        $collected = [];
        $receivables = [];
        $ageing = ['0_30' => [], '31_60' => [], '61_90' => [], '90_plus' => []];
        $rows = [];

        foreach ($institutions as $institution) {
            $report = $reports->get($institution->id);
            $name = $institution->name;

            $expected[$name] = $this->figure($institution, $report, 'expected_fees', Capability::ENROLMENT);
            $collected[$name] = $this->figure($institution, $report, 'collected', Capability::ENROLMENT);
            $receivables[$name] = $this->figure($institution, $report, 'receivables_total', Capability::RECEIVABLES);

            foreach (array_keys($ageing) as $bucket) {
                $ageing[$bucket][$name] = $this->figure($institution, $report, 'receivables_'.$bucket, Capability::RECEIVABLES);
            }

            $rows[] = (object) [
                'institution' => $institution,
                'expected' => $expected[$name],
                'collected' => $collected[$name],
                'receivables' => $receivables[$name],
                'owed_over_90' => $ageing['90_plus'][$name],
                'collection_rate' => $report && $report->declares(Capability::ENROLMENT)
                    ? $report->collection_rate
                    : null,
                'cash_share' => $report && $report->declares(Capability::ENROLMENT)
                    ? $report->cash_share
                    : null,
            ];
        }

        return [
            'expected' => Figure::sum($expected),
            'collected' => Figure::sum($collected),
            'receivables' => Figure::sum($receivables),
            'ageing' => array_map(fn (array $f) => Figure::sum($f), $ageing),
            'rows' => $rows,
        ];
    }

    /**
     * Budget versus actual, per institution and for the body.
     *
     * @param  Collection<int, Institution>  $institutions
     * @return array{budgeted: FigureAggregate, actual: FigureAggregate, rows: array<int, object>}
     */
    public function budget(Collection $institutions, string $period): array
    {
        $reports = $this->reportsFor($institutions, $period);

        $budgeted = [];
        $actual = [];
        $rows = [];

        foreach ($institutions as $institution) {
            $report = $reports->get($institution->id);
            $name = $institution->name;

            $budgeted[$name] = $this->figure($institution, $report, 'budgeted', Capability::BUDGET);
            $actual[$name] = $this->figure($institution, $report, 'budget_actual', Capability::BUDGET);

            $variance = null;

            if ($budgeted[$name]->hasValue() && $actual[$name]->hasValue() && ! $budgeted[$name]->money()->isZero()) {
                $variance = round(
                    ((float) $actual[$name]->money()->value() - (float) $budgeted[$name]->money()->value())
                    / (float) $budgeted[$name]->money()->value() * 100,
                    1
                );
            }

            $rows[] = (object) [
                'institution' => $institution,
                'budgeted' => $budgeted[$name],
                'actual' => $actual[$name],
                'variance_pct' => $variance,
            ];
        }

        return ['budgeted' => Figure::sum($budgeted), 'actual' => Figure::sum($actual), 'rows' => $rows];
    }

    /**
     * The capability matrix — institutions down, capabilities across (brief §7.4).
     *
     * Four states, and the distinction between the last two is the point of the
     * whole thing: a gap that will close over time reads differently from one
     * that never will.
     *
     *   reporting      declared and seen arriving
     *   declared       declared but never actually seen — a quieter failure
     *   not_reporting  not declared: cannot report it yet
     *   not_applicable could never apply here (enrolment at a pharmacy)
     *
     * @param  Collection<int, Institution>  $institutions
     * @return array{capabilities: array<int, string>, rows: array<int, object>}
     */
    public function capabilityMatrix(Collection $institutions): array
    {
        $rows = [];

        foreach ($institutions as $institution) {
            $applicable = $institution->applicableCapabilities();
            $declared = $institution->capabilities->keyBy('capability');
            $cells = [];

            foreach (Capability::all() as $capability) {
                if (! in_array($capability, $applicable, true)) {
                    $cells[$capability] = 'not_applicable';

                    continue;
                }

                $row = $declared->get($capability);

                $cells[$capability] = match (true) {
                    $row === null => 'not_reporting',
                    $row->first_seen_at !== null => 'reporting',
                    default => 'declared',
                };
            }

            $rows[] = (object) [
                'institution' => $institution,
                'cells' => $cells,
                'coverage' => count(array_filter($cells, fn ($s) => $s === 'reporting')),
                'applicable' => count($applicable),
            ];
        }

        return ['capabilities' => Capability::all(), 'rows' => $rows];
    }

    /**
     * An institution's figure across months — a step change is a signal.
     *
     * @return array<int, object>
     */
    public function trend(Institution $institution, string $column, string $capability, int $months = 12): array
    {
        $cursor = Carbon::now()->startOfMonth()->subMonths($months);
        $periods = [];

        for ($i = 0; $i < $months; $i++) {
            $periods[] = $cursor->addMonth()->format('Y-m');
        }

        $reports = $institution->periodReports()
            ->where('is_current', true)
            ->whereIn('period', $periods)
            ->get()
            ->keyBy('period');

        return collect($periods)->map(fn (string $period) => (object) [
            'period' => $period,
            'figure' => $this->figure($institution, $reports->get($period), $column, $capability),
        ])->all();
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * The single place a stored column becomes a Figure for the console.
     *
     * The order of these checks is the whole rule:
     *   not applicable  -> could never report it
     *   no report       -> could report it, but this month has not arrived
     *   not capable     -> the report came, but without this capability
     *   stale / reported
     */
    private function figure(Institution $institution, ?PeriodReport $report, string $column, string $capability): Figure
    {
        if (! in_array($capability, $institution->applicableCapabilities(), true)) {
            return Figure::notApplicable();
        }

        if (! $report) {
            return Figure::notReported(__(':name has not reported this period.', ['name' => $institution->name]));
        }

        return $report->figure($column, $capability, Carbon::now()->subDays($this->staleDays));
    }

    /**
     * Income or expenditure total for one institution, summed from its OHADA
     * lines rather than a stored column — the lines are what was actually sent.
     */
    private function groupAggregate(Institution $institution, ?PeriodReport $report, string $section): Figure
    {
        if (! $report) {
            return Figure::notReported(__(':name has not reported this period.', ['name' => $institution->name]));
        }

        if (! $report->declares(Capability::LEDGER)) {
            return Figure::notCapable(__(':name does not report a ledger yet.', ['name' => $institution->name]));
        }

        $total = Money::sum(array_values($report->groupTotals($section)));

        return $report->generated_at->lessThan(Carbon::now()->subDays($this->staleDays))
            ? Figure::stale($total, $report->generated_at)
            : Figure::reported($total, $report->generated_at);
    }

    private function isBalanced(PeriodReport $report): ?bool
    {
        if ($report->total_debits === null || $report->total_credits === null) {
            return null;
        }

        return Money::of($report->total_debits)->equals(Money::of($report->total_credits));
    }

    /**
     * @param  Collection<int, Institution>  $institutions
     * @return Collection<int, PeriodReport>  keyed by institution id
     */
    private function reportsFor(Collection $institutions, string $period): Collection
    {
        return PeriodReport::query()
            ->with('lines')
            ->whereIn('institution_id', $institutions->pluck('id'))
            ->where('period', $period)
            ->where('is_current', true)
            ->get()
            ->keyBy('institution_id');
    }
}
