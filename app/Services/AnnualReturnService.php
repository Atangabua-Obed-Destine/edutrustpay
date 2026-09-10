<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\PeriodReport;
use App\Support\Figure;
use Carbon\Carbon;
use EdutrustPay\Contract\Money;

/**
 * The twelve-month statement: an institution's year, in the shape its own
 * bursar already reads.
 *
 * WHY THIS SHAPE. paxhitestbed's DaybookService::monthlySummary() produces five
 * columns — budget, this month, brought forward, cumulative, balance — and that
 * is the layout the Bursar reads and signs every month. Inventing a different
 * one here would mean the body and the institution discussing the same year in
 * two different vocabularies. So the console assembles the same five columns
 * out of the monthly reports it holds.
 *
 * WHAT THIS CAN DO THAT THE SOURCE SYSTEM CANNOT. An institution's own daybook
 * always has all of its own months, so it never has to ask whether the year is
 * complete. The console does: a month that was never reported leaves a hole in
 * the cumulative, and a cumulative that quietly skipped it would be a confident
 * wrong number — precisely the failure this platform exists to prevent. So
 * every cumulative here carries its own coverage, and the missing months are
 * named rather than counted.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not go below the OHADA group. The
 * console holds no transactions and no line detail; for that it links out to
 * the institution's own system (brief §13).
 */
class AnnualReturnService
{
    /**
     * The fiscal year containing $period, as a list of YYYY-MM.
     *
     * Fiscal years are not calendar years here and the difference is not
     * cosmetic: the Archdiocese of Bamenda keeps calendar books while PAX
     * HIGHER INSTITUTE's budget runs October to August. "The year to date"
     * therefore means two different windows in the same building.
     *
     * THE INSTITUTION'S OWN YEAR WINS. This statement is that institution's,
     * and it has to reconcile against the sheet its own Bursar works from. An
     * earlier version used the body's year for everyone, which produced a
     * statement agreeing with nothing either party publishes — harder to spot
     * than a wrong figure, because every number in it is correct.
     *
     * The body's setting still governs the CONSOLIDATED position, where the
     * question really is about the body's year.
     *
     * @return list<string>
     */
    public function fiscalMonths(Institution $institution, string $period): array
    {
        $start = $this->fiscalYearStart($institution, $period);
        $months = [];

        for ($i = 0; $i < 12; $i++) {
            $months[] = $start->copy()->addMonths($i)->format('Y-m');
        }

        return $months;
    }

    public function fiscalYearStart(Institution $institution, string $period): Carbon
    {
        $startMonth = $institution->fiscalYearStartMonth();
        $cursor = Carbon::createFromFormat('Y-m', $period)->startOfMonth();

        $start = $cursor->copy()->month($startMonth)->startOfMonth();

        // A period in January under a July year belongs to the year that opened
        // the previous July, not the one that opens six months from now.
        if ($start->greaterThan($cursor)) {
            $start->subYear();
        }

        return $start;
    }

    /**
     * The statement itself.
     *
     * @return array{
     *     period: string,
     *     months: list<string>,
     *     reported: list<string>,
     *     missing: list<string>,
     *     sections: array<string, array{rows: list<object>, totals: object}>,
     *     complete: bool
     * }
     */
    public function build(Institution $institution, string $period): array
    {
        $months = $this->fiscalMonths($institution, $period);

        // Only months up to and including the one being viewed can contribute:
        // a year-to-date figure must not include months that have not happened.
        $elapsed = array_values(array_filter($months, fn (string $m) => $m <= $period));

        $reports = $institution->periodReports()
            ->where('is_current', true)
            ->whereIn('period', $elapsed)
            ->with('lines')
            ->get()
            ->keyBy('period');

        $missing = array_values(array_filter($elapsed, fn (string $m) => ! $reports->has($m)));

        $sections = [];

        foreach (['income', 'expenditure'] as $section) {
            $sections[$section] = $this->section($section, $elapsed, $period, $reports, $missing);
        }

        return [
            'period' => $period,
            'fiscal_start' => $months[0],
            'months' => $months,
            'elapsed' => $elapsed,
            'reported' => array_values(array_diff($elapsed, $missing)),
            'missing' => $missing,
            'sections' => $sections,
            'complete' => $missing === [],
        ];
    }

    /**
     * One section of the statement, one row per OHADA group.
     *
     * @param  \Illuminate\Support\Collection<string, PeriodReport>  $reports
     * @param  list<string>  $elapsed
     * @param  list<string>  $missing
     * @return array{rows: list<object>, totals: object}
     */
    private function section(string $section, array $elapsed, string $period, $reports, array $missing): array
    {
        $priorMonths = array_values(array_filter($elapsed, fn (string $m) => $m < $period));

        /** @var array<string, array{month: ?Money, brought: Money}> $groups */
        $groups = [];

        foreach ($elapsed as $month) {
            $report = $reports->get($month);

            if (! $report) {
                continue;
            }

            foreach ($report->groupTotals($section) as $group => $money) {
                $groups[$group] ??= ['month' => null, 'brought' => Money::zero()];

                if ($month === $period) {
                    $groups[$group]['month'] = $money;
                } else {
                    $groups[$group]['brought'] = $groups[$group]['brought']->add($money);
                }
            }
        }

        ksort($groups);

        $rows = [];
        $monthTotal = Money::zero();
        $broughtTotal = Money::zero();

        foreach ($groups as $group => $figures) {
            $month = $figures['month'] ?? Money::zero();
            $brought = $figures['brought'];
            $cumulative = $brought->add($month);

            $monthTotal = $monthTotal->add($month);
            $broughtTotal = $broughtTotal->add($brought);

            $rows[] = (object) [
                'group' => (string) $group,
                'class' => mb_substr((string) $group, 0, 1),
                // A group present in an earlier month but absent this month is
                // a real zero for this month: the report arrived and did not
                // mention it. That is different from the month not arriving,
                // which is handled by the coverage below.
                'month' => $this->monthFigure($reports, $period, $figures['month']),
                'brought' => $brought,
                'cumulative' => $cumulative,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => (object) [
                'month' => $this->monthFigure($reports, $period, $monthTotal),
                'brought' => $broughtTotal,
                'cumulative' => $broughtTotal->add($monthTotal),
            ],
            // Coverage travels with the arithmetic rather than being a caption
            // somebody has to remember to add.
            'brought_months' => count($priorMonths),
            'brought_missing' => array_values(array_filter($missing, fn (string $m) => $m < $period)),
        ];
    }

    /**
     * This month's figure, or the fact that this month never arrived.
     */
    private function monthFigure($reports, string $period, ?Money $money): Figure
    {
        if (! $reports->has($period)) {
            return Figure::notReported();
        }

        return Figure::reported($money ?? Money::zero());
    }

    /**
     * A plain-language coverage statement for a year-to-date column.
     *
     * @param  list<string>  $missing
     */
    public function coverageStatement(int $elapsed, array $missing): string
    {
        if ($missing === []) {
            return trans_choice(
                'Year to date covers the one month elapsed.|Year to date covers all :count months elapsed.',
                $elapsed,
                ['count' => $elapsed]
            );
        }

        return __('Year to date covers :covered of :total months elapsed. Not reported: :missing.', [
            'covered' => $elapsed - count($missing),
            'total' => $elapsed,
            'missing' => implode(', ', $missing),
        ]);
    }
}
