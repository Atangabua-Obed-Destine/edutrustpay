<?php

namespace App\Findings;

/**
 * What a rule produces when it fires.
 *
 * A draft, not a Finding: the engine decides whether this is a new finding or
 * one that is still true, and only it writes to the database. Keeping rules free
 * of persistence is what makes them cheap to test — a rule is a pure function
 * from a report to "does this look wrong, and how would I explain it".
 */
class FindingDraft
{
    /**
     * @param  array<string, mixed>  $figures  the numbers behind it, frozen
     */
    public function __construct(
        public string $rule,
        public string $severity,
        public string $title,
        public string $explanation,
        public array $figures = [],
        public ?string $period = null,
    ) {
    }
}
