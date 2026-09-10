<?php

namespace App\Findings\Rules;

use App\Findings\FindingDraft;
use App\Findings\Rule;
use App\Findings\RuleContext;
use EdutrustPay\Contract\Money;

/**
 * A period that was already closed has been refiled.
 *
 * This is fraud model B seen from the outside: the bursar does not edit the
 * report, they edit the BOOKS, and a truthful report is then generated from
 * cooked books. Automatic reporting cannot catch that — the report is honest
 * about a ledger that is not. What catches it is noticing that a month which was
 * declared final subsequently changed.
 *
 * Critical severity, because unlike almost every other control here it cannot be
 * explained away by connectivity, a mid-build system, or a bursar being behind.
 * Someone reopened a closed month and the figures moved.
 *
 * Restating a PROVISIONAL month is not this. A month reported while still open
 * and refiled when it closes is ordinary bookkeeping, and treating it as an
 * incident would bury the real signal.
 */
class FinalRestatedRule implements Rule
{
    public function code(): string
    {
        return 'final_restated';
    }

    public function stage(): string
    {
        return self::STAGE_REPORT;
    }

    public function evaluate(RuleContext $context): ?FindingDraft
    {
        $report = $context->report;

        if (! $report || ! $report->supersedes_id) {
            return null;
        }

        $previous = $report->supersedes;

        // Only a period that had been declared FINAL counts.
        if (! $previous || $previous->status !== 'final') {
            return null;
        }

        $movement = $this->movement($previous, $report);

        return new FindingDraft(
            rule: $this->code(),
            severity: 'critical',
            title: __('A closed month was refiled'),
            explanation: __(
                ':name reported :period as final on :originally, then refiled it on :again at sequence '
                .':sequence. :movement Both versions are retained and can be compared. A month that '
                .'changes after it was closed is the shape a retro-edited ledger takes.',
                [
                    'name' => $context->institution->name,
                    'period' => $report->period,
                    'originally' => $previous->generated_at->toFormattedDateString(),
                    'again' => $report->generated_at->toFormattedDateString(),
                    'sequence' => $report->sequence,
                    'movement' => $movement['sentence'],
                ]
            ),
            figures: array_filter([
                'period' => $report->period,
                'previous_sequence' => $previous->sequence,
                'new_sequence' => $report->sequence,
                'previous_surplus' => $previous->surplus_deficit,
                'new_surplus' => $report->surplus_deficit,
                'surplus_movement' => $movement['delta'],
                'previous_ledger_hash' => $previous->ledger_hash,
                'new_ledger_hash' => $report->ledger_hash,
                'days_between' => $previous->generated_at->diffInDays($report->generated_at),
            ], fn ($v) => $v !== null),
            period: $report->period,
        );
    }

    /**
     * How far the surplus moved, in words a bursary head can act on.
     *
     * @return array{sentence: string, delta: string|null}
     */
    private function movement($previous, $report): array
    {
        if ($previous->surplus_deficit === null || $report->surplus_deficit === null) {
            return ['sentence' => '', 'delta' => null];
        }

        $before = Money::of($previous->surplus_deficit);
        $after = Money::of($report->surplus_deficit);
        $delta = $after->subtract($before);

        if ($delta->isZero()) {
            /*
             * The totals are identical but the period was refiled anyway. That
             * is arguably MORE interesting than a changed number: something was
             * reclassified without changing the bottom line, which is what
             * moving money between accounts looks like from up here.
             */
            return [
                'sentence' => __('The surplus is unchanged, so something was reclassified rather than re-valued.'),
                'delta' => $delta->value(),
            ];
        }

        return [
            'sentence' => __('The surplus moved from :before to :after, a change of :delta.', [
                'before' => $before->value(),
                'after' => $after->value(),
                'delta' => $delta->value(),
            ]),
            'delta' => $delta->value(),
        ];
    }
}
