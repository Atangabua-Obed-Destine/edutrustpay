<?php

namespace App\Findings\Rules;

use App\Findings\FindingDraft;
use App\Findings\Rule;
use App\Findings\RuleContext;
use EdutrustPay\Contract\Money;

/**
 * Far less was collected than enrolment implies should have been.
 *
 * This is the arithmetic that turns "do we trust the report" into a question
 * with an answer, and it reaches the two fraud models nothing else does:
 *
 *   C — cash received and never entered at all. The books balance perfectly,
 *       because the money never touched them.
 *   D — students attending who were never enrolled, paying the bursar directly.
 *
 * A STRUCTURAL CAVEAT, and it is stated inside the finding rather than only
 * here: expected_fees is trustworthy only once the BODY pushes fee-structure
 * templates down and the institution reports enrolment against them. Until then
 * it is a figure the institution chose, and comparing it against what the same
 * institution says it collected proves very little. The finding says so, so that
 * nobody treats it as arithmetic before it is.
 */
class CollectionGapRule implements Rule
{
    public function code(): string
    {
        return 'collection_gap';
    }

    public function stage(): string
    {
        return self::STAGE_REPORT;
    }

    public function evaluate(RuleContext $context): ?FindingDraft
    {
        $report = $context->report;

        if (! $report || $report->collection_rate === null || $report->expected_fees === null) {
            return null;
        }

        $minimum = (float) $context->setting('min_collection_rate', '0.70');
        $rate = (float) $report->collection_rate;

        if ($rate >= $minimum) {
            return null;
        }

        $expected = Money::of($report->expected_fees);
        $collected = Money::of($report->collected ?? '0.00');
        $shortfall = $expected->subtract($collected);

        /*
         * A gap standing next to a high physical-cash share is the combination
         * that matters most. Money collected in a form nobody can trace and
         * money never collected at all look identical from up here — which is
         * exactly why the pair is worth a person's attention.
         */
        $cashShare = $report->cash_share !== null ? (float) $report->cash_share : null;

        return new FindingDraft(
            rule: $this->code(),
            // A prompt to ask, so it never claims to be the most serious kind.
            severity: $rate < $minimum / 2 ? 'high' : 'medium',
            title: __('Collection is :rate% of what enrolment implies', ['rate' => round($rate * 100)]),
            explanation: __(
                ':name reported :enrolment students for :period, implying :expected in fees, but collected '
                .':collected — a shortfall of :shortfall.:cash Expected fees are the school\'s own figure, '
                .'so this is a prompt to ask rather than evidence.',
                [
                    'name' => $context->institution->name,
                    'enrolment' => $report->enrolment ?? __('an unstated number of'),
                    'period' => $report->period,
                    'expected' => money_text($expected->value()),
                    'collected' => money_text($collected->value()),
                    'shortfall' => money_text($shortfall->value()),
                    'cash' => $cashShare !== null && $cashShare > 0.5
                        ? __(' :share% of what was collected was taken as physical cash.', ['share' => round($cashShare * 100)])
                        : '',
                ]
            ),
            figures: array_filter([
                'enrolment' => $report->enrolment,
                'expected_fees' => $expected->value(),
                'collected' => $collected->value(),
                'shortfall' => $shortfall->value(),
                'collection_rate' => $report->collection_rate,
                'minimum_rate' => $minimum,
                'cash_share' => $report->cash_share,
            ], fn ($v) => $v !== null),
            period: $report->period,
        );
    }
}
