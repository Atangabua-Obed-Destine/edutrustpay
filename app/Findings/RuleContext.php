<?php

namespace App\Findings;

use App\Models\Institution;
use App\Models\PeriodReport;
use Illuminate\Support\Collection;

/**
 * Everything a rule is allowed to look at.
 *
 * Passed in rather than fetched, so a rule cannot quietly issue its own queries
 * and so every rule can be tested by constructing one of these by hand.
 *
 * `history` matters more than it looks: several of the controls in brief §11 are
 * about CHANGE rather than level — arrears spiking, cash share climbing,
 * unposted entries accumulating. A rule that only ever sees one month cannot
 * express those, and a threshold on the absolute figure would fire on the
 * biggest institution every month regardless of whether anything was wrong.
 */
class RuleContext
{
    /**
     * @param  Collection<int, PeriodReport>  $history  earlier current reports, newest first
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public Institution $institution,
        public ?PeriodReport $report,
        public Collection $history,
        public array $config = [],
    ) {
    }

    /**
     * @return mixed
     */
    public function setting(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * The report for the month before this one, if we have it.
     */
    public function previous(): ?PeriodReport
    {
        return $this->history->first();
    }
}
