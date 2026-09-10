<?php

namespace App\Findings\Rules;

use App\Findings\FindingDraft;
use App\Findings\Rule;
use App\Findings\RuleContext;

/**
 * Entries are accumulating outside the ledger.
 *
 * An unposted entry is money the institution knows about but which is not in the
 * figures it just reported. A few mid-month is normal; a number that climbs is a
 * growing gap between what the books say and what the bursar knows.
 *
 * This has particular force against paxhitestbed, where unpost() mutates account
 * balances backwards rather than posting a contra entry — so figures can be
 * removed from the ledger without leaving a reversing trail. The count is the
 * only external sign that happened.
 *
 * The rule reads the TREND as well as the level, because a large institution
 * legitimately carries more work in progress than a small one, and a threshold
 * on the absolute number alone would fire on the biggest school every month.
 */
class UnpostedBacklogRule implements Rule
{
    public function code(): string
    {
        return 'unposted_backlog';
    }

    public function stage(): string
    {
        return self::STAGE_REPORT;
    }

    public function evaluate(RuleContext $context): ?FindingDraft
    {
        $report = $context->report;

        if (! $report || $report->unposted_count === null) {
            return null;
        }

        $threshold = (int) $context->setting('threshold', 10);
        $current = (int) $report->unposted_count;

        $history = $context->history
            ->take(3)
            ->filter(fn ($r) => $r->unposted_count !== null)
            ->map(fn ($r) => (int) $r->unposted_count);

        $rising = $history->isNotEmpty() && $current > $history->max();

        if ($current < $threshold && ! ($rising && $current > 0)) {
            return null;
        }

        return new FindingDraft(
            rule: $this->code(),
            severity: $current >= $threshold * 3 ? 'high' : 'medium',
            title: __(':count entries are unposted', ['count' => $current]),
            explanation: __(
                ':name reported :count unposted journal entries for :period:trend. Unposted entries are '
                .'excluded from every figure in this report, so the institution\'s own books already know '
                .'about money that the consolidated position does not.',
                [
                    'name' => $context->institution->name,
                    'count' => $current,
                    'period' => $report->period,
                    'trend' => $rising
                        ? __(', up from :previous the month before', ['previous' => $history->first()])
                        : '',
                ]
            ),
            figures: [
                'unposted_count' => $current,
                'threshold' => $threshold,
                'recent_counts' => $history->values()->all(),
                'rising' => $rising,
            ],
            period: $report->period,
        );
    }
}
