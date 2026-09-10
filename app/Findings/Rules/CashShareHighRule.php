<?php

namespace App\Findings\Rules;

use App\Findings\FindingDraft;
use App\Findings\Rule;
use App\Findings\RuleContext;

/**
 * Most of what this institution collects is physical cash.
 *
 * Fraud model C is cash received and never entered, and the books balance
 * perfectly afterwards because the money never touched them. Nothing inside a
 * ledger can detect that. What can be seen from outside is EXPOSURE: how much of
 * the institution's income passes through someone's hands before it reaches an
 * account.
 *
 * This is not an accusation and the finding says so. A rural mission may have no
 * bank within a day's travel and no realistic alternative to cash; the point is
 * that the risk is concentrated there, and that is where reconciliation against
 * a source the bursar does not author is worth the effort.
 *
 * THE THRESHOLD IS DELIBERATELY HIGH. Cash is normal in Cameroon. Firing at 40%
 * would flag the entire group and mean nothing — a control that fires on
 * everybody is a control nobody reads. What is worth a person's attention is a
 * near-total-cash institution, or one whose share is climbing.
 */
class CashShareHighRule implements Rule
{
    public function code(): string
    {
        return 'cash_share_high';
    }

    public function stage(): string
    {
        return self::STAGE_REPORT;
    }

    public function evaluate(RuleContext $context): ?FindingDraft
    {
        $report = $context->report;

        if (! $report || $report->cash_share === null) {
            return null;
        }

        $share = (float) $report->cash_share;
        $threshold = (float) $context->setting('threshold', '0.80');
        $riseLimit = (float) $context->setting('rise_over_months', '0.15');

        $earlier = $context->history
            ->filter(fn ($r) => $r->cash_share !== null)
            ->map(fn ($r) => (float) $r->cash_share);

        // The trend is the earlier signal, and catches an institution moving
        // toward cash before it gets there.
        $rise = $earlier->isNotEmpty() ? $share - $earlier->min() : 0.0;
        $rising = $rise >= $riseLimit;

        if ($share < $threshold && ! $rising) {
            return null;
        }

        return new FindingDraft(
            rule: $this->code(),
            severity: $share >= 0.95 ? 'high' : 'medium',
            title: __(':share% of collections are physical cash', ['share' => round($share * 100)]),
            explanation: __(
                ':name collected :share% of its fees for :period as physical cash:trend. This is not '
                .'evidence that anything is wrong — cash may be the only practical option here — but it '
                .'is where the risk sits: money that never reaches an account cannot be traced by any '
                .'figure in this report, and the books will balance whether or not it arrived. This is '
                .'the institution to reconcile against a bank or mobile-money statement first.',
                [
                    'name' => $context->institution->name,
                    'share' => round($share * 100),
                    'period' => $report->period,
                    'trend' => $rising
                        ? __(', up :rise points on earlier months', ['rise' => round($rise * 100)])
                        : '',
                ]
            ),
            figures: array_filter([
                'cash_share' => $report->cash_share,
                'threshold' => $threshold,
                'rise_from_earlier' => $rising ? round($rise, 4) : null,
                'collected' => $report->collected,
                'enrolment' => $report->enrolment,
            ], fn ($v) => $v !== null),
            period: $report->period,
        );
    }
}
