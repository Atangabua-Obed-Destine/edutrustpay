<?php

namespace App\Findings;

/**
 * A control (brief §11).
 *
 * "A control that only writes to a log is not a control" — so a rule's job is
 * not to detect and record, but to produce something a person can act on: a
 * plain-language explanation and the figures behind it.
 *
 * Two stages, because the controls genuinely divide in two:
 *
 *   STAGE_REPORT  fires when a report arrives, looking at what it says
 *   STAGE_SWEEP   fires on a schedule, looking at what has NOT arrived
 *
 * The second is not an afterthought. "Which institutions have not reported" is
 * the most valuable signal on the platform, and by definition no incoming report
 * can ever trigger it.
 */
interface Rule
{
    public const STAGE_REPORT = 'report';

    public const STAGE_SWEEP = 'sweep';

    /** Matches the key in config/findings.php. */
    public function code(): string;

    public function stage(): string;

    public function evaluate(RuleContext $context): ?FindingDraft;
}
