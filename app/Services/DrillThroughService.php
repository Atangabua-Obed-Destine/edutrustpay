<?php

namespace App\Services;

use App\Models\Institution;
use Carbon\Carbon;

/**
 * Links out to an institution's own system, for the detail this console does
 * not hold.
 *
 * THE ARCHITECTURAL POINT. EdutrustPay receives a signed monthly summary and each
 * school's daybook lines. It holds nothing else: no invoices, no receipts, no
 * student records. So for detail beyond the daybook, the honest answer is not to
 * fetch it; it is to send them to the institution's own system, where the
 * institution's own login decides what they may see.
 *
 * THE BROWSER GOES, NOT THE SERVER. This produces a plain URL. The console
 * makes no request, holds no credential for the far end, and needs no inbound
 * access to the institution — which is the same promise the settings screen at
 * each school makes to its bursar: outbound only, nothing reaches in. A body
 * user who cannot reach that host simply cannot follow the link, and nothing
 * about the console breaks.
 *
 * WHAT IT WILL NOT DO. It never invents a URL. If an institution has no
 * recorded address the links are absent rather than broken, and a target the
 * institution's system cannot answer for is omitted rather than guessed at —
 * a dead link from an oversight platform teaches people the platform is
 * unreliable, which is expensive to undo.
 */
class DrillThroughService
{
    /**
     * What each source system can be asked for, and how to ask.
     *
     * Two shapes, and the difference is not cosmetic:
     *
     *   'month'  the screen accepts a calendar month directly
     *   'range'  the screen accepts start and end dates
     *
     * paxhitestbed's ledger reports take real dates and needed no changes. Its
     * daybook and budget sheet do NOT: the daybook takes database ids, and a
     * budget sheet's id IS its period. Both gained a thin month-addressable
     * redirect in that codebase rather than having their ids guessed at here —
     * an outside system reconstructing another system's primary keys is exactly
     * the coupling this platform exists to avoid.
     */
    private const TARGETS = [
        'paxhitestbed' => [
            'daybook' => [
                'label' => 'Daybook',
                'blurb' => 'Every cash movement in the month, itemised.',
                'path' => 'admin/daybook/month/{month}',
                'shape' => 'month',
            ],
            'budget_sheet' => [
                'label' => 'Budget sheet',
                'blurb' => 'Budgeted against actual for the year this month sits in.',
                'path' => 'admin/budget-sheet/month/{month}',
                'shape' => 'month',
            ],
            'trial_balance' => [
                'label' => 'Trial balance',
                'blurb' => 'Every account with movement in the month.',
                'path' => 'admin/general-ledger/trial-balance',
                'shape' => 'range',
            ],
            'income_statement' => [
                'label' => 'Income statement',
                'blurb' => 'The month in OHADA shape, from the source.',
                'path' => 'admin/general-ledger/income-statement',
                'shape' => 'range',
            ],
            'receivables_aging' => [
                'label' => 'Student fee ageing',
                'blurb' => 'Who owes what, by age, as at month end.',
                'path' => 'admin/accounting-reports/student-fee-aging',
                'shape' => 'as_of',
            ],
            'cash_flow' => [
                'label' => 'Cash flow',
                'blurb' => 'Movement behind the opening and closing cash figures.',
                'path' => 'admin/accounting-reports/cash-flow-statement',
                'shape' => 'range',
            ],
        ],

        'edutrustshsm' => [
            'fee_reports' => [
                'label' => 'Fee report',
                'blurb' => 'Expected against collected, by category and method.',
                'path' => 'admin/fee-reports',
                'shape' => 'none',
            ],
            'payments' => [
                'label' => 'Payments',
                'blurb' => 'Individual receipts, with the method each was paid by.',
                'path' => 'admin/payments',
                'shape' => 'none',
            ],
        ],

        // posinnova has no adapter yet, so it has nothing to link to. An empty
        // list is the correct answer, not an omission.
        'posinnova' => [],
    ];

    /**
     * Every link available for this institution and month.
     *
     * @return list<object{key: string, label: string, blurb: string, url: string}>
     */
    public function for(Institution $institution, string $period): array
    {
        if (! $institution->endpoint) {
            return [];
        }

        $base = rtrim($institution->endpoint, '/');
        $links = [];

        foreach (self::TARGETS[$institution->system] ?? [] as $key => $target) {
            $url = $this->url($base, $target, $period);

            if ($url === null) {
                continue;
            }

            $links[] = (object) [
                'key' => $key,
                'label' => __($target['label']),
                'blurb' => __($target['blurb']),
                'url' => $url,
            ];
        }

        return $links;
    }

    public function available(Institution $institution): bool
    {
        return $institution->endpoint && (self::TARGETS[$institution->system] ?? []) !== [];
    }

    /**
     * @param  array<string, string>  $target
     */
    private function url(string $base, array $target, string $period): ?string
    {
        $month = $this->month($period);

        if (! $month) {
            return null;
        }

        $path = str_replace('{month}', $period, $target['path']);

        $query = match ($target['shape']) {
            'range' => [
                'start_date' => $month->format('Y-m-d'),
                'end_date' => $month->copy()->endOfMonth()->format('Y-m-d'),
            ],
            // Ageing is a position at a moment, not a movement over a window.
            'as_of' => ['as_of_date' => $month->copy()->endOfMonth()->format('Y-m-d')],
            default => [],
        };

        return $base.'/'.$path.($query ? '?'.http_build_query($query) : '');
    }

    private function month(string $period): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
