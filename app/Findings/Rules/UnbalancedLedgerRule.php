<?php

namespace App\Findings\Rules;

use App\Findings\FindingDraft;
use App\Findings\Rule;
use App\Findings\RuleContext;
use EdutrustPay\Contract\Money;

/**
 * Debits do not equal credits.
 *
 * The report was ACCEPTED at ingest on purpose — refusing it would have made
 * this institution look silent rather than alarming, and a bursary head responds
 * to those two very differently. The control fires here instead.
 *
 * In double-entry bookkeeping this cannot happen by accident in a sound system:
 * either the ledger is broken or something has been manipulated. Either way
 * every figure in this month's report is unsafe to consolidate, so the finding
 * says so rather than merely flagging the two totals.
 */
class UnbalancedLedgerRule implements Rule
{
    public function code(): string
    {
        return 'unbalanced_ledger';
    }

    public function stage(): string
    {
        return self::STAGE_REPORT;
    }

    public function evaluate(RuleContext $context): ?FindingDraft
    {
        $report = $context->report;

        if (! $report || $report->total_debits === null || $report->total_credits === null) {
            return null;
        }

        $debits = Money::of($report->total_debits);
        $credits = Money::of($report->total_credits);

        if ($debits->equals($credits)) {
            return null;
        }

        $difference = $debits->subtract($credits);

        return new FindingDraft(
            rule: $this->code(),
            severity: 'critical',
            title: __('The ledger does not balance'),
            explanation: __(
                'For :period, :name reported debits of :debits against credits of :credits — a difference '
                .'of :difference across :entries journal entries. In double-entry bookkeeping this cannot '
                .'happen by accident, so every figure in this month\'s report should be treated as unsafe '
                .'until the institution explains it.',
                [
                    'period' => period_text($report->period),
                    'name' => $context->institution->name,
                    'debits' => $debits->value(),
                    'credits' => $credits->value(),
                    'difference' => $difference->value(),
                    'entries' => $report->journal_entry_count ?? __('an unknown number of'),
                ]
            ),
            figures: [
                'total_debits' => $debits->value(),
                'total_credits' => $credits->value(),
                'difference' => $difference->value(),
                'journal_entry_count' => $report->journal_entry_count,
                'unposted_count' => $report->unposted_count,
            ],
            period: $report->period,
        );
    }
}
