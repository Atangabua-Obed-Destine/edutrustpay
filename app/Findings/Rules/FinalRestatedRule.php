<?php

namespace App\Findings\Rules;

use App\Findings\FindingDraft;
use App\Findings\Rule;
use App\Findings\RuleContext;
use Carbon\Carbon;
use EdutrustPay\Contract\Fingerprint;
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

        if (! $previous) {
            return null;
        }

        // Refiled with only snapshots or the contract version different: the
        // month itself did not change, so there is nothing to explain.
        if ($previous->status === 'final' && $previous->payload !== '{}'
            && Fingerprint::ofJson($previous->payload) === Fingerprint::ofJson($report->payload)) {
            return null;
        }

        $movement = $this->movement($previous, $report);

        if ($previous->status !== 'final') {
            return $this->lateChange($context, $previous, $movement);
        }

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
                    'period' => period_text($report->period),
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
     * A month still marked provisional whose LEDGER changed well after it ended.
     *
     * Status alone cannot carry this control. A school that never closes its
     * accounting periods (PAX HIGHER INSTITUTE has closed 0 of 27) reports every
     * month as provisional forever, so the final-month check above never fires
     * and old months can be edited unnoticed. Measured on the ledger hash, so a
     * receivable changing because a student paid does not count.
     *
     * @param  array{sentence: string, delta: ?string}  $movement
     */
    private function lateChange(RuleContext $context, $previous, array $movement): ?FindingDraft
    {
        $report = $context->report;
        $days = (int) config('findings.rules.final_restated.late_after_days', 30);
        $monthEnd = Carbon::createFromFormat('!Y-m', $report->period)->endOfMonth();

        /*
         * The earlier version was filed before the month had finished, so of
         * course the finished month differs from it: the rest of the month had not
         * happened yet. Saying the books were changed afterwards would be false.
         */
        if ($previous->generated_at->lessThanOrEqualTo($monthEnd)) {
            return null;
        }

        $ledgerMoved = $previous->ledger_hash && $report->ledger_hash
            && $previous->ledger_hash !== $report->ledger_hash;

        if (! $ledgerMoved || $report->generated_at->lessThan($monthEnd->copy()->addDays($days))) {
            return null;
        }

        /*
         * Same totals with a different hash means entries were re-created or
         * renumbered without the month's figures moving. Worth asking about, but
         * not the same thing as money changing in an old month.
         */
        $figuresMoved = (string) $previous->surplus_deficit !== (string) $report->surplus_deficit
            || (string) $previous->total_debits !== (string) $report->total_debits
            || $previous->journal_entry_count !== $report->journal_entry_count;

        return new FindingDraft(
            rule: $this->code(),
            severity: $figuresMoved ? 'high' : 'medium',
            title: $figuresMoved
                ? __('An old month was changed')
                : __('An old month\'s entries were re-created'),
            explanation: __(
                $figuresMoved
                    ? ':name changed its books for :period :days days after that month ended. :movement '
                        .'Both versions are retained. Late entries can be honest catch-up work, so ask what '
                        .'was added and why; this is also the shape a retro-edited ledger takes.'
                    : ':name re-created or renumbered its entries for :period :days days after that month '
                        .'ended. The month\'s totals did not change. Both versions are retained. This usually '
                        .'means the ledger was rebuilt, so ask who did it and why.',
                [
                    'name' => $context->institution->name,
                    'period' => period_text($report->period),
                    'days' => (int) $monthEnd->diffInDays($report->generated_at),
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
                'before' => money_text($before->value()),
                'after' => money_text($after->value()),
                'delta' => money_text($delta->value()),
            ]),
            'delta' => $delta->value(),
        ];
    }
}
