<?php

namespace App\Findings\Rules;

use App\Findings\FindingDraft;
use App\Findings\Rule;
use App\Findings\RuleContext;

/**
 * An institution has gone quiet.
 *
 * Brief §4.5: "silence is an alarm", and "which institutions have not reported,
 * and for how long is more valuable than the consolidated total". This is the
 * only control that fires on the ABSENCE of data, which is precisely why it
 * cannot live in the ingest path and needs a scheduled sweep.
 *
 * It is also the one control that automatic reporting does not itself fix. A
 * bursar who stops pushing has not been caught by anything clever; they have
 * been caught by someone noticing nothing arrived.
 */
class NotReportingRule implements Rule
{
    public function code(): string
    {
        return 'not_reporting';
    }

    public function stage(): string
    {
        return self::STAGE_SWEEP;
    }

    public function evaluate(RuleContext $context): ?FindingDraft
    {
        $institution = $context->institution;

        if (! $institution->is_active || ! $institution->isSilent()) {
            return null;
        }

        $days = $institution->daysSilent();
        $threshold = $institution->notReportingDays();

        /*
         * Never reported at all is a different finding from having stopped, and
         * it needs a different conversation: one is a failed onboarding — no
         * credentials deployed, no server, nobody trained — and the other is a
         * change in behaviour at a place that was working.
         */
        if ($days === null) {
            return new FindingDraft(
                rule: $this->code(),
                severity: 'high',
                title: __('Never reported'),
                explanation: __(
                    ':name was enrolled :enrolled but has never sent a report or a heartbeat. This usually '
                    .'means onboarding was not finished — no credentials deployed, or no server — rather '
                    .'than anything about the figures.',
                    ['name' => $institution->name, 'enrolled' => $institution->created_at?->diffForHumans()]
                ),
                figures: [
                    'enrolled_at' => $institution->created_at?->toDateString(),
                    'threshold_days' => $threshold,
                ],
            );
        }

        $heartbeatOnly = $institution->last_heartbeat_at !== null && $institution->last_report_at === null;

        return new FindingDraft(
            rule: $this->code(),
            // Severity climbs with the silence. A month late is a lapse; three
            // months is a governance question.
            severity: $days > $threshold * 3 ? 'critical' : 'high',
            title: $heartbeatOnly
                ? __('Alive but not reporting')
                : __('Not reporting for :days days', ['days' => $days]),
            explanation: $heartbeatOnly
                ? __(
                    ':name is online and sending heartbeats but has not sent any figures. The server is '
                    .'running and someone is choosing not to report, which is a different problem from a '
                    .'machine that has stopped.',
                    ['name' => $institution->name]
                )
                : __(
                    'Nothing has been received from :name for :days days; the threshold is :threshold. '
                    .'The last period received was :period.',
                    [
                        'name' => $institution->name,
                        'days' => $days,
                        'threshold' => $threshold,
                        'period' => $institution->last_period_received ?? __('none'),
                    ]
                ),
            figures: [
                'days_silent' => $days,
                'threshold_days' => $threshold,
                'last_period_received' => $institution->last_period_received,
                'last_report_at' => $institution->last_report_at?->toDateTimeString(),
                'last_heartbeat_at' => $institution->last_heartbeat_at?->toDateTimeString(),
            ],
        );
    }
}
