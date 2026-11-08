<?php

namespace App\Services;

use App\Models\DaybookFiling;
use App\Models\DaybookLine;
use App\Models\Institution;
use EdutrustPay\Contract\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Compares schools using their daybooks. Totals only: no line and no name leaves
 * this class, so the comparison can be shown to people who cannot read the lines.
 *
 * Missing is not zero. A month a school has not sent is counted as not sent and
 * stated beside its totals, because six months against four is not a comparison.
 * Percentages are used where schools differ in size, and each is null when there
 * is nothing to take a share of, rather than a misleading 0%.
 */
class DaybookAnalysisService
{
    /**
     * @param  Collection<int, Institution>  $institutions
     * @return array{months: array<int, string>, rows: array<int, object>, totals: ?object, monthly: array<int, object>}
     */
    public function compare(Collection $institutions, string $from, string $to): array
    {
        $months = $this->months($from, $to);

        $filings = DaybookFiling::query()
            ->whereIn('institution_id', $institutions->pluck('id'))
            ->where('is_current', true)
            ->whereBetween('period', [$from, $to])
            ->get();

        $amounts = DaybookLine::query()
            ->whereIn('daybook_filing_id', $filings->pluck('id'))
            ->selectRaw('institution_id, kind, direction, method, heading, SUM(amount) as total')
            ->groupBy('institution_id', 'kind', 'direction', 'method', 'heading')
            ->toBase()
            ->get()
            ->groupBy('institution_id');

        $rows = $institutions->map(fn (Institution $institution) => $this->row(
            $institution,
            $filings->where('institution_id', $institution->id),
            $amounts->get($institution->id, collect()),
            count($months)
        ))->values()->all();

        return [
            'months' => $months,
            'rows' => $rows,
            'totals' => $this->totals($rows, count($months)),
            'monthly' => $this->monthly($months, $institutions, $filings),
        ];
    }

    private function row(Institution $institution, Collection $filings, Collection $amounts, int $monthCount): object
    {
        $sum = fn (Collection $set) => Money::sum($set->map(fn ($a) => Money::of(number_format((float) $a->total, 2, '.', ''))));
        $money = fn (string $direction, ?string $kind = null) => $sum($amounts
            ->where('direction', $direction)
            ->when($kind, fn ($set) => $set->where('kind', $kind)));

        $in = $money('in');
        $out = $money('out');

        // Some schools pay salaries as ordinary expenses rather than through payroll.
        // Their staff pay is then unknown here, not zero.
        $hasPayroll = $amounts->contains(fn ($a) => $a->kind === 'payroll');
        $cash = $sum($amounts->where('direction', 'in')->where('method', 'cash'));
        $known = $sum($amounts->where('direction', 'in')->whereNotNull('method'));

        return (object) [
            'institution' => $institution,
            'months_sent' => $filings->count(),
            'months_total' => $monthCount,
            'has_data' => $filings->isNotEmpty(),
            'money_in' => $in,
            'money_out' => $out,
            'difference' => $in->subtract($out),
            'fees' => $money('in', 'fee'),
            'other_income' => $money('in', 'income'),
            'expenses' => $money('out', 'expense'),
            'payroll' => $money('out', 'payroll'),
            'fee_share' => $this->share($money('in', 'fee'), $in),
            'has_payroll' => $hasPayroll,
            'payroll_share' => $hasPayroll ? $this->share($money('out', 'payroll'), $out) : null,
            // Only of what has a recorded method, or unrecorded money would read as not cash.
            'cash_share' => $this->share($cash, $known),
            'top_spending' => $this->topSpending($amounts, $out),
        ];
    }

    /**
     * What a school spends most on, under its own headings.
     *
     * @return array<int, object>
     */
    private function topSpending(Collection $amounts, Money $out): array
    {
        return $amounts->where('direction', 'out')
            ->groupBy('heading')
            ->map(fn (Collection $set, string $heading) => (object) [
                'heading' => $heading,
                'amount' => $total = (float) $set->sum('total'),
                'share' => (float) $out->value() > 0 ? round($total / (float) $out->value() * 100) : null,
            ])
            ->sortByDesc('amount')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * A total for all schools only when every school sent every month.
     *
     * @param  array<int, object>  $rows
     */
    private function totals(array $rows, int $monthCount): ?object
    {
        if (count($rows) < 2 || collect($rows)->contains(fn ($row) => $row->months_sent < $monthCount)) {
            return null;
        }

        $total = fn (string $key) => Money::sum(array_map(fn ($row) => $row->{$key}, $rows));

        return (object) [
            'payroll_known' => collect($rows)->every(fn ($row) => $row->has_payroll || $row->money_out->isZero()),
            'money_in' => $total('money_in'),
            'money_out' => $total('money_out'),
            'difference' => $total('difference'),
            'fees' => $total('fees'),
            'other_income' => $total('other_income'),
            'expenses' => $total('expenses'),
            'payroll' => $total('payroll'),
        ];
    }

    /**
     * Money in and out per school, month by month. A cell is null if nothing was sent.
     *
     * @param  array<int, string>  $months
     * @return array<int, object>
     */
    private function monthly(array $months, Collection $institutions, Collection $filings): array
    {
        return array_map(fn (string $month) => (object) [
            'period' => $month,
            'cells' => $institutions->mapWithKeys(function (Institution $institution) use ($month, $filings) {
                return [$institution->id => $filings
                    ->where('institution_id', $institution->id)
                    ->firstWhere('period', $month)];
            })->all(),
        ], array_reverse($months));
    }

    private function share(Money $part, Money $whole): ?int
    {
        return (float) $whole->value() > 0
            ? (int) round((float) $part->value() / (float) $whole->value() * 100)
            : null;
    }

    /**
     * @return array<int, string>
     */
    private function months(string $from, string $to): array
    {
        $months = [];
        $cursor = Carbon::createFromFormat('!Y-m', $from);

        while ($cursor->format('Y-m') <= $to) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $months;
    }
}
